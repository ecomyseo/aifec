<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Gm\Proximamente;

use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\RateLimiter;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

/**
 * POST «avísame»: guarda la petición de quien quiere que le avisen cuando el producto esté disponible.
 *
 * Pide el correo y la casilla de consentimiento. Un campo trampa que los robots rellenan se contesta con «gracias» sin guardar
 * nada, y hay un límite de usos por hora y dirección. La respuesta no se guarda en ninguna caché.
 */
final class NotifyController
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * @param array<string,string> $params
     */
    public function notify(Request $request, array $params): Response
    {
        $productId = $request->int('product_id');
        $product = $this->app->service('catalog')->product($productId, $this->app->lang());
        if ($product === null || !Soon::isSoon($this->app, $productId)) {
            throw HttpError::notFound();
        }
        // Los robots rellenan todo lo que ven: se les contesta «gracias» y no se guarda nada.
        if ($request->str('website') !== '') {
            return $this->page($product, true, '', []);
        }
        $limit = max(1, $this->app->settings()->int('gm_proximamente.rate_per_hour', 5));
        $limiter = new RateLimiter($this->app->db(), $this->app->clock());
        if (!$limiter->hit('gm_soon:' . $request->ip, $limit, 3600)) {
            throw HttpError::tooMany(t('Too many attempts. Try again in an hour.'));
        }
        $email = strtolower(trim($request->str('email')));
        $errors = [];
        if ($email === '' || strlen($email) > 191 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = t('Enter a valid email address.');
        }
        if (!$request->bool('consent')) {
            $errors['consent'] = t('Tick the box to say you agree.');
        }
        if ($errors !== []) {
            return $this->page($product, false, $email, $errors, 422);
        }
        (new Soon($this->app))->request($productId, $email, $this->app->lang());

        return $this->page($product, true, $email, []);
    }

    /**
     * @param array<string,mixed>  $product
     * @param array<string,string> $errors
     */
    private function page(array $product, bool $done, string $email, array $errors, int $status = 200): Response
    {
        $title = $done ? t('We will let you know') : t('Tell me when it is available');
        $response = $this->app->render('gm-proximamente::front/notify', [
            'page' => ['type' => 'page', 'title' => $title, 'robots' => 'noindex, nofollow', 'breadcrumbs' => []],
            'product' => $product, 'done' => $done, 'email' => $email, 'errors' => $errors, 'title' => $title,
        ], $status);
        $response->headers['Cache-Control'] = 'no-store, private';

        return $response;
    }
}
