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

/**
 * Publicado como el servicio «datalayer»: junta las piezas del módulo (ajustes, constructor de eventos, marcas de pedido, página,
 * cabecera, eventos del servidor y marcas de inicio de sesión) y las entrega ya montadas.
 */
final class Datalayer
{
    /** @var App */
    private $app;

    /** @var Config */
    private $config;

    /** @var EventBuilder */
    private $builder;

    /** @var OrderMarks */
    private $marks;

    /** @var Pages */
    private $pages;

    /** @var Head */
    private $head;

    /** @var ServerEvents */
    private $server;

    /** @var Markers */
    private $markers;

    public function __construct(App $app)
    {
        $this->app = $app;
        $this->config = new Config($app);
        $this->builder = new EventBuilder($app, $this->config);
        $this->marks = new OrderMarks($app);
        $this->pages = new Pages($app, $this->config, $this->builder, $this->marks);
        $this->head = new Head($app, $this->config);
        $this->server = new ServerEvents($app, $this->config, $this->builder, $this->marks);
        $this->markers = new Markers($app, $this->config);
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function builder(): EventBuilder
    {
        return $this->builder;
    }

    public function marks(): OrderMarks
    {
        return $this->marks;
    }

    public function pages(): Pages
    {
        return $this->pages;
    }

    public function head(): Head
    {
        return $this->head;
    }

    public function server(): ServerEvents
    {
        return $this->server;
    }

    public function markers(): Markers
    {
        return $this->markers;
    }

    /**
     * Las cookies que ponen los destinos encendidos, para el inventario del consentimiento (filtro «consent.cookies»).
     *
     * @param array<int,array<string,string>> $list
     *
     * @return array<int,array<string,string>>
     */
    public function cookies(array $list): array
    {
        if (!$this->config->enabled()) {
            return $list;
        }
        $c = $this->config;
        $list[] = ['name' => $this->app->cookieName('dl'), 'provider' => $this->app->site()->url(), 'purpose' => t('Remembers that you signed in or created an account, to measure it'), 'duration' => t('1 day'), 'category' => 'statistics'];
        if ($c->ga4() !== '' || $c->gtm() !== '') {
            $list[] = ['name' => '_ga', 'provider' => 'Google', 'purpose' => t('Tells visitors apart to measure how the shop is used'), 'duration' => t('2 years'), 'category' => $c->category($c->ga4() !== '' ? 'ga4' : 'gtm')];
        }
        if ($c->adsId() !== '') {
            $list[] = ['name' => '_gcl_au', 'provider' => 'Google', 'purpose' => t('Measures how well adverts lead to a purchase'), 'duration' => t('3 months'), 'category' => $c->category('ads')];
        }
        if ($c->metaPixel() !== '') {
            $list[] = ['name' => '_fbp', 'provider' => 'Meta', 'purpose' => t('Measures adverts and shows them to people who may like them'), 'duration' => t('3 months'), 'category' => $c->category('meta')];
        }
        if ($c->tiktok() !== '') {
            $list[] = ['name' => '_ttp', 'provider' => 'TikTok', 'purpose' => t('Measures adverts and shows them to people who may like them'), 'duration' => t('13 months'), 'category' => $c->category('tiktok')];
        }
        if ($c->uet() !== '') {
            $list[] = ['name' => '_uetvid', 'provider' => 'Microsoft', 'purpose' => t('Measures adverts and shows them to people who may like them'), 'duration' => t('13 months'), 'category' => $c->category('uet')];
        }

        return $list;
    }
}
