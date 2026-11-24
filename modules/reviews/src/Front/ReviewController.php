<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Reviews\Front;

use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\RateLimiter;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Modules\Reviews\Reviews;

/**
 * Lo que escriben los compradores: el formulario de la ficha (POST /valoracion/{productId}) y la página a la que lleva el enlace
 * del correo (GET y POST /valorar/{ficha}).
 *
 * Defensas, como las de los formularios del contenido: un campo trampa que una persona no ve y un robot rellena (se contesta
 * «gracias» y no se guarda nada) y un límite de envíos por dirección y hora. Todas las acciones van detrás del intermediario
 * «csrf». Las páginas de error y la del enlace del correo no se guardan en ninguna caché y no se indexan.
 */
final class ReviewController
{
    /** Nombre del campo trampa (el mismo que usan los formularios del contenido). */
    public const TRAP = 'website';

    /** Opiniones que se aceptan de una misma dirección cada hora. */
    public const HOURLY_LIMIT = 5;

    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    private function reviews(): Reviews
    {
        /** @var Reviews $reviews */
        $reviews = $this->app->service('reviews');

        return $reviews;
    }

    /**
     * GET /valoracion/{productId}: la dirección a la que envía el formulario de la ficha, abierta a mano. Lleva a la ficha.
     * Auditoría del 24/11/2026: 112 respuestas 405 en un día de gmartos.es, de robots que seguían la acción del formulario.
     *
     * @param array<string,string> $params productId
     */
    public function toProduct(Request $request, array $params): Response
    {
        $product = $this->app->service('catalog')->product((int) $params['productId']);
        if ($product === null) {
            throw HttpError::notFound();
        }

        return Response::redirect((string) $product['url'], 301);
    }

    /**
     * POST /valoracion/{productId}: la opinión desde la ficha.
     *
     * @param array<string,string> $params productId
     */
    public function submit(Request $request, array $params): Response
    {
        $productId = (int) $params['productId'];
        $product = $this->app->service('catalog')->product($productId);
        if ($product === null) {
            throw HttpError::notFound();
        }
        $back = (string) $product['url'];
        $json = $request->wantsJson();

        // Robots: se contesta «gracias» y no se guarda nada.
        if ($this->trapFilled($request)) {
            return $this->thanks($back, 'sent', $json);
        }
        $limiter = new RateLimiter($this->app->db(), $this->app->clock());
        if ($limiter->remaining('reviews.submit:' . $request->ip, self::HOURLY_LIMIT, 3600) < 1) {
            return $this->tooMany($json);
        }

        $values = $this->values($request);
        $result = $this->reviews()->submit(['product_id' => $productId] + $values);
        if (empty($result['ok'])) {
            return $this->invalid($result, $values, $product, $this->app->url()->route('reviews.submit', ['productId' => $productId]), false, $json);
        }
        $limiter->hit('reviews.submit:' . $request->ip, self::HOURLY_LIMIT, 3600);

        return $this->thanks($back, (string) $result['status'] === 'published' ? 'published' : 'sent', $json);
    }

    /**
     * GET /valorar/{token}: la página de la ficha de un solo uso del correo.
     *
     * @param array<string,string> $params token
     */
    public function page(Request $request, array $params): Response
    {
        $token = isset($params['token']) ? (string) $params['token'] : '';
        $row = $this->reviews()->requests()->open($token);
        $product = $row !== null ? $this->app->service('catalog')->product((int) $row['product_id']) : null;
        if ($row === null || $product === null) {
            return $this->unavailable();
        }

        return $this->form($product, $this->app->url()->route('reviews.request', ['token' => $token]), ['name' => (string) $row['name']], [], '', 200);
    }

    /**
     * POST /valorar/{token}: gasta la ficha y guarda la opinión (de compra verificada).
     *
     * @param array<string,string> $params token
     */
    public function submitRequest(Request $request, array $params): Response
    {
        $token = isset($params['token']) ? (string) $params['token'] : '';
        $json = $request->wantsJson();
        $row = $this->reviews()->requests()->open($token);
        $product = $row !== null ? $this->app->service('catalog')->product((int) $row['product_id']) : null;
        if ($row === null || $product === null) {
            if ($json) {
                return Response::json(['ok' => false, 'message' => t('This review link is not valid or has already been used.'), 'errors' => new \stdClass()], 404);
            }

            return $this->unavailable();
        }
        if ($this->trapFilled($request)) {
            return $this->thanks((string) $product['url'], 'sent', $json);
        }

        $values = $this->values($request);
        $values['token'] = $token;
        $result = $this->reviews()->submit($values);
        if (empty($result['ok'])) {
            return $this->invalid($result, $values, $product, $this->app->url()->route('reviews.request', ['token' => $token]), true, $json);
        }

        return $this->thanks((string) $product['url'], (string) $result['status'] === 'published' ? 'published' : 'sent', $json);
    }

    // ------------------------------------------------------------------ respuestas

    private function trapFilled(Request $request): bool
    {
        $value = $request->input(self::TRAP);
        if ($value === null) {
            return false;
        }

        return is_scalar($value) ? trim((string) $value) !== '' : true;
    }

    /**
     * Lo que se manda en el formulario.
     *
     * @return array<string,string>
     */
    private function values(Request $request): array
    {
        $out = [];
        foreach (['name', 'email', 'rating', 'title', 'body'] as $key) {
            $value = $request->input($key);
            $out[$key] = is_scalar($value) ? (string) $value : '';
        }
        $out['lang'] = $this->app->lang();

        return $out;
    }

    private function thanks(string $back, string $notice, bool $json): Response
    {
        if ($json) {
            return Response::json(['ok' => true, 'message' => $notice === 'published' ? t('Thank you! Your review is now published.') : t('Thank you! Your review will appear once we have checked it.')]);
        }

        return Response::redirect($back . (strpos($back, '?') !== false ? '&' : '?') . 'valoracion=' . $notice . '#valoraciones');
    }

    private function tooMany(bool $json): Response
    {
        $message = t('Too many reviews in a short time. Please try again later.');
        if ($json) {
            $response = Response::json(['ok' => false, 'message' => $message, 'errors' => new \stdClass()], 429);
            $response->headers['Retry-After'] = '3600';

            return $response;
        }
        throw HttpError::tooMany($message);
    }

    /**
     * @param array<string,mixed>  $result  lo que devolvió Reviews::submit
     * @param array<string,string> $values  lo que escribió la persona
     * @param array<string,mixed>  $product
     */
    private function invalid(array $result, array $values, array $product, string $action, bool $locked, bool $json): Response
    {
        if ($json) {
            $errors = isset($result['errors']) && is_array($result['errors']) && $result['errors'] !== [] ? $result['errors'] : new \stdClass();

            return Response::json(['ok' => false, 'message' => (string) $result['error'], 'errors' => $errors], 422);
        }
        unset($values['token']);

        return $this->form($product, $action, $values, is_array($result['errors']) ? $result['errors'] : [], (string) $result['error'], 422, $locked);
    }

    /**
     * La página del formulario (la del enlace del correo y la de los errores del formulario de la ficha).
     *
     * @param array<string,mixed>  $product
     * @param array<string,string> $values
     * @param array<string,string> $errors
     */
    private function form(array $product, string $action, array $values, array $errors, string $message, int $status, bool $locked = true): Response
    {
        $page = ['title' => t('Review {product}', ['product' => (string) $product['name']]), 'type' => 'account', 'robots' => 'noindex, nofollow'];
        $response = $this->app->render('reviews::front/write', [
            'page' => $page, 'product' => $product, 'action' => $action, 'values' => $values, 'errors' => $errors, 'message' => $message,
            'locked' => $locked, 'invalid' => false,
        ], $status);
        $response->headers['Cache-Control'] = 'no-store, private';

        return $response;
    }

    private function unavailable(): Response
    {
        $page = ['title' => t('Review link not valid'), 'type' => 'account', 'robots' => 'noindex, nofollow'];
        $response = $this->app->render('reviews::front/write', [
            'page' => $page, 'product' => null, 'action' => '', 'values' => [], 'errors' => [], 'message' => '', 'locked' => true, 'invalid' => true,
        ], 404);
        $response->headers['Cache-Control'] = 'no-store, private';

        return $response;
    }
}
