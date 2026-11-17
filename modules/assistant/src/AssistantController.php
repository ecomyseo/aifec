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
 * no existe) se abre otra. Límites: 20 mensajes cada 10 minutos por conversación y 60 por dirección IP (para que borrar la cookie
 * no sirva de nada). Con el ajuste «assistant.enabled» apagado la ruta no existe (404). La respuesta es JSON y no se guarda
 * en ninguna caché.
 *
 * Con «stream=1» la respuesta llega por partes (application/x-ndjson, una línea JSON cada vez): {"status": "Buscando en la tienda…"}
 * mientras trabaja cada herramienta y, al final, {"done": {…la respuesta de siempre…}}. Cada línea se manda en cuanto existe, sin
 * compresión ni búfer del servidor. Este controlador es del propio módulo: la respuesta es suya y puede escribirla él. Sin «stream», la
 * respuesta entera, como siempre.
 */
final class AssistantController
{
    /** Mensajes por visitante en la ventana. */
    public const PER_VISITOR = 20;

    /** Mensajes por dirección IP en la misma ventana. */
    public const PER_IP = 60;

    /** Ventana de los límites, en segundos. */
    public const WINDOW = 600;

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

        $limiter = new RateLimiter($app->db(), $app->clock());
        if (!$limiter->hit('assistant:ip:' . $request->ip, self::PER_IP, self::WINDOW)) {
            return $this->tooMany();
        }
        $newToken = null;
        if ($conversation !== null) {
            if (!$limiter->hit(self::visitorKey((string) $conversation['token_hash']), self::PER_VISITOR, self::WINDOW)) {
                return $this->tooMany();
            }
            $id = (int) $conversation['id'];
        } else {
            // El primer mensaje abre la conversación y pone la cookie. Ya cuenta como uno de los 20 del visitante.
            $created = $conversations->create($app->lang());
            $id = $created['id'];
            $app->setCookie($conversations->cookieName(), $created['token'], 1);
            $limiter->hit(self::visitorKey(hash('sha256', $created['token'])), self::PER_VISITOR, self::WINDOW);
        }

        if ($add !== []) {
            $variant = isset($add['variant_id']) && is_numeric($add['variant_id']) ? max(0, (int) $add['variant_id']) : 0;
            $qty = isset($add['qty']) && is_numeric($add['qty']) ? max(1, min(99, (int) $add['qty'])) : 1;

            return Response::json($assistant->addToCart($id, $productId, $variant, $qty));
        }

        if ($request->bool('stream')) {
            return $this->stream($assistant, $id, $text);
        }

        return Response::json($assistant->reply($id, $text));
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
    private function stream(Assistant $assistant, int $id, string $text): Response
    {
        $app = $this->app;
        $cart = $app->has('cart') ? $app->service('cart') : null;
        $opened = false;
        if (is_object($cart) && method_exists($cart, 'ensure') && !$cart->exists()) {
            $cart->ensure();
            $opened = true;
        }

        $writer = static function (callable $emit) use ($app, $assistant, $id, $text, $cart, $opened): void {
            $line = static function (array $data) use ($emit): void {
                $emit((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n");
            };
            try {
                $done = $assistant->reply($id, $text, static function (string $status) use ($line): void {
                    $line(['status' => $status]);
                });
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

        $limiter = new RateLimiter($app->db(), $app->clock());
        if (!$limiter->hit('assistant:ip:' . $request->ip, self::PER_IP, self::WINDOW)) {
            return $this->tooMany();
        }
        if ($conversation === null) {
            return $nothing;
        }
        if (!$limiter->hit(self::visitorKey((string) $conversation['token_hash']), self::PER_VISITOR, self::WINDOW)) {
            return $this->tooMany();
        }
        $answer = $assistant->confirm((int) $conversation['id']);

        return $answer === null ? $nothing : Response::json($answer);
    }

    private static function visitorKey(string $tokenHash): string
    {
        return 'assistant:c:' . substr($tokenHash, 0, 32);
    }

    private function tooMany(): Response
    {
        $response = Response::json(['error' => t('You are writing too fast. Wait a moment and try again.')], 429);
        $response->headers['Retry-After'] = (string) self::WINDOW;

        return $response;
    }
}
