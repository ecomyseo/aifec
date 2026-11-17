<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Ai\Admin;

use Aifec\Admin\Admin;
use Aifec\Ai\AiException;
use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Modules\Ai\Ai;
use Aifec\Modules\Ai\AiDisabled;
use Aifec\Modules\Ai\AiLimitReached;

/** Prueba explícita de una conexión guardada; nunca acepta ni devuelve una clave de la API. */
final class ConnectionController
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function index(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('ai.edit');

        return Admin::page($this->app, 'ai::admin/connection', [], [
            'title' => t('Test the AI connection'),
            'active' => '/admin/ai/connection',
            'help' => Admin::helpText('Save your provider, model and API key in AI settings first. This test makes one short request using that saved connection. It counts towards the usage and the spending caps. It never changes shop content.'),
        ]);
    }

    public function test(Request $request, array $params): Response
    {
        $app = $this->app;
        $app->auth()->requireCap('ai.edit');
        $tier = $request->str('tier');
        if (!in_array($tier, Ai::TIERS, true)) {
            Admin::flash($app, 'bad', t('Choose an AI tier.'));

            return Response::redirect($app->url()->plain('/admin/ai/connection'));
        }
        /** @var Ai $ai */
        $ai = $app->service('ai');
        $ok = false;
        try {
            $result = $ai->ask('connection.test', [
                'system' => 'This is a connection test. Reply with the single word AIFEC. Do not use tools.',
                'prompt' => 'AIFEC',
                'max_tokens' => 256,
                'temperature' => 0,
            ], $tier);
            $ok = trim($result->text) !== '' && $result->toolCalls === [] && $result->finishReason !== 'MAX_TOKENS';
            if (!$ok) {
                $ai->flagFailure($ai->lastUsageId(), 'The connection test returned no usable text');
            }
            Admin::flash($app, $ok ? 'ok' : 'bad', $ok
                ? ($ai->provider($tier)->name() === 'fake' ? t('The simulated provider answered. No external connection was tested.') : t('The saved AI connection answered successfully.'))
                : t('The provider returned no usable answer. Check the model and try again.'));
        } catch (AiDisabled $e) {
            Admin::flash($app, 'warn', t('The AI is off. Turn it on in AI settings before testing.'));
        } catch (AiLimitReached $e) {
            Admin::flash($app, 'warn', t($e->getMessage()));
        } catch (AiException $e) {
            // Un error de un proveedor puede contener una dirección o una clave: no vuelve al navegador.
            Admin::flash($app, 'bad', t('The connection could not be confirmed. Check the saved provider, model, API key and base address in AI settings.'));
        }
        Admin::audit($app, 'ai.connection.test', 'ai', $tier, $ok ? 'Connection answered' : 'Connection not confirmed');

        return Response::redirect($app->url()->plain('/admin/ai/connection'));
    }
}
