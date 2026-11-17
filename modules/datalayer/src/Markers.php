<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Datalayer;

use Aifec\Kernel\App;
use Aifec\Modules\Consent\ConsentService;

/**
 * Los eventos «login» y «sign_up» ocurren en una petición que acaba en una redirección: la página siguiente ya está en la caché y
 * no puede llevar nada del visitante. Se deja una marca legible de un día («af_dl_…» = «login» o «sign_up», sin ningún dato) que
 * datalayer.js lee en la página siguiente, empuja y borra. Solo se pone si la decisión del visitante concede las estadísticas.
 */
final class Markers
{
    /** @var App */
    private $app;

    /** @var Config */
    private $config;

    /** @var string lo que ya se pidió poner en esta petición (un alta también inicia sesión: gana «sign_up») */
    private $pending = '';

    public function __construct(App $app, Config $config)
    {
        $this->app = $app;
        $this->config = $config;
    }

    /** Un cliente ha iniciado sesión (acción customer.login). */
    public function login(): void
    {
        $this->mark('login');
    }

    /** Un cliente se ha dado de alta (acción customer.registered). */
    public function registered(): void
    {
        $this->mark('sign_up');
    }

    private function mark(string $event): void
    {
        if (!$this->config->enabled() || $this->app->silenced() || !$this->app->has('consent') || ($this->pending === 'sign_up' && $event === 'login')) {
            return;
        }
        /** @var ConsentService $consent */
        $consent = $this->app->service('consent');
        if (!$consent->allows('statistics')) {
            return;
        }
        $this->pending = $event;
        $this->app->setCookie($this->app->cookieName('dl'), $event, 1, true);
    }
}
