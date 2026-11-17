<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Copilot\Admin;

use Aifec\Admin\Admin;
use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Modules\Copilot\Copilot;
use Aifec\Modules\Copilot\Palette;

/**
 * La pantalla del copiloto: la caja donde se escribe la orden, la propuesta en curso con su «antes → después» y el historial.
 *
 * Funciona entera SIN JavaScript: cada botón es un formulario que envía, contesta con una redirección 303 y la pantalla siguiente
 * enseña el aviso. Con JavaScript, los mismos formularios (data-af-ajax) piden JSON y la zona «#cp-stage» se rehace sin recargar.
 * Todo exige el permiso «copilot.use»; aplicar y deshacer exigen además el permiso de la acción. Las propuestas son de quien las
 * escribió: la de otra persona da 404.
 */
final class CopilotController
{
    /** @var App */
    private $app;

    /** @var Copilot */
    private $copilot;

    public function __construct(App $app)
    {
        $this->app = $app;
        /** @var Copilot $copilot */
        $copilot = $app->service('copilot');
        $this->copilot = $copilot;
    }

    /**
     * La pantalla. Con «?q=…» (el «Hacer: …» del buscador) prepara la propuesta y salta a ella.
     *
     * @param array<string,string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $this->guard();
        $q = trim($request->str('q'));
        if ($q !== '') {
            $proposal = $this->copilot->propose($q);

            return Response::redirect($this->showUrl((int) $proposal['id']), 303);
        }

        return $this->page(null);
    }

    /**
     * @param array<string,string> $params id
     */
    public function show(Request $request, array $params): Response
    {
        $this->guard();

        return $this->page($this->copilot->mine((int) $params['id']));
    }

    /**
     * Una orden nueva.
     *
     * @param array<string,string> $params
     */
    public function ask(Request $request, array $params): Response
    {
        $this->guard();
        $text = trim($request->str('text'));
        if ($text === '') {
            return $this->answer($request, null, false, 'warn', t('Write what you want to do.'));
        }
        $proposal = $this->copilot->propose($text);
        $kind = (string) $proposal['status'] === 'failed' ? 'warn' : 'info';
        $message = (string) $proposal['status'] === 'failed' ? (string) $proposal['result'] : '';

        return $this->answer($request, $proposal, true, $kind, $message);
    }

    /**
     * La persona rellena o corrige datos de la propuesta.
     *
     * @param array<string,string> $params id
     */
    public function complete(Request $request, array $params): Response
    {
        $this->guard();
        $input = $request->arr('p');
        $proposal = $this->copilot->complete((int) $params['id'], $input);

        return $this->answer($request, $proposal, true, 'info', '');
    }

    /**
     * La persona elige una de las otras lecturas que se le ofrecieron.
     *
     * @param array<string,string> $params id
     */
    public function choose(Request $request, array $params): Response
    {
        $this->guard();
        $proposal = $this->copilot->choose((int) $params['id'], $request->int('alt', -1));

        return $this->answer($request, $proposal, true, 'info', '');
    }

    /**
     * Aplica. Exige el permiso de la acción (si no, 403).
     *
     * @param array<string,string> $params id
     */
    public function apply(Request $request, array $params): Response
    {
        $this->guard();
        $result = $this->copilot->apply((int) $params['id']);

        return $this->answer($request, $result['proposal'], $result['ok'], $result['ok'] ? 'ok' : ($result['stale'] ? 'warn' : 'bad'), $result['message']);
    }

    /**
     * @param array<string,string> $params id
     */
    public function discard(Request $request, array $params): Response
    {
        $this->guard();
        $proposal = $this->copilot->discard((int) $params['id']);

        return $this->answer($request, $proposal, true, 'info', t('Discarded. Nothing was changed.'));
    }

    /**
     * @param array<string,string> $params id
     */
    public function undo(Request $request, array $params): Response
    {
        $this->guard();
        $result = $this->copilot->undo((int) $params['id']);

        return $this->answer($request, $result['proposal'], $result['ok'], $result['ok'] ? 'ok' : 'bad', $result['message']);
    }

    // ------------------------------------------------------------------ interior

    private function guard(): void
    {
        $this->app->auth()->requireCap('copilot.use');
    }

    private function showUrl(int $id): string
    {
        return $this->app->url()->plain('/admin/copilot/' . $id);
    }

    /**
     * La respuesta a un envío: JSON con la zona nueva si lo pidió JavaScript; si no, un aviso y 303 a la propuesta.
     *
     * @param array<string,mixed>|null $proposal
     */
    private function answer(Request $request, ?array $proposal, bool $ok, string $kind, string $message): Response
    {
        if ($request->wantsJson()) {
            $data = ['ok' => $ok, 'message' => $message, 'id' => $proposal !== null ? (int) $proposal['id'] : 0];
            if ($proposal !== null) {
                $data['status'] = (string) $proposal['status'];
                $data['html'] = $this->stage($proposal);
            }

            return Response::json($data, $proposal === null ? 422 : 200);
        }
        if ($message !== '') {
            Admin::flash($this->app, $kind, $message);
        }

        return Response::redirect($proposal !== null ? $this->showUrl((int) $proposal['id']) : $this->app->url()->plain('/admin/copilot'), 303);
    }

    /**
     * La pantalla entera, con la propuesta en curso si la hay.
     *
     * @param array<string,mixed>|null $proposal
     */
    private function page(?array $proposal): Response
    {
        $app = $this->app;
        $url = $app->url();

        return Admin::page($app, 'copilot::admin/index', [
            'stage' => $this->stage($proposal),
            'examples' => $this->copilot->examples(),
            'urls' => ['ask' => $url->plain('/admin/copilot/ask')],
        ], [
            'title' => t('Copilot'),
            'active' => '/admin/copilot',
            'help' => Admin::helpText("«Copilot» does tasks in this panel for you when you ask in plain words; it never talks to customers. The chat with customers is «Assistant».\nWrite what you want in plain language: «mark order 12 as shipped with tracking 4455», «raise the prices of the category chairs by 5 %», «where do I change the VAT?».\nThe copilot prepares the change and shows exactly what will change. Nothing is applied until you press Apply.\nIf the data changes between your look and your click, it does not apply: it shows you the new preview.\nAfter applying you can undo the change while nothing else has touched it. It never refunds money or deletes anything."),
        ]);
    }

    /**
     * La zona que se rehace sin recargar: la propuesta y el historial.
     *
     * @param array<string,mixed>|null $proposal
     */
    private function stage(?array $proposal): string
    {
        $app = $this->app;
        $url = $app->url();
        $present = $proposal !== null ? $this->copilot->present($proposal) : null;
        $found = [];
        if ($present !== null && $present['action'] === '') {
            foreach (['settingsItems', 'dataItems', 'helpItems'] as $part) {
                try {
                    foreach (call_user_func([Palette::class, $part], $app, (string) $present['text']) as $item) {
                        $found[] = $item;
                    }
                } catch (\Throwable $e) {
                    $app->log()->exception($e, 'copilot found ' . $part);
                }
            }
        }
        $history = [];
        foreach ($this->copilot->proposals()->history($app->auth()->adminId(), 15) as $row) {
            $history[] = $this->copilot->present($row);
        }
        $base = '/admin/copilot/';

        return $app->view()->render('copilot::admin/stage', [
            'proposal' => $present,
            'found' => $found,
            'history' => $history,
            'urls' => [
                'complete' => $present !== null ? $url->plain($base . $present['id'] . '/complete') : '',
                'choose' => $present !== null ? $url->plain($base . $present['id'] . '/choose') : '',
                'apply' => $present !== null ? $url->plain($base . $present['id'] . '/apply') : '',
                'discard' => $present !== null ? $url->plain($base . $present['id'] . '/discard') : '',
                'undo' => $present !== null ? $url->plain($base . $present['id'] . '/undo') : '',
                'show' => $url->plain($base),
                'base' => $url->plain($base),
            ],
        ]);
    }
}
