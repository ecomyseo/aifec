<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Customers\Front;

use Aifec\Geo\Countries;
use Aifec\Kernel\App;
use Aifec\Kernel\Response;
use Aifec\Modules\Customers\Customers;
use Aifec\Security\Csrf;

/**
 * Piezas comunes de la zona de cliente de la tienda: la dirección base, el cliente que ha entrado, los avisos de una
 * petición a la siguiente, la ficha de los formularios, las redirecciones seguras y el pintado de las páginas.
 *
 * Todas las páginas de la zona salen con tipo «account» (sin indexar) y con «no-store»: nada de lo que se pinta aquí
 * puede guardarse en una caché ni en un intermediario.
 */
final class Account
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function customers(): Customers
    {
        $service = $this->app->service('customers');
        if (!$service instanceof Customers) {
            throw new \LogicException('The customers service is not the one of the module');
        }

        return $service;
    }

    /** Dirección base de la zona de cliente (ajuste urls.account), con la barra delante y sin barra detrás. */
    public function base(): string
    {
        $base = trim($this->app->settings()->str('urls.account', 'mi-cuenta'), '/');

        return '/' . ($base !== '' ? $base : 'mi-cuenta');
    }

    /** El cliente que ha entrado (una cuenta activa), o null. */
    public function me(): ?array
    {
        return $this->customers()->current();
    }

    /** Hay módulo de pedidos en este sitio. */
    public function hasOrders(): bool
    {
        return $this->app->has('orders');
    }

    /**
     * @param array<string,string|int> $params
     */
    public function link(string $name, array $params = []): string
    {
        return $this->app->url()->route($name, $params);
    }

    /**
     * Redirección (303) a una ruta con nombre.
     *
     * @param array<string,string|int> $params
     */
    public function go(string $name, array $params = []): Response
    {
        return $this->goTo($this->link($name, $params));
    }

    public function goTo(string $url): Response
    {
        $response = Response::redirect($url, 303);
        $response->headers['Cache-Control'] = 'no-store, private';

        return $response;
    }

    /** A la pantalla de entrada. Una sesión que ya no vale (cuenta apagada) se cierra de paso. */
    public function toLogin(): Response
    {
        if ($this->app->auth()->customerId() !== null) {
            $this->customers()->logout();
        }

        return $this->go('account.login');
    }

    /**
     * Dirección a la que volver tras entrar: solo caminos del propio sitio («/algo»). Una dirección con dominio, con
     * «//» o «/\» al principio, con barras invertidas o con caracteres de control se descarta (devuelve «»).
     */
    public function safeNext(string $next): string
    {
        $next = trim($next);
        if ($next === '' || strlen($next) > 500 || $next[0] !== '/') {
            return '';
        }
        if (isset($next[1]) && ($next[1] === '/' || $next[1] === '\\')) {
            return '';
        }
        if (preg_match('/[\x00-\x1f\x7f\\\\]/', $next) === 1) {
            return '';
        }

        return $next;
    }

    /** Un aviso que se enseña en la petición siguiente. $type: ok, bad, info o warn. */
    public function flash(string $type, string $text): void
    {
        $session = $this->app->session('customer');
        $flash = $session->get('_flash', []);
        $list = is_array($flash) && isset($flash['messages']) && is_array($flash['messages']) ? $flash['messages'] : [];
        $list[] = [$type, $text];
        $session->flash('messages', $list);
    }

    /** Ficha para los formularios de quien ha entrado. */
    public function token(): string
    {
        $session = $this->app->session('customer');

        return Csrf::issue($session->csrfSecret(), 'front', $this->app->clock()->now());
    }

    /** «» si la contraseña es la de esa cuenta; si no, el motivo ya traducido. Cuenta como intento de entrada. */
    public function passwordProblem(array $me, string $password): string
    {
        $customers = $this->customers();
        $email = (string) $me['email'];
        if ($customers->isLocked($email)) {
            return $this->lockedText();
        }
        if ($password === '') {
            return t('Enter your current password.');
        }
        if ($customers->login($email, $password) === (int) $me['id']) {
            return '';
        }

        return $customers->isLocked($email) ? $this->lockedText() : t('The password is not correct.');
    }

    public function lockedText(): string
    {
        return t('Too many failed attempts. Try again in {minutes} minutes.', ['minutes' => (int) (Customers::LOCK_SECONDS / 60)]);
    }

    /**
     * Las líneas de una dirección para enseñarla: nombre, empresa, calle, código postal y ciudad, y país.
     *
     * @param array<string,mixed> $a
     *
     * @return string[]
     */
    public function addressLines(array $a): array
    {
        return \Aifec\Geo\Address::lines($a, $this->app->lang(), ['tax_id' => false, 'phone' => false]);
    }

    /**
     * Pinta una plantilla de la zona («customers::front/<plantilla>») dentro del envoltorio del tema.
     *
     * Datos que se pasan siempre: page, messages (los de $data y los que quedaron de la petición anterior), token (solo
     * si va «customer» en $data: la ficha de los formularios) y account (este objeto).
     *
     * @param array<string,mixed> $data
     */
    public function render(string $template, string $title, array $data = [], int $status = 200): Response
    {
        // La dirección canónica va sin parámetros (next, page, done): la página es la misma.
        $url = $this->app->url();
        $data['page'] = [
            'title' => $title, 'type' => 'account', 'robots' => 'noindex, nofollow', 'body_class' => 'af-account',
            'canonical' => $url->origin() . $url->to($this->app->request()->path),
        ];
        $flash = $this->app->session('customer')->pullFlash();
        $messages = isset($flash['messages']) && is_array($flash['messages']) ? $flash['messages'] : [];
        if (isset($data['messages']) && is_array($data['messages'])) {
            $messages = array_merge($messages, $data['messages']);
        }
        $data['messages'] = $messages;
        $data['token'] = isset($data['customer']) && is_array($data['customer']) ? $this->token() : '';
        $data['account'] = $this;
        $response = $this->app->render('customers::front/' . $template, $data, $status);
        $response->headers['Cache-Control'] = 'no-store, private';
        $response->headers['X-Robots-Tag'] = 'noindex, nofollow';

        return $response;
    }
}
