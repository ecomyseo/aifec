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
use Aifec\Kernel\VisitLog;
use Aifec\Modules\Consent\ConsentService;

/**
 * Lo que la capa de datos pone en la cabecera de la tienda: la configuración para el JavaScript, los guiones de los destinos
 * declarados para esperar su consentimiento (type="text/plain": no se ejecutan ni se descargan hasta que consent.js los activa) y
 * el guion de la capa de datos, con «defer».
 *
 * Es el mismo texto para todos los visitantes: la página sigue siendo cacheable. Las dos excepciones son del equipo con sesión
 * abierta en el panel, que nunca sale de la caché: con «excluir al equipo» no se carga nada, y solo para ellos funciona «?dl_debug=1».
 */
final class Head
{
    /** @var App */
    private $app;

    /** @var Config */
    private $config;

    public function __construct(App $app, Config $config)
    {
        $this->app = $app;
        $this->config = $config;
    }

    /** ¿Es esta petición de alguien del equipo con el panel abierto? Se mira la marca de la sesión, sin abrirla. */
    public function isStaff(): bool
    {
        return $this->app->request()->cookie($this->app->cookieName('a')) !== null;
    }

    /** La configuración para datalayer.js. */
    public function settingsFor(bool $staff): array
    {
        $cfg = ['v' => 1, 'cur' => $this->app->settings()->str('shop.currency', 'EUR'), 'off' => 0];
        if ($staff && $this->config->excludeStaff()) {
            $cfg['off'] = 1;
        }
        if ($staff && $this->app->request()->str('dl_debug') === '1') {
            $cfg['dbg'] = 1;
        }
        if ($this->config->gtm() !== '') {
            $cfg['gtm'] = 1;
        }
        if ($this->config->metaPixel() !== '') {
            $cfg['meta'] = 1;
        }
        if ($this->config->tiktok() !== '') {
            $cfg['tt'] = 1;
        }
        if ($this->config->uet() !== '') {
            $cfg['uet'] = 1;
        }
        if ($this->config->adsId() !== '' && $this->config->adsLabel() !== '') {
            $cfg['ads'] = ['id' => $this->config->adsId(), 'label' => $this->config->adsLabel()];
        }
        $cfg['dl'] = $this->app->cookieName('dl');
        $cfg['sq'] = 'q';
        if ($this->app->router()->has('search')) {
            $cfg['sp'] = (string) parse_url($this->app->url()->route('search'), PHP_URL_PATH);
        }
        $cfg['ai'] = VisitLog::AI_REFERRERS;
        $cfg['cp'] = $this->app->cookiePath();
        if ($this->app->router()->has('cart.remove')) {
            $cfg['rm'] = (string) parse_url($this->app->url()->route('cart.remove'), PHP_URL_PATH);
        }

        return $cfg;
    }

    /** El HTML de la cabecera. */
    public function render(): string
    {
        if (!$this->config->enabled()) {
            return '';
        }
        $staff = $this->isStaff();
        $cfg = $this->settingsFor($staff);
        $html = '<script type="application/json" id="af-dl-config">' . json_html($cfg) . '</script>';
        if (empty($cfg['off'])) {
            $html .= $this->loaders();
        }

        return $html . '<script src="' . e($this->app->url()->asset('m', 'datalayer', 'datalayer.js')) . '" defer></script>';
    }

    /**
     * ¿Se pinta en las páginas algún guion de un destino (GTM, Google, Meta, TikTok, Microsoft)? Esos guiones escriben y cargan
     * los suyos, y con una política de contenido estricta no funcionarían: quien pregunta es el filtro «csp».
     */
    public function hasLoaders(): bool
    {
        return $this->config->enabled() && $this->loaders() !== '';
    }

    /**
     * Los guiones de los destinos, cada uno esperando su categoría.
     */
    private function loaders(): string
    {
        if ($this->app->site()->isDemo()) {
            return '';
        }
        $c = $this->config;
        $out = '';
        if ($c->gtm() !== '') {
            $id = $c->gtm();
            $out .= $this->wait($c->category('gtm'), "(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s);j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i;f.parentNode.insertBefore(j,f)})(window,document,'script','dataLayer','" . $id . "');");
        }
        // Google (GA4 y Ads) comparten la biblioteca gtag.js: una sola descarga por categoría.
        $groups = [];
        if ($c->ga4() !== '') {
            $groups[$c->category('ga4')][] = ['id' => $c->ga4(), 'config' => ''];
        }
        if ($c->adsId() !== '') {
            $groups[$c->category('ads')][] = ['id' => $c->adsId(), 'config' => $c->adsEnhanced() ? ",{allow_enhanced_conversions:true}" : ''];
        }
        foreach ($groups as $category => $tags) {
            $code = "gtag('js',new Date());";
            foreach ($tags as $tag) {
                $code .= "gtag('config','" . $tag['id'] . "'" . $tag['config'] . ');';
            }
            $out .= $this->wait($category, 'https://www.googletagmanager.com/gtag/js?id=' . $tags[0]['id'], ['async' => true]) . $this->wait($category, $code);
        }
        if ($c->metaPixel() !== '') {
            $out .= $this->wait($c->category('meta'), "!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init','" . $c->metaPixel() . "');fbq('track','PageView');");
        }
        if ($c->tiktok() !== '') {
            $out .= $this->wait($c->category('tiktok'), "!function(w,d,t){w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=['page','track','identify','instances','debug','on','off','once','ready','alias','group','enableCookie','disableCookie'],ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);ttq.instance=function(t){for(var e=ttq._i[t]||[],n=0;n<ttq.methods.length;n++)ttq.setAndDefer(e,ttq.methods[n]);return e},ttq.load=function(e,n){var r='https://analytics.tiktok.com/i18n/pixel/events.js';ttq._i=ttq._i||{},ttq._i[e]=[],ttq._i[e]._u=r,ttq._t=ttq._t||{},ttq._t[e]=+new Date,ttq._o=ttq._o||{},ttq._o[e]=n||{};var o=document.createElement('script');o.type='text/javascript',o.async=!0,o.src=r+'?sdkid='+e+'&lib='+t;var a=document.getElementsByTagName('script')[0];a.parentNode.insertBefore(o,a)};ttq.load('" . $c->tiktok() . "');ttq.page()}(window,document,'ttq');");
        }
        if ($c->uet() !== '') {
            $out .= $this->wait($c->category('uet'), "(function(w,d,t,r,u){var f,n,i;w[u]=w[u]||[],f=function(){var o={ti:'" . $c->uet() . "',enableAutoSpaTracking:true};o.q=w[u],w[u]=new UET(o),w[u].push('pageLoad')},n=d.createElement(t),n.src=r,n.async=1,n.onload=n.onreadystatechange=function(){var s=this.readyState;s&&s!=='loaded'&&s!=='complete'||(f(),n.onload=n.onreadystatechange=null)},i=d.getElementsByTagName(t)[0],i.parentNode.insertBefore(n,i)})(window,document,'script','//bat.bing.com/bat.js','uetq');");
        }
        foreach (['preferences', 'statistics', 'marketing'] as $category) {
            $code = $c->custom($category);
            if ($code !== '') {
                $out .= $this->wait($category, $code);
            }
        }

        return $out;
    }

    /**
     * Un guion que espera su categoría (lo escribe el servicio de consentimiento; con «necessary» sale normal).
     *
     * @param array<string,mixed> $attrs
     */
    private function wait(string $category, string $srcOrCode, array $attrs = []): string
    {
        /** @var ConsentService $consent */
        $consent = $this->app->service('consent');

        return $consent->script($category, $srcOrCode, $attrs);
    }
}
