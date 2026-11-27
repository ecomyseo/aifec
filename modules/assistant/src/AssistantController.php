<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Assistant;

use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\RateLimiter;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

/**
 * POST /asistente/mensaje (ruta «assistant.message», intermediario «csrf»): el mensaje de un cliente o el botón «añadir» de una ficha.
 *
 * La conversación se identifica con una cookie propia del sitio que se pone al primer mensaje; sin cookie (o con una que ya no
 * existe) se abre otra. Los límites por IP admiten ajustes por minuto, por día y una pausa entre ráfagas.
 * Con «assistant.enabled» apagado la ruta devuelve 404. La respuesta es JSON y no se guarda
 * en ninguna caché.
 *
 * Con «stream=1» la respuesta llega por partes (application/x-ndjson, una línea JSON cada vez): {"status": "Buscando en la tienda…"}
 * mientras trabaja cada herramienta y, al final, {"done": {…la respuesta de siempre…}}. Cada línea se manda en cuanto existe, sin
 * compresión ni búfer del servidor. Este controlador es del propio módulo: la respuesta es suya y puede escribirla él. Sin «stream», la
 * respuesta entera, como siempre.
 */
final class AssistantController
{
    public const PER_MINUTE = 20;
    public const PER_DAY = 300;
    public const COOLDOWN = 180;

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * @param array<string,string> $params
     */
    public function message(Request $request, array $params): Response
    {
        $app = $this->app;
        if (!$app->settings()->bool('assistant.enabled', true)) {
            throw HttpError::notFound();
        }

        // Lo que pide el navegador: un mensaje o añadir un producto. Sin ninguna de las dos cosas no hay nada que hacer.
        $text = mb_substr($request->str('message'), 0, 4000, 'UTF-8');
        $add = $request->arr('add');
        $productId = isset($add['product_id']) && is_numeric($add['product_id']) ? (int) $add['product_id'] : 0;
        if ($add === [] && trim($text) === '') {
            return Response::json(['error' => t('Write me what you need and I will help you.')], 400);
        }
        if ($add !== [] && $productId < 1) {
            return Response::json(['error' => t('This product is not available')], 400);
        }

        /** @var Assistant $assistant */
        $assistant = $app->service('assistant');
        $conversations = $assistant->conversations();
        $token = (string) $request->cookie($conversations->cookieName(), '');
        $conversation = $token !== '' ? $conversations->findByToken($token) : null;

        $limited = $this->limit($request);
        if ($limited !== null) {
            return $limited;
        }
        if ($conversation !== null) {
            $id = (int) $conversation['id'];
        } else {
            // El primer mensaje abre la conversación y pone la cookie. Ya cuenta como uno de los 20 del visitante.
            $created = $conversations->create($app->lang());
            $id = $created['id'];
            $app->setCookie($conversations->cookieName(), $created['token'], 1);
        }

        if ($add !== []) {
            $variant = isset($add['variant_id']) && is_numeric($add['variant_id']) ? max(0, (int) $add['variant_id']) : 0;
            $qty = isset($add['qty']) && is_numeric($add['qty']) ? max(1, min(99, (int) $add['qty'])) : 1;

            return Response::json($assistant->addToCart($id, $productId, $variant, $qty));
        }

        $page = $request->str('page_path');
        if ($request->bool('stream')) {
            return $this->stream($assistant, $id, $text, $page);
        }

        $reply = $assistant->reply($id, $text, null, $page);
        if ($request->bool('criteria')) { $reply['preferences'] = ChatPreferences::read($app, $request, $id); }
        return Response::json($reply);
    }

    /**
     * La respuesta por partes: una línea JSON por cada cosa que hace el asistente y una última con la respuesta. La escribe el
     * núcleo (Response::stream) después de mandar las cabeceras y las cookies encoladas hasta aquí: la de la conversación, si se
     * acaba de abrir, y **la del carrito**.
     *
     * Lo del carrito es lo que no se ve: si el visitante no tiene carrito y el asistente le añade un producto a mitad de la
     * respuesta, el carrito nacería con las cabeceras ya enviadas, su cookie no saldría nunca y la petición siguiente lo vería
     * vacío, sin un error en ningún sitio. Por eso el carrito se abre ANTES de la primera línea (Cart::ensure) y, si al acabar
     * sigue vacío, se quita de la base de datos.
     */
    private function stream(Assistant $assistant, int $id, string $text, string $page): Response
    {
        $app = $this->app;
        $cart = $app->has('cart') ? $app->service('cart') : null;
        $opened = false;
        if (is_object($cart) && method_exists($cart, 'ensure') && !$cart->exists()) {
            $cart->ensure();
            $opened = true;
        }

        $writer = static function (callable $emit) use ($app, $assistant, $id, $text, $page, $cart, $opened): void {
            $line = static function (array $data) use ($emit): void {
                $emit((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n");
            };
            try {
                $done = $assistant->reply($id, $text, static function (string $status) use ($line): void {
                    $line(['status' => $status]);
                }, $page, $app->request()->bool('progress') ? static function (string $fact) use ($line): void {
                    $line(['fact' => $fact]);
                } : null);
                if ($app->request()->bool('criteria')) { $done['preferences'] = ChatPreferences::read($app, $app->request(), $id); }
                $line(['done' => $done]);
            } catch (\Throwable $e) {
                try {
                    $app->log()->exception($e, 'assistant.stream');
                } catch (\Throwable $ignored) {
                    // Si ni siquiera se puede apuntar, la ventana recibe igualmente su aviso.
                }
                $line(['done' => ['error' => t('Something went wrong. Please try again.')]]);
            }
            if ($opened && is_object($cart)) {
                try {
                    if ($cart->count() === 0) {
                        $cart->clear();
                    }
                } catch (\Throwable $e) {
                    // Un carrito vacío de más lo borra la tarea de carritos viejos.
                }
            }
        };

        return Response::stream($writer, [
            'Content-Type' => 'application/x-ndjson; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * POST /asistente/confirmar (ruta «assistant.confirm», intermediario «csrf»): el botón «Confirmar pedido» de la tarjeta del
     * resumen. Solo vale con la cookie de la conversación en la que se revisó el pedido: sin cookie, o con la de otra
     * conversación, no hay nada que confirmar y no se hace nada. No abre conversaciones.
     *
     * @param array<string,string> $params
     */
    public function confirm(Request $request, array $params): Response
    {
        $app = $this->app;
        if (!$app->settings()->bool('assistant.enabled', true)) {
            throw HttpError::notFound();
        }
        /** @var Assistant $assistant */
        $assistant = $app->service('assistant');
        if (!$assistant->checkoutEnabled()) {
            throw HttpError::notFound();
        }
        $conversations = $assistant->conversations();
        $token = (string) $request->cookie($conversations->cookieName(), '');
        $conversation = $token !== '' ? $conversations->findByToken($token) : null;
        $nothing = Response::json(['error' => t('There is nothing to confirm. Ask me to review your order first.')], 409);

        $limited = $this->limit($request);
        if ($limited !== null) {
            return $limited;
        }
        if ($conversation === null) {
            return $nothing;
        }
        $answer = $assistant->confirm((int) $conversation['id']);

        return $answer === null ? $nothing : Response::json($answer);
    }

    public function starters(Request $request, array $params): Response
    {
        if (!$this->app->settings()->bool('assistant.enabled', true)) {
            throw HttpError::notFound();
        }
        $catalog = $this->app->service('catalog');
        $labels = [];
        $sale = $catalog->listing(['lang' => $this->app->lang(), 'on_sale' => true, 'per_page' => 1]);
        if (!empty($sale['items'])) {
            $labels[] = t('Special offers');
        }
        $categories = $catalog->tree();
        if (count($categories) === 1 && !empty($categories[0]['children'])) {
            $categories = $categories[0]['children'];
        }
        foreach (array_slice($categories, 0, 2) as $category) {
            $labels[] = t('Show me {category}', ['category' => $category['name']]);
        }
        $path = $request->str('page_path');
        if ($path !== '' && $path[0] === '/') {
            $phrases = $this->app->db()->column("SELECT phrases FROM {assistant_guides} WHERE active = 1 AND kind = 'faq' AND lang = ? AND page_path = ? ORDER BY id LIMIT 3", [$this->app->lang(), rtrim($path, '/') ?: '/']);
            foreach ($phrases as $entry) { $labels[] = trim(strtok($entry, "\r\n")); }
        }
        $pageId = ProductTalk::linkedProduct($this->app, $request->str('page_path'));
        if ($pageId > 0) {
            $page = $catalog->product($pageId, $this->app->lang());
            foreach (array_slice($page['faqs'] ?? [], 0, 3) as $faq) { $labels[] = $faq['question']; }
        }
        $labels[] = t('Shipping and returns');
        $labels[] = t('Where is my order?');
        return Response::json(['suggestions' => $labels, 'preferences' => ChatPreferences::read($this->app, $request)]);
    }

    public function preferences(Request $request, array $params): Response
    {
        if (!$this->app->settings()->bool('assistant.enabled', true)) { throw HttpError::notFound(); }
        return Response::json(['preferences' => ChatPreferences::edit($this->app, $request)]);
    }

    public function window(Request $request, array $params): Response
    {
        $app = $this->app;
        if (!$app->settings()->bool('assistant.enabled', true)) { throw HttpError::notFound(); }
        $html = $app->view()->render('assistant::front/window', ['assistant' => $app->service('assistant')]);
        return Response::html($html);
    }

    public function rate(Request $request, array $params): Response
    {
        $app = $this->app;
        if (!$app->settings()->bool('assistant.enabled', true)) { throw HttpError::notFound(); }
        $conversations = $app->service('assistant')->conversations();
        $row = $conversations->findByToken((string) $request->cookie($conversations->cookieName(), ''));
        if ($row === null) { throw HttpError::notFound(); }
        $vote = $request->str('vote');
        $reason = $request->str('reason');
        if (!in_array($vote, ['up', 'down'], true) || !in_array($reason, ['', 'products', 'clarity', 'speed'], true)) {
            throw HttpError::badRequest();
        }
        $app->db()->update('conversations', ['vote' => $vote === 'up' ? 1 : -1, 'vote_reason' => $reason ?: null], ['id' => (int) $row['id']]);
        return Response::json(['rated' => true]);
    }

    public function restart(Request $request, array $params): Response
    {
        $app = $this->app;
        if (!$app->settings()->bool('assistant.enabled', true)) {
            throw HttpError::notFound();
        }
        $conversations = $app->service('assistant')->conversations();
        $name = $conversations->cookieName();
        $token = (string) $request->cookie($name, '');
        if ($token !== '') {
            // Revocar también las copias de la cookie. El siguiente mensaje abre una conversación limpia.
            $app->db()->run('UPDATE {conversations} SET token_hash = ?, state = NULL, handoff_status = ?, handoff_reply = NULL, handoff_summary = NULL WHERE token_hash = ?', [
                hash('sha256', \Aifec\Security\Random::token(32)), '', hash('sha256', $token),
            ]);
        }
        $app->forgetCookie($name);
        return Response::json(['restarted' => true]);
    }

    private function limit(Request $request): ?Response
    {
        $app = $this->app;
        $limiter = new RateLimiter($app->db(), $app->clock());
        $key = 'assistant:ip:' . $request->ip;
        $cooldown = max(1, $app->settings()->int('assistant.cooldown_seconds', self::COOLDOWN));
        $retry = $limiter->retryAfter($key . ':pause', 1, $cooldown);
        if ($retry > 0) {
            return $this->tooMany($retry);
        }
        $perDay = max(1, $app->settings()->int('assistant.messages_per_day', self::PER_DAY));
        $retry = $limiter->retryAfter($key . ':day', $perDay, 86400);
        if ($retry > 0) {
            return $this->tooMany($retry);
        }
        $perMinute = max(1, $app->settings()->int('assistant.messages_per_minute', self::PER_MINUTE));
        if (!$limiter->hit($key . ':minute', $perMinute, 60)) {
            $limiter->hit($key . ':pause', 1, $cooldown);
            return $this->tooMany($cooldown);
        }
        if (!$limiter->hit($key . ':day', $perDay, 86400)) {
            return $this->tooMany(max(1, $limiter->retryAfter($key . ':day', $perDay, 86400)));
        }
        return null;
    }

    private function tooMany(int $seconds): Response
    {
        $response = Response::json(['error' => t('You are writing too fast. Wait a moment and try again.'), 'retry_after' => $seconds], 429);
        $response->headers['Retry-After'] = (string) $seconds;

        return $response;
    }
}
