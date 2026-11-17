<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\SearchLearning\Admin;

use Aifec\Admin\Admin;
use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

final class LearningController
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function index(Request $request, array $params): Response
    {
        LearningAccess::read($this->app);
        $lang = $request->str('lang', $this->app->defaultLang());
        $this->language($lang);
        $service = $this->app->service('search_learning');
        $context = $service->context($lang);
        $tier = $this->app->settings()->str('search_learning.default_tier', 'economy');
        if (!in_array($tier, ['economy', 'standard', 'advanced'], true)) {
            $tier = 'economy';
        }
        return $this->page('index', [
            'context' => $context, 'memoryVersion' => $service->version($lang), 'runs' => $service->recent(30),
            'languages' => $this->app->languages(), 'lang' => $lang, 'tier' => $tier,
            'profiles' => max(1, min(20, $this->app->settings()->int('search_learning.default_profiles', 5))),
            'from' => gmdate('Y-m-d', $this->app->clock()->now() - max(1, min(365, $this->app->settings()->int('search_learning.default_history_days', 7))) * 86400),
            'to' => gmdate('Y-m-d', $this->app->clock()->now()),
        ]);
    }

    public function create(Request $request, array $params): Response
    {
        $actor = LearningAccess::mutate($this->app, $request);
        $lang = $request->str('lang');
        $this->language($lang);
        $mode = $request->str('mode');
        $tier = $request->str('tier');
        $profiles = self::integer($request->input('profiles'), 1, 20);
        $version = self::integer($request->input('expected_version'), 0, 2147483647);
        $hash = $this->hash($request->str('expected_context'));
        if (!in_array($mode, ['history', 'simulated'], true) || !in_array($tier, ['economy', 'standard', 'advanced'], true) || !$request->bool('authorise')) {
            throw new HttpError(422, t('Choose a source and AI tier, and explicitly authorise this round.'));
        }
        $service = $this->app->service('search_learning');
        $current = $service->context($lang);
        if (!hash_equals($current['hash'], $hash) || $service->version($lang) !== $version) {
            throw new HttpError(409, t('The shop context or memory version changed. Reload before authorising a round.'));
        }
        $options = ['mode' => $mode, 'lang' => $lang, 'profiles' => $profiles, 'tier' => $tier,
            'budget_cents' => self::budget($request->str('budget')), 'expected_context' => $hash, 'expected_version' => $version];
        if ($mode === 'history') {
            $from = $this->date($request->str('from'));
            $to = $this->date($request->str('to'));
            $span = (new \DateTimeImmutable($from, new \DateTimeZone('UTC')))->diff(new \DateTimeImmutable($to, new \DateTimeZone('UTC')));
            if ($to < $from || $to > gmdate('Y-m-d', $this->app->clock()->now()) || $span->days > 365) {
                throw new HttpError(422, t('Choose a history period of up to 365 days ending no later than today.'));
            }
            $options['from'] = $from;
            $options['to'] = $to;
        }
        try {
            $id = $service->createRun($options, $actor);
        } catch (\InvalidArgumentException $e) {
            return $this->problem($e, 422);
        } catch (\RuntimeException $e) {
            return $this->problem($e, 409);
        }
        Admin::audit($this->app, 'search_learning.create', 'search_learning', (string) $id);
        Admin::flash($this->app, 'ok', t('The authorised round was queued. No proposal will be applied automatically.'));
        return Response::redirect($this->app->url()->route('admin.search_learning.show', ['id' => $id]), 303);
    }

    public function show(Request $request, array $params): Response
    {
        LearningAccess::read($this->app);
        $service = $this->app->service('search_learning');
        $run = $this->run((int) $params['id']);
        $lang = $this->runLanguage($run);
        $context = $service->context($lang);
        $current = hash_equals($run['context_hash'], $context['hash']);
        return $this->page('show', ['run' => $run, 'items' => $service->items((int) $run['id']), 'proposals' => $service->proposals((int) $run['id']),
            'applications' => $service->applications((int) $run['id']), 'context' => $context,
            'contextCurrent' => $current, 'memoryVersion' => $service->version($lang)]);
    }

    public function action(Request $request, array $params): Response
    {
        $actor = LearningAccess::mutate($this->app, $request);
        $run = $this->run((int) $params['id']);
        $action = (string) $params['action'];
        $hash = $this->hash($request->str('expected_context'));
        $version = self::integer($request->input('expected_version'), 0, 2147483647);
        if (!hash_equals($run['context_hash'], $hash)) {
            throw new HttpError(409, t('This form belongs to another learning context.'));
        }
        $service = $this->app->service('search_learning');
        try {
            if ($action === 'cancel') {
                // Cancelar una ronda antigua sigue siendo posible; nunca autoriza más llamadas ni aplica memoria.
                if ((int) $run['version'] !== $version) {
                    throw new \RuntimeException('learning.version_changed');
                }
                $service->cancel((int) $run['id'], $actor);
            } else {
                $context = $service->context($this->runLanguage($run));
                if (!hash_equals($hash, $context['hash']) || $service->version($this->runLanguage($run)) !== $version) {
                    throw new \RuntimeException('learning.context_or_version_changed');
                }
                if ($action === 'resume') {
                    $service->resume((int) $run['id'], $actor, $hash, $version);
                } elseif ($action === 'validate') {
                    $service->validate((int) $run['id'], $actor, $hash, $version);
                } elseif ($action === 'apply') {
                    if (!$request->bool('reviewed')) {
                        throw new \InvalidArgumentException('learning.review_required');
                    }
                    $service->apply((int) $run['id'], $actor, $hash, $version);
                } elseif ($action === 'revert') {
                    $applicationId = self::integer($request->input('application_id'), 1, 2147483647);
                    $found = false;
                    foreach ($service->applications((int) $run['id']) as $application) {
                        $found = $found || ((int) $application['id'] === $applicationId && (int) $application['run_id'] === (int) $run['id']);
                    }
                    if (!$found || !$request->bool('reviewed')) {
                        throw new \InvalidArgumentException('learning.application_or_review');
                    }
                    $service->revert($applicationId, $actor, $hash, $version);
                } else {
                    throw HttpError::notFound();
                }
            }
        } catch (\InvalidArgumentException $e) {
            return $this->problem($e, 422);
        } catch (\RuntimeException $e) {
            return $this->problem($e, 409);
        }
        Admin::audit($this->app, 'search_learning.' . $action, 'search_learning', (string) $run['id']);
        Admin::flash($this->app, 'ok', t('The authorised learning action was recorded. Review the current status and evidence below.'));
        return Response::redirect($this->app->url()->route('admin.search_learning.show', ['id' => (int) $run['id']]), 303);
    }

    public function download(Request $request, array $params): Response
    {
        LearningAccess::read($this->app);
        $run = $this->run((int) $params['id']);
        $service = $this->app->service('search_learning');
        $context = $service->context($this->runLanguage($run));
        if (!hash_equals($run['context_hash'], $context['hash'])) {
            throw new HttpError(409, t('The shop context changed. This report cannot be downloaded in the current context.'));
        }
        $kind = (string) $params['kind'];
        if (!in_array($kind, ['experience', 'improvements'], true)) {
            throw HttpError::notFound();
        }
        $bytes = LearningReports::render($this->app, $service->report((int) $run['id']), $kind);
        return new Response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="search-learning-' . (int) $run['id'] . '-' . $kind . '.pdf"',
            'Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex, nofollow', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function page(string $view, array $data): Response
    {
        $data += ['app' => $this->app, 'token' => Admin::token($this->app), 'canManage' => LearningAccess::canManage($this->app),
            'shopName' => $this->app->settings()->str('store.name', 'AIFEC'), 'currency' => $this->app->settings()->str('shop.currency', 'EUR')];
        return Admin::page($this->app, 'search-learning::admin/' . $view, $data, ['title' => t('Test and learn'), 'active' => '/admin/search/learning']);
    }

    private function run(int $id): array
    {
        $run = $this->app->service('search_learning')->find($id);
        if ($run === null || !isset($run['context_hash']) || !is_string($run['context_hash'])) {
            throw HttpError::notFound();
        }
        return $run;
    }

    private function runLanguage(array $run): string
    {
        $lang = isset($run['context']['lang']) && is_string($run['context']['lang']) ? $run['context']['lang'] : (string) ($run['lang'] ?? '');
        $this->language($lang);
        return $lang;
    }

    private function language(string $lang): void
    {
        if (!in_array($lang, $this->app->languages(), true)) {
            throw new HttpError(422, t('Choose a language configured for this shop.'));
        }
    }

    private function hash(string $value): string
    {
        if (preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
            throw new HttpError(422, t('The learning context is missing. Reload the page.'));
        }
        return $value;
    }

    private function date(string $value): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new HttpError(422, t('Enter valid history dates.'));
        }
        return $value;
    }

    private function problem(\Throwable $error, int $status): Response
    {
        $message = $error->getMessage();
        $known = ['learning.review_required' => t('Review the pending proposals and explicitly confirm before applying them.'),
            'learning.context_changed' => t('The shop context or memory version changed. Reload before authorising a round.'),
            'learning.version_changed' => t('The shop context or memory version changed. Reload before authorising a round.'),
            'learning.context_or_version_changed' => t('The shop context or memory version changed. Reload before authorising a round.'),
            'learning.application_or_review' => t('Choose an application from this round and explicitly confirm its reversal.')];
        return $this->app->errorResponse($status, $known[$message] ?? LearningLabels::problem($message));
    }

    private static function integer($value, int $min, int $max): int
    {
        if ((!is_int($value) && !is_string($value)) || preg_match('/^\d{1,10}$/', (string) $value) !== 1 || (int) $value < $min || (int) $value > $max) {
            throw new HttpError(422, t('Enter a valid whole number within the allowed limits.'));
        }
        return (int) $value;
    }

    public static function budget(string $value): int
    {
        if (preg_match('/^(\d{1,4})(?:[.,](\d{1,2}))?$/', trim($value), $match) !== 1) {
            throw new HttpError(422, t('Enter an explicit positive budget with at most two decimal places.'));
        }
        $cents = (int) $match[1] * 100 + (int) str_pad($match[2] ?? '', 2, '0');
        if ($cents <= 0 || $cents > 100000) {
            throw new HttpError(422, t('Enter an explicit positive budget with at most two decimal places.'));
        }
        return $cents;
    }
}
