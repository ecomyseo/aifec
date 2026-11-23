<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Admin\Menu;
use Aifec\Autoload;
use Aifec\Kernel\App;

require_once __DIR__ . '/../../modules/orders/tests/AdminPanelKit.php';

/*
 * El menú ordenado: una entrada por conjunto de pantallas hermanas, pestañas bajo el título y el presupuesto de 60 entradas.
 * Se prueba con TODOS los módulos encendidos (también los que no vienen de serie), como en el panel de verdad.
 */

if (!function_exists('menuTabsSlugs')) {
    /**
     * Todos los módulos del proyecto, menos los que se piden apagados.
     *
     * @param string[] $without
     *
     * @return string[]
     */
    function menuTabsSlugs(array $without = []): array
    {
        $slugs = [];
        foreach (glob(Autoload::root() . '/modules/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (!in_array(basename($dir), $without, true)) {
                $slugs[] = basename($dir);
            }
        }

        return $slugs;
    }

    /**
     * Sitio con esos módulos y todos encendidos.
     *
     * @param string[]|null $modules null = todos
     */
    function menuTabsApp(?array $modules = null): App
    {
        $modules = $modules !== null ? $modules : menuTabsSlugs();

        // 'master': el sitio principal, el único donde sale «Sitios y demostraciones».
        return testApp(['modules' => $modules, 'settings' => ['modules.active' => $modules], 'config' => ['master' => true]]);
    }

    /**
     * El menú tal como se pinta: [[grupo, [entrada, …]], …]; el grupo es '' para los enlaces sueltos (Inicio, Copiloto).
     *
     * @return array<int,array{0:string,1:string[]}>
     */
    function menuTabsNav(string $html): array
    {
        $start = strpos($html, '<nav class="af-nav">');
        $end = $start !== false ? strpos($html, '</nav>', $start) : false;
        $nav = $start !== false && $end !== false ? substr($html, $start, $end - $start) : '';
        $out = [];
        preg_match_all('#<a class="af-nav-link"[^>]*>.*?<span>(.*?)</span></a>|<summary>.*?<span>(.*?)</span></summary>\s*<div class="af-nav-sub">(.*?)</div>#s', $nav, $m, PREG_SET_ORDER);
        foreach ($m as $hit) {
            if ($hit[1] !== '') {
                $out[] = ['', [html_entity_decode($hit[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')]];
                continue;
            }
            preg_match_all('#<a href="[^"]*"[^>]*>(.*?)</a>#s', $hit[3], $links);
            $out[] = [html_entity_decode($hit[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'), array_map(static function (string $label): string {
                return html_entity_decode($label, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }, $links[1])];
        }

        // Los enlaces sueltos seguidos se juntan en un solo grupo sin nombre.
        $merged = [];
        foreach ($out as $row) {
            $last = count($merged) - 1;
            if ($row[0] === '' && $last >= 0 && $merged[$last][0] === '') {
                $merged[$last][1] = array_merge($merged[$last][1], $row[1]);
            } else {
                $merged[] = $row;
            }
        }

        return $merged;
    }

    /**
     * El menú en una línea por grupo, para comparar y leer de un vistazo: «Ventas: Pedidos · Carritos».
     *
     * @param array<int,array{0:string,1:string[]}> $nav
     *
     * @return string[]
     */
    function menuTabsLines(array $nav): array
    {
        return array_map(static function (array $group): string {
            return $group[0] . ': ' . implode(' · ', $group[1]);
        }, $nav);
    }

    /** Las direcciones de las entradas del menú. @return string[] */
    function menuTabsNavLinks(string $html): array
    {
        $start = strpos($html, '<nav class="af-nav">');
        $end = $start !== false ? strpos($html, '</nav>', $start) : false;
        $nav = $start !== false && $end !== false ? substr($html, $start, $end - $start) : '';
        preg_match_all('#<a\b[^>]*\bhref="([^"]+)"#', $nav, $m);

        return $m[1];
    }

    /**
     * La barra de pestañas de la pantalla: nombre y pestañas [texto, dirección, seleccionada]. [] si no hay barra.
     *
     * @return array{label?:string,tabs?:array<int,array{0:string,1:string,2:bool}>}
     */
    function menuTabsBar(string $html): array
    {
        if (preg_match('#<div class="af-tabs af-pagetabs" role="tablist" aria-label="([^"]*)" data-af-pagetabs>(.*?)</div>#s', $html, $m) !== 1) {
            return [];
        }
        preg_match_all('#<a class="af-tab" role="tab" href="([^"]*)" aria-selected="(true|false)"[^>]*>(.*?)</a>#s', $m[2], $tabs, PREG_SET_ORDER);
        $out = [];
        foreach ($tabs as $tab) {
            $out[] = [html_entity_decode($tab[3], ENT_QUOTES | ENT_HTML5, 'UTF-8'), html_entity_decode($tab[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $tab[2] === 'true'];
        }

        return ['label' => html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'tabs' => $out];
    }

    /** Los textos de las pestañas, con la seleccionada entre corchetes: «Ajustes|[Clases]|Tipos». */
    function menuTabsBarText(string $html): string
    {
        $bar = menuTabsBar($html);
        if ($bar === []) {
            return '';
        }

        return $bar['label'] . ': ' . implode('|', array_map(static function (array $tab): string {
            return $tab[2] ? '[' . $tab[0] . ']' : $tab[0];
        }, $bar['tabs']));
    }

    /**
     * El menú del encargo (P102, punto 2), grupo a grupo. Formas de pago e Informes son pantallas nuevas.
     *
     * @return array<int,array{0:string,1:string[]}>
     */
    function menuTabsExpected(): array
    {
        return [
            ['', ['Inicio', 'Copiloto']],
            ['Ventas', ['Pedidos', 'Carritos', 'Facturas', 'Devoluciones', 'Informes', 'Suscripciones']],
            ['Catálogo', ['Productos', 'Categorías', 'Marcas', 'Atributos', 'Existencias y precios', 'Valoraciones', 'Productos en CSV']],
            ['Clientes', ['Clientes', 'Grupos', 'Mensajes']],
            ['Contenido', ['Páginas', 'Blog', 'Medios', 'Menús', 'Formularios', 'Páginas legales', 'Redirecciones']],
            ['Marketing', ['Cupones', 'SEO', 'Buscador', 'Medición', 'Cookies']],
            ['Inteligencia artificial', ['IA', 'Asistente', 'Auditoría AIFEC', 'Robots y asistentes', 'WebMCP']],
            ['Diseño', ['Temas', 'Portada', 'Logotipo e icono']],
            ['Ajustes', ['General', 'Formas de pago', 'Envío', 'Impuestos', 'Ventas', 'Catálogo', 'Contenido', 'Correo', 'Traducciones', 'Equipo', 'API y ERP', 'Todos los ajustes']],
            ['Sistema', ['Módulos y plugins', 'Sitios y demostraciones', 'Migración', 'Seguridad', 'Tareas', 'Diagnóstico', 'Caché y mantenimiento', 'Copias de seguridad']],
        ];
    }

    /**
     * Las direcciones de siempre (las 86 entradas del menú de antes que no son un grupo), con el permiso que las abre.
     *
     * @return array<string,string> dirección => permiso
     */
    function menuTabsOldScreens(): array
    {
        return [
            '/admin/orders' => 'orders.view', '/admin/orders/carts' => 'orders.view', '/admin/invoices' => 'invoices.view', '/admin/returns' => 'returns.view',
            '/admin/r/products' => 'catalog.view', '/admin/catalog/stock' => 'catalog.view', '/admin/r/categories' => 'catalog.view', '/admin/r/brands' => 'catalog.view',
            '/admin/r/attributes' => 'catalog.view', '/admin/r/families' => 'catalog.view', '/admin/reviews' => 'reviews.view', '/admin/tools/csv' => 'catalog.edit',
            '/admin/r/customers' => 'customers.view', '/admin/r/customer_groups' => 'customers.view',
            '/admin/r/pages' => 'content.view', '/admin/r/posts' => 'content.view', '/admin/r/post_categories' => 'content.view', '/admin/r/post_tags' => 'content.view',
            '/admin/cms/menus' => 'content.view', '/admin/r/forms' => 'content.view', '/admin/r/form_entries' => 'content.view', '/admin/r/redirects' => 'content.view',
            '/admin/cms/not-found' => 'content.view', '/admin/cms/legal' => 'content.view', '/admin/media' => 'media.view',
            '/admin/r/coupons' => 'coupons.view', '/admin/seo/audit' => 'seo.view', '/admin/settings/seo' => 'seo.edit', '/admin/search' => 'search.view',
            '/admin/r/synonyms' => 'search.view', '/admin/settings/search' => 'search.edit', '/admin/datalayer/events' => 'datalayer.view',
            '/admin/settings/datalayer' => 'datalayer.edit', '/admin/consent' => 'consent.view', '/admin/r/consent_cookies' => 'consent.view',
            '/admin/settings/consent' => 'consent.edit',
            '/admin/ai/proposals' => 'ai.use', '/admin/ai/batches' => 'ai.use', '/admin/ai/usage' => 'ai.view', '/admin/settings/ai' => 'ai.edit',
            '/admin/assistant' => 'assistant.view', '/admin/assistant/unanswered' => 'assistant.view', '/admin/settings/assistant' => 'assistant.edit',
            '/admin/semantic/audit' => 'semantic.view', '/admin/semantic/bots' => 'semantic.view', '/admin/webmcp' => 'webmcp.view', '/admin/settings/webmcp' => 'webmcp.edit',
            '/admin/themes' => 'appearance.view', '/admin/themes/home' => 'appearance.view', '/admin/themes/brand' => 'appearance.view', '/admin/themes/new' => 'appearance.create',
            '/admin/settings' => 'settings.edit', '/admin/r/users' => 'team.view', '/admin/r/roles' => 'team.view', '/admin/r/api_keys' => 'api.view',
            '/admin/settings/tax' => 'tax.edit', '/admin/r/tax_classes' => 'tax.view', '/admin/r/tax_rates' => 'tax.view', '/admin/shipping' => 'shipping.view',
            '/admin/r/order_states' => 'orders.view', '/admin/settings/orders' => 'orders.edit', '/admin/settings/invoices' => 'invoices.edit',
            '/admin/settings/returns' => 'returns.manage', '/admin/settings/catalog' => 'catalog.edit', '/admin/settings/cms' => 'content.edit',
            '/admin/settings/reviews' => 'reviews.edit', '/admin/settings/mail' => 'mail.edit', '/admin/erp' => 'erp.view', '/admin/erp/exports' => 'erp.view',
            '/admin/erp/imports' => 'erp.view', '/admin/erp/runs' => 'erp.view', '/admin/settings/erp' => 'erp.edit',
            '/admin/plugins' => 'plugins.view', '/admin/plugins/new' => 'plugins.edit', '/admin/sites' => 'sites.manage', '/admin/import' => 'import.run',
            '/admin/r/jobs' => 'jobs.view', '/admin/jobs/schedule' => 'jobs.view', '/admin/tools/doctor' => 'tools.view', '/admin/tools/logs' => 'tools.view',
            '/admin/tools/cache' => 'tools.view', '/admin/tools/backups' => 'tools.backup', '/admin/tools/info' => 'tools.view', '/admin/r/audit' => 'system.view',
            '/admin/r/mail' => 'mail.view', '/admin/mail/templates' => 'mail.view',
        ];
    }

    /**
     * El nombre de siempre de cada una (el del menú de antes, en castellano): con él la tiene que seguir encontrando el
     * buscador del panel. Dos que se llamaban igual se distinguen ahora por su pestaña.
     *
     * @return array<string,string> dirección => nombre
     */
    function menuTabsOldNames(): array
    {
        return [
            '/admin/orders' => 'Pedidos', '/admin/orders/carts' => 'Carritos', '/admin/invoices' => 'Facturas', '/admin/returns' => 'Devoluciones',
            '/admin/r/products' => 'Productos', '/admin/catalog/stock' => 'Existencias y precios', '/admin/r/categories' => 'Categorías', '/admin/r/brands' => 'Marcas',
            '/admin/r/attributes' => 'Atributos', '/admin/r/families' => 'Tipos de producto', '/admin/reviews' => 'Valoraciones', '/admin/tools/csv' => 'Productos en CSV',
            '/admin/r/customers' => 'Clientes', '/admin/r/customer_groups' => 'Grupos',
            '/admin/r/pages' => 'Páginas', '/admin/r/posts' => 'Entradas', '/admin/r/post_categories' => 'Categorías del blog', '/admin/r/post_tags' => 'Etiquetas del blog',
            '/admin/cms/menus' => 'Menús', '/admin/r/forms' => 'Formularios', '/admin/r/form_entries' => 'Mensajes de formularios', '/admin/r/redirects' => 'Redirecciones',
            '/admin/cms/not-found' => 'Sin resultado', '/admin/cms/legal' => 'Páginas legales', '/admin/media' => 'Medios',
            '/admin/r/coupons' => 'Cupones', '/admin/seo/audit' => 'SEO (Auditoría)', '/admin/settings/seo' => 'SEO (Ajustes)', '/admin/search' => 'Buscar (Consultas)',
            '/admin/r/synonyms' => 'Sinónimos', '/admin/settings/search' => 'Buscar (Ajustes)', '/admin/datalayer/events' => 'Eventos del servidor',
            '/admin/settings/datalayer' => 'Medición', '/admin/consent' => 'Consentimiento de cookies', '/admin/r/consent_cookies' => 'Cookies',
            '/admin/settings/consent' => 'Ajustes de consentimiento',
            '/admin/ai/proposals' => 'Propuestas de la IA', '/admin/ai/batches' => 'Lotes de la IA', '/admin/ai/usage' => 'Uso de la IA', '/admin/settings/ai' => 'Ajustes de la IA',
            '/admin/assistant' => 'Asistente', '/admin/assistant/unanswered' => 'Preguntas sin respuesta', '/admin/settings/assistant' => 'Ajustes del asistente',
            '/admin/semantic/audit' => 'Auditoría AIFEC', '/admin/semantic/bots' => 'Robots y asistentes', '/admin/webmcp' => 'WebMCP', '/admin/settings/webmcp' => 'Ajustes de WebMCP',
            '/admin/themes' => 'Temas', '/admin/themes/home' => 'Portada', '/admin/themes/brand' => 'Logotipo e icono', '/admin/themes/new' => 'Tema nuevo',
            '/admin/settings' => 'Todos los ajustes', '/admin/r/users' => 'Equipo', '/admin/r/roles' => 'Papeles', '/admin/r/api_keys' => 'Claves de la API',
            '/admin/settings/tax' => 'Impuestos', '/admin/r/tax_classes' => 'Clases de impuesto', '/admin/r/tax_rates' => 'Tipos de impuesto', '/admin/shipping' => 'Envío',
            '/admin/r/order_states' => 'Estados de pedido', '/admin/settings/orders' => 'Ventas', '/admin/settings/invoices' => 'Ajustes de facturas',
            '/admin/settings/returns' => 'Ajustes de devoluciones', '/admin/settings/catalog' => 'Catálogo', '/admin/settings/cms' => 'Contenido',
            '/admin/settings/reviews' => 'Ajustes de valoraciones', '/admin/settings/mail' => 'Correo electrónico', '/admin/erp' => 'Conexiones ERP', '/admin/erp/exports' => 'Exportaciones ERP',
            '/admin/erp/imports' => 'Importaciones ERP', '/admin/erp/runs' => 'Pasadas ERP', '/admin/settings/erp' => 'Ajustes ERP',
            '/admin/plugins' => 'Módulos y plugins', '/admin/plugins/new' => 'Nuevo plugin', '/admin/sites' => 'Sitios y demostraciones', '/admin/import' => 'Migración',
            '/admin/r/jobs' => 'Tareas en segundo plano', '/admin/jobs/schedule' => 'Tareas programadas', '/admin/tools/doctor' => 'Diagnóstico', '/admin/tools/logs' => 'Registro',
            '/admin/tools/cache' => 'Caché y mantenimiento', '/admin/tools/backups' => 'Copias de seguridad', '/admin/tools/info' => 'Información del sistema', '/admin/r/audit' => 'Registro de actividad',
            '/admin/r/mail' => 'Registro de correo', '/admin/mail/templates' => 'Plantillas de correo',
        ];
    }

    /**
     * Un papel con esos permisos y una persona que entra con él.
     *
     * @param string[] $caps
     */
    function menuTabsRole(App $app, string $code, array $caps): TestClient
    {
        $app->boot();
        $app->db()->insert('roles', ['code' => $code, 'name' => ucfirst($code), 'caps' => json_encode($caps), 'system' => 0]);

        return adminClient($app, $code);
    }
}

test('el menú con todos los módulos encendidos: el del encargo, grupo a grupo, sin nombres repetidos y con no más de 60 entradas', function (): void {
    $app = menuTabsApp();
    $web = adminClient($app);
    $home = $web->get('/admin');
    same(200, $home->status);
    $nav = menuTabsNav($home->body);
    same(menuTabsLines(menuTabsExpected()), menuTabsLines($nav));

    $labels = [];
    foreach ($nav as $group) {
        $labels = array_merge($labels, $group[1]);
    }
    same(58, count($labels), 'cincuenta y ocho entradas');
    check(count($labels) <= 60, 'dentro del presupuesto de 60');
    same($labels, array_values(array_unique($labels)), 'ningún nombre repetido');
    same(58, count(array_unique(menuTabsNavLinks($home->body))), 'y cada entrada lleva a un sitio distinto');
    same(0, preg_match_all('#<div class="af-nav-sub">(?:(?!</div>).)*<details#s', $home->body), 'un solo nivel');
});

test('ninguna pantalla cambia de dirección: las de siempre responden 200 y se llega a cada una desde el menú, una pestaña o el buscador', function (): void {
    $app = menuTabsApp();
    $web = adminClient($app);
    $home = $web->get('/admin');
    $reach = array_fill_keys(menuTabsNavLinks($home->body), 'menú');
    $failed = [];
    foreach (array_keys(menuTabsOldScreens()) as $path) {
        $r = $web->get($path);
        for ($hop = 0; $hop < 3 && $r->isRedirect(); $hop++) {
            $r = $web->follow();
        }
        if ($r->status !== 200) {
            $failed[] = $path . ' → ' . $r->status;
            continue;
        }
        $bar = menuTabsBar($r->body);
        foreach ($bar !== [] ? $bar['tabs'] : [] as $tab) {
            $reach += [$tab[1] => 'pestaña'];
        }
    }
    same([], $failed, 'todas abren');

    $lost = [];
    $names = menuTabsOldNames();
    same(array_keys(menuTabsOldScreens()), array_keys($names), 'un nombre por pantalla');
    foreach (array_keys(menuTabsOldScreens()) as $path) {
        $web->get('/admin/palette.json?q=' . rawurlencode($names[$path]));
        $found = '';
        foreach ($web->json()['items'] ?? [] as $item) {
            if ($item['url'] === $path) {
                $found = (string) $item['label'];
            }
        }
        if ($found !== $names[$path]) {
            $lost[] = $path . ' (el buscador da «' . $found . '» con «' . $names[$path] . '»)';
        }
        if (!isset($reach[$path]) && !in_array($path, ['/admin/plugins/new', '/admin/themes/new'], true)) {
            $lost[] = $path . ' (ni menú ni pestaña)';
        }
    }
    same([], $lost, 'a todas se llega, y el buscador las encuentra por su nombre de siempre');
    notContains('/admin/plugins/new', implode(' ', menuTabsNavLinks($home->body)), 'lo que pasa a ser un botón ya no es una entrada del menú');
    notContains('/admin/themes/new', implode(' ', menuTabsNavLinks($home->body)));
    contains('/admin/plugins/new', $web->get('/admin/plugins')->body, 'es el botón de su pantalla');
    contains('/admin/themes/new', $web->get('/admin/themes')->body);

    // Con su permiso de siempre: quien no lo tiene sigue sin poder abrirlas.
    $nobody = menuTabsRole($app, 'nadie', ['dashboard.view']);
    $open = [];
    foreach (array_keys(menuTabsOldScreens()) as $path) {
        if ($nobody->get($path)->status !== 403) {
            $open[] = $path;
        }
    }
    // «Todos los ajustes» se abría ya sin permiso (enseña solo las tarjetas que cada cual puede ver); su entrada pide settings.edit.
    same(['/admin/settings'], $open, 'sin permisos, todas las demás dan 403');
    contains('Aquí no hay nada que configurar', $nobody->get('/admin/settings')->body, 'y esa sale sin ninguna tarjeta');
    same([': Inicio'], menuTabsLines(menuTabsNav($nobody->get('/admin')->body)), 'y el menú solo trae el inicio');
});

test('cada conjunto pinta su barra de pestañas bajo el título, con la primera marcada al entrar por el menú', function (): void {
    $app = menuTabsApp();
    $web = adminClient($app);
    $home = $web->get('/admin')->body;
    $expected = [
        'Atributos: [Atributos]|Tipos de producto',
        'Valoraciones: [Valoraciones]|Ajustes',
        'Blog: [Entradas]|Categorías|Etiquetas',
        'Redirecciones: [Redirecciones]|Sin resultado',
        'SEO: [Auditoría]|Ajustes',
        'Buscador: [Consultas]|Sinónimos|Ajustes|Probar y aprender',
        'Medición: [Ajustes]|Eventos del servidor',
        'Cookies: [Consentimiento]|Cookies|Ajustes',
        'IA: [Propuestas]|Lotes|Uso|Ajustes|Prueba de conexión',
        'Asistente: [Conversaciones]|Preguntas sin respuesta|Ajustes',
        'WebMCP: [WebMCP]|Ajustes',
        'Impuestos: [Ajustes]|Clases|Tipos',
        'Ventas: [Ajustes]|Estados de pedido|Facturas|Devoluciones',
        'Suscripciones: [Suscripciones]|Planes mensuales|Ajustes',
        'Correo: [Ajustes]|Plantillas|Registro de envíos',
        'Equipo: [Personas]|Papeles',
        'API y ERP: [Claves de la API]|Conexiones|Exportaciones|Importaciones|Pasadas|Ajustes',
        'Seguridad: [Auditoría]|Passkeys|Ajustes',
        'Tareas: [En segundo plano]|Programadas',
        'Diagnóstico: [Diagnóstico]|Información del sistema|Registro|Registro de actividad',
    ];
    $bars = [];
    foreach (menuTabsNavLinks($home) as $href) {
        $r = $web->get($href);
        for ($hop = 0; $hop < 3 && $r->isRedirect(); $hop++) {
            $r = $web->follow();
        }
        $text = menuTabsBarText($r->body);
        if ($text !== '') {
            $bars[] = $text;
            $bar = menuTabsBar($r->body);
            same($href, $bar['tabs'][0][1], 'la entrada del menú lleva a la primera pestaña (' . $text . ')');
        }
    }
    sort($expected);
    sort($bars);
    same($expected, $bars, 'veinte conjuntos y solo esos');
});

test('la pestaña de la pantalla en la que se está va marcada, y su entrada del menú también', function (): void {
    $app = menuTabsApp();
    $web = adminClient($app);
    $cases = [
        '/admin/r/tax_classes' => 'Impuestos: Ajustes|[Clases]|Tipos',
        '/admin/r/tax_rates' => 'Impuestos: Ajustes|Clases|[Tipos]',
        '/admin/settings/tax' => 'Impuestos: [Ajustes]|Clases|Tipos',
        '/admin/erp/runs' => 'API y ERP: Claves de la API|Conexiones|Exportaciones|Importaciones|[Pasadas]|Ajustes',
        '/admin/erp' => 'API y ERP: Claves de la API|[Conexiones]|Exportaciones|Importaciones|Pasadas|Ajustes',
        '/admin/r/audit' => 'Diagnóstico: Diagnóstico|Información del sistema|Registro|[Registro de actividad]',
        '/admin/tools/logs' => 'Diagnóstico: Diagnóstico|Información del sistema|[Registro]|Registro de actividad',
        '/admin/r/roles' => 'Equipo: Personas|[Papeles]',
        '/admin/assistant/unanswered' => 'Asistente: Conversaciones|[Preguntas sin respuesta]|Ajustes',
        '/admin/ai/connection' => 'IA: Propuestas|Lotes|Uso|Ajustes|[Prueba de conexión]',
        '/admin/r/subscription_plans' => 'Suscripciones: Suscripciones|[Planes mensuales]|Ajustes',
        '/admin/settings/subscriptions' => 'Suscripciones: Suscripciones|Planes mensuales|[Ajustes]',
        '/admin/settings/invoices' => 'Ventas: Ajustes|Estados de pedido|[Facturas]|Devoluciones',
        '/admin/r/mail' => 'Correo: Ajustes|Plantillas|[Registro de envíos]',
        '/admin/cms/not-found' => 'Redirecciones: Redirecciones|[Sin resultado]',
    ];
    foreach ($cases as $path => $bar) {
        $r = $web->get($path);
        same(200, $r->status, $path);
        same($bar, menuTabsBarText($r->body), $path);
        $selected = menuTabsBar($r->body)['tabs'];
        $on = array_values(array_filter($selected, static function (array $tab): bool {
            return $tab[2];
        }));
        same(1, count($on), 'una sola pestaña marcada en ' . $path);
        same($path, $on[0][1], 'y es la de esta dirección');
        $markup = substr($r->body, (int) strpos($r->body, 'data-af-pagetabs'));
        same(1, substr_count(substr($markup, 0, (int) strpos($markup, '</div>')), 'aria-selected="true"'), 'en la barra, una sola con aria-selected="true" en ' . $path);
        $label = substr($bar, 0, (int) strpos($bar, ':'));
        check(preg_match('#<a href="[^"]*" aria-current="page">' . preg_quote(e($label), '#') . '</a>#', $r->body) === 1, 'la entrada «' . $label . '» del menú va marcada en ' . $path);
    }
    same('', menuTabsBarText($web->get('/admin/orders')->body), 'una pantalla suelta no lleva barra');
    same('', menuTabsBarText($web->get('/admin/r/form_entries')->body), 'tampoco la que solo cambia de grupo');
    same('', menuTabsBarText($web->get('/admin')->body));
});

test('la pestaña se conserva al guardar un ajuste: se vuelve a la misma pantalla y a la misma pestaña', function (): void {
    $app = menuTabsApp();
    $web = adminClient($app);
    $page = $web->get('/admin/settings/invoices');
    same('Ventas: Ajustes|Estados de pedido|[Facturas]|Devoluciones', menuTabsBarText($page->body));
    $r = $web->post('/admin/settings/invoices', panelKitForm($page->body, '/admin/settings/invoices'));
    same(302, $r->status, 'se guarda');
    same('/admin/settings/invoices', (string) preg_replace('/#.*$/', '', $r->headers['Location']), 'vuelve a su pantalla');
    $back = $web->follow();
    contains('Ajustes guardados', $back->body);
    same('Ventas: Ajustes|Estados de pedido|[Facturas]|Devoluciones', menuTabsBarText($back->body), 'con su pestaña marcada, no la primera');

    $search = $web->get('/admin/settings/search');
    $r = $web->post('/admin/settings/search', panelKitForm($search->body, '/admin/settings/search'));
    same(302, $r->status);
    same('Buscador: Consultas|Sinónimos|[Ajustes]|Probar y aprender', menuTabsBarText($web->follow()->body));
});

test('al filtrar, paginar, ordenar o abrir una ficha de un listado, su pestaña sigue marcada', function (): void {
    $app = menuTabsApp();
    $web = adminClient($app);
    $classId = (int) $app->db()->value('SELECT MIN(id) FROM {tax_classes}');
    check($classId > 0, 'hay clases de impuesto sembradas');
    $cases = [
        '/admin/r/tax_rates?q=IVA&sort=name&dir=desc&page=2' => 'Impuestos: Ajustes|Clases|[Tipos]',
        '/admin/r/tax_classes/new' => 'Impuestos: Ajustes|[Clases]|Tipos',
        '/admin/r/tax_classes/' . $classId => 'Impuestos: Ajustes|[Clases]|Tipos',
        '/admin/ai/proposals?f%5Bstatus%5D=pending' => 'IA: [Propuestas]|Lotes|Uso|Ajustes|Prueba de conexión',
        '/admin/erp/runs?page=3' => 'API y ERP: Claves de la API|Conexiones|Exportaciones|Importaciones|[Pasadas]|Ajustes',
        '/admin/r/synonyms?q=sof%C3%A1' => 'Buscador: Consultas|[Sinónimos]|Ajustes|Probar y aprender',
        '/admin/search/learning' => 'Buscador: Consultas|Sinónimos|Ajustes|[Probar y aprender]',
    ];
    foreach ($cases as $path => $bar) {
        $r = $web->get($path);
        same(200, $r->status, $path);
        same($bar, menuTabsBarText($r->body), $path);
    }
});

test('un papel sin permiso de una pestaña no la ve, y su dirección sigue dando 403', function (): void {
    $app = menuTabsApp();
    $tax = menuTabsRole($app, 'taxview', ['tax.view']);
    $r = $tax->get('/admin/r/tax_classes');
    same(200, $r->status);
    same('Impuestos: [Clases]|Tipos', menuTabsBarText($r->body), 'sin «Ajustes»: no puede cambiarlos');
    notContains('/admin/settings/tax', $r->body);
    same(403, $tax->get('/admin/settings/tax')->status, 'y su dirección sigue cerrada');
    same(['Ajustes: Impuestos'], array_slice(menuTabsLines(menuTabsNav($r->body)), 1), 'en el menú, solo lo suyo');

    $erp = menuTabsRole($app, 'erpview', ['erp.view']);
    $r = $erp->get('/admin/erp/exports');
    same('API y ERP: Conexiones|[Exportaciones]|Importaciones|Pasadas', menuTabsBarText($r->body));
    same(403, $erp->get('/admin/r/api_keys')->status);
    same(403, $erp->get('/admin/settings/erp')->status);

    // Con una sola pantalla del conjunto a la vista, la entrada lleva el nombre de esa pantalla y no hay barra.
    $events = menuTabsRole($app, 'measure', ['datalayer.view']);
    $r = $events->get('/admin/datalayer/events');
    same(200, $r->status);
    same('', menuTabsBarText($r->body));
    same(['Marketing: Eventos del servidor'], array_slice(menuTabsLines(menuTabsNav($r->body)), 1));
    same(403, $events->get('/admin/settings/datalayer')->status);
});

test('con el módulo de impuestos apagado no salen ni su entrada ni sus pestañas, y el resto del menú no cambia', function (): void {
    $without = menuTabsApp(['mail', 'jobs']);
    $with = menuTabsApp(['mail', 'jobs', 'tax']);
    $web = adminClient($without);
    $off = $web->get('/admin')->body;
    $on = adminClient($with)->get('/admin')->body;
    // Sin el módulo de herramientas, «Diagnóstico» se queda en el registro de actividad: entrada con su propio nombre.
    $rest = ['Ajustes: General · Correo · Equipo · Todos los ajustes', 'Sistema: Tareas · Registro de actividad'];
    same($rest, array_slice(menuTabsLines(menuTabsNav($off)), 1));
    $rest[0] = 'Ajustes: General · Impuestos · Correo · Equipo · Todos los ajustes';
    same($rest, array_slice(menuTabsLines(menuTabsNav($on)), 1), 'encendido, la misma lista con «Impuestos» en su sitio');
    $mail = $web->get('/admin/settings/mail')->body;
    same('Correo: [Ajustes]|Plantillas|Registro de envíos', menuTabsBarText($mail), 'las barras de los demás no cambian');
    foreach (['/admin/settings/tax', '/admin/r/tax_classes', '/admin/r/tax_rates'] as $path) {
        notContains($path, $off);
        notContains($path, $mail);
    }
    same(404, $web->get('/admin/settings/tax')->status, 'la pantalla no existe');
});

test('con el ERP apagado, «API y ERP» se queda en las claves de la API, sin barra, y lo demás sigue igual', function (): void {
    $app = menuTabsApp(menuTabsSlugs(['erp']));
    $web = adminClient($app);
    $home = $web->get('/admin')->body;
    $expected = menuTabsLines(menuTabsExpected());
    $expected[8] = str_replace('API y ERP', 'Claves de la API', $expected[8]);
    same($expected, menuTabsLines(menuTabsNav($home)));
    notContains('/admin/erp', $home);
    $keys = $web->get('/admin/r/api_keys');
    same(200, $keys->status);
    same('', menuTabsBarText($keys->body));
    notContains('/admin/erp', $keys->body);
});

test('con el asistente apagado (y lo que lo necesita) no sale nada suyo y el resto del grupo sigue en su orden', function (): void {
    $app = menuTabsApp(menuTabsSlugs(['assistant', 'api', 'mcp', 'webmcp']));
    $web = adminClient($app);
    $home = $web->get('/admin')->body;
    $expected = menuTabsLines(menuTabsExpected());
    $expected[6] = 'Inteligencia artificial: IA · Auditoría AIFEC · Robots y asistentes';
    same($expected, menuTabsLines(menuTabsNav($home)));
    notContains('/admin/assistant', $home);
    same('API y ERP: [Conexiones]|Exportaciones|Importaciones|Pasadas|Ajustes', menuTabsBarText($web->get('/admin/erp')->body), 'sin la pestaña de las claves');
    same('IA: [Propuestas]|Lotes|Uso|Ajustes|Prueba de conexión', menuTabsBarText($web->get('/admin/ai/proposals')->body));
});

test('un plugin con una entrada «a la antigua» sigue saliendo igual, y uno que declara pestañas las tiene', function (): void {
    $app = testApp();
    aifecAdmin($app);
    $app->hooks()->filter('admin.definitions', static function (array $definitions): array {
        $list = ['columns' => ['id' => ['label' => 'Id']]];
        $definitions['plugtest'] = [
            'menu' => [['group' => 'plugins', 'items' => [
                ['label' => 'Old screen', 'resource' => 'pt_old', 'cap' => 'system.view', 'order' => 10],
                ['label' => 'Tips', 'resource' => 'pt_tips', 'cap' => 'system.view', 'order' => 20, 'tabs' => 'pt', 'menu_label' => 'Product tips'],
                ['label' => 'Tip report', 'resource' => 'pt_report', 'cap' => 'system.view', 'order' => 21, 'tabs' => 'pt', 'tab' => 'Report', 'menu_label' => 'Product tips'],
            ]]],
            'resources' => [
                'pt_old' => ['table' => 'audit', 'title' => 'Old screen', 'cap' => 'system', 'readonly' => true, 'list' => $list],
                'pt_tips' => ['table' => 'roles', 'title' => 'Tips', 'cap' => 'system', 'readonly' => true, 'list' => $list],
                'pt_report' => ['table' => 'audit', 'title' => 'Tip report', 'cap' => 'system', 'readonly' => true, 'list' => $list],
            ],
        ];

        return $definitions;
    });
    $web = new TestClient($app);
    aifecLogin($web);
    $home = $web->get('/admin')->body;
    contains('Módulos y plugins: Old screen · Product tips', implode("\n", menuTabsLines(menuTabsNav($home))));
    $old = $web->get('/admin/r/pt_old');
    same(200, $old->status);
    same('', menuTabsBarText($old->body), 'la entrada de siempre no lleva barra');
    contains('<a href="/admin/r/pt_old" aria-current="page">Old screen</a>', $old->body);
    same('Product tips: Tips|[Report]', menuTabsBarText($web->get('/admin/r/pt_report')->body));
    same('Product tips: [Tips]|Report', menuTabsBarText($web->get('/admin/r/pt_tips')->body));
    contains('<a href="/admin/r/pt_tips" aria-current="page">Product tips</a>', $web->get('/admin/r/pt_report')->body, 'la entrada del conjunto se marca desde cualquiera de sus pestañas');
});

test('el buscador del panel encuentra por su nombre de siempre una pantalla que ahora es una pestaña o un botón', function (): void {
    $app = menuTabsApp();
    $web = adminClient($app);
    $find = static function (string $q) use ($web): array {
        $out = [];
        $web->get('/admin/palette.json?q=' . rawurlencode($q));
        foreach ($web->json()['items'] ?? [] as $item) {
            $out[$item['label']] = $item['url'];
        }

        return $out;
    };
    same('/admin/r/tax_classes', $find('clases de impuesto')['Clases de impuesto'] ?? '');
    same('/admin/r/mail', $find('registro de correo')['Registro de correo'] ?? '');
    same('/admin/erp/runs', $find('pasadas')['Pasadas ERP'] ?? '');
    same('/admin/settings/returns', $find('ajustes de devoluciones')['Ajustes de devoluciones'] ?? '');
    same('/admin/plugins/new', $find('nuevo plugin')['Nuevo plugin'] ?? '');
    $seo = $find('seo');
    same('/admin/seo/audit', $seo['SEO (Auditoría)'] ?? '', 'dos pantallas con el mismo nombre se distinguen por su pestaña');
    same('/admin/settings/seo', $seo['SEO (Ajustes)'] ?? '');
    same('/admin/r/form_entries', $find('mensajes de formularios')['Mensajes de formularios'] ?? '');
});

test('la barra de pestañas es accesible y funciona sin JavaScript: enlaces con role="tab", flechas en el guion y sin desbordar en móvil', function (): void {
    $app = menuTabsApp();
    $web = adminClient($app);
    $body = $web->get('/admin/r/tax_classes')->body;
    check(preg_match('#<div class="af-tabs af-pagetabs" role="tablist" aria-label="Impuestos" data-af-pagetabs>#', $body) === 1, 'una lista de pestañas con nombre');
    same(3, preg_match_all('#<a class="af-tab" role="tab" href="/admin/[^"]+" aria-selected="(?:true|false)"#', $body), 'cada pestaña es un enlace de verdad');
    contains('aria-selected="true" aria-current="page">Clases</a>', $body);
    $head = strpos($body, '<div class="af-head">');
    $bar = strpos($body, 'data-af-pagetabs');
    check($head !== false && $bar !== false && $bar > $head, 'la barra va bajo el título');
    notContains('onclick', $body);
    same(1, preg_match_all('#<script>#', $body), 'el único guion escrito en la página es el del tema claro u oscuro');

    $js = (string) file_get_contents(Autoload::root() . '/core/assets/admin/admin.js');
    contains("on('keydown', '[data-af-pagetabs] [role=\"tab\"]'", $js);
    foreach (["'ArrowRight'", "'ArrowLeft'", "'Home'", "'End'"] as $key) {
        contains($key, substr($js, (int) strpos($js, '[data-af-pagetabs] [role="tab"]'), 700), 'el guion de las pestañas atiende ' . $key);
    }
    $css = (string) file_get_contents(Autoload::root() . '/core/assets/admin/admin.css');
    contains('.af-tabs { display: flex; flex-wrap: wrap;', $css, 'si no caben pasan a otra línea: nunca se desplazan de lado');
    contains('.af-pagetabs { max-width: 100%;', $css);
    contains('.af-tab { display: inline-flex; align-items: center; min-height: var(--tap);', $css, 'pestañas de buen tamaño para el dedo (44 px al tacto)');
});

test('Menu::tabs solo da barra si la entrada marcada agrupa dos pantallas o más', function (): void {
    $one = [['items' => [['label' => 'A', 'active' => true, 'tabs' => [['label' => 'x', 'path' => '/a', 'url' => '/a', 'active' => true]]]]]];
    same([], Menu::tabs($one));
    $two = [['items' => [
        ['label' => 'Sin marcar', 'active' => false, 'tabs' => [['label' => 'x', 'path' => '/x', 'url' => '/x', 'active' => false], ['label' => 'y', 'path' => '/y', 'url' => '/y', 'active' => false]]],
        ['label' => 'B', 'active' => true, 'tabs' => [['label' => 'p', 'path' => '/p', 'url' => '/p', 'active' => false], ['label' => 'q', 'path' => '/q', 'url' => '/q', 'active' => true]]],
    ]]];
    $bar = Menu::tabs($two);
    same('B', $bar['label']);
    same(['p', 'q'], array_column($bar['items'], 'label'));
    same([], Menu::tabs([]));
    same([], Menu::tabs([['items' => []]]));
});

test('una pantalla que ahora es un botón marca la entrada de la pantalla desde la que se llega', function (): void {
    $app = menuTabsApp();
    $web = adminClient($app);
    contains('<a href="/admin/plugins" aria-current="page">Módulos y plugins</a>', $web->get('/admin/plugins/new')->body);
    contains('<a href="/admin/themes" aria-current="page">Temas</a>', $web->get('/admin/themes/new')->body);
});

test('una pantalla de ajustes sin entrada propia marca «Todos los ajustes» y no lleva barra', function (): void {
    $app = menuTabsApp();
    $web = adminClient($app);
    $r = $web->get('/admin/settings/mcp');
    same(200, $r->status);
    contains('<a href="/admin/settings" aria-current="page">Todos los ajustes</a>', $r->body);
    same('', menuTabsBarText($r->body));
    contains('<a href="/admin/settings/tax" aria-current="page">Impuestos</a>', $web->get('/admin/settings/tax')->body, 'la que tiene entrada, marca la suya');
});

test('el inicio no lleva barra y la entrada marcada es «Inicio»; la página de entrada no lleva ni menú ni barra', function (): void {
    $app = menuTabsApp();
    $web = adminClient($app);
    $home = $web->get('/admin')->body;
    contains('<a class="af-nav-link" href="/admin" aria-current="page">', $home);
    notContains('data-af-pagetabs', $home);
    $login = (new TestClient($app))->get('/admin/login')->body;
    notContains('data-af-pagetabs', $login);
    notContains('<nav class="af-nav">', $login);
});

test('los nombres de un conjunto y de sus pestañas salen escapados', function (): void {
    $app = testApp();
    aifecAdmin($app);
    $app->hooks()->filter('admin.definitions', static function (array $definitions): array {
        $list = ['columns' => ['id' => ['label' => 'Id']]];
        $definitions['plugesc'] = [
            'menu' => [['group' => 'plugins', 'items' => [
                ['label' => 'Uno', 'resource' => 'pe_one', 'cap' => 'system.view', 'order' => 1, 'tabs' => 'pe', 'tab' => 'Tips & <b>tricks</b>', 'menu_label' => 'Mix & "más"'],
                ['label' => 'Dos', 'resource' => 'pe_two', 'cap' => 'system.view', 'order' => 2, 'tabs' => 'pe', 'menu_label' => 'Mix & "más"'],
            ]]],
            'resources' => [
                'pe_one' => ['table' => 'audit', 'title' => 'Uno', 'cap' => 'system', 'readonly' => true, 'list' => $list],
                'pe_two' => ['table' => 'roles', 'title' => 'Dos', 'cap' => 'system', 'readonly' => true, 'list' => $list],
            ],
        ];

        return $definitions;
    });
    $web = new TestClient($app);
    aifecLogin($web);
    $body = $web->get('/admin/r/pe_two')->body;
    contains('aria-label="Mix &amp; &quot;más&quot;"', $body);
    contains('>Tips &amp; &lt;b&gt;tricks&lt;/b&gt;</a>', $body);
    notContains('<b>tricks</b>', $body);
    contains('>Mix &amp; &quot;más&quot;</a>', $body, 'y la entrada del menú también');
});

test('un conjunto repartido entre módulos: con devoluciones apagado, «Ventas» de Ajustes sigue con sus otras pestañas', function (): void {
    $app = menuTabsApp(menuTabsSlugs(['returns']));
    $web = adminClient($app);
    same('Ventas: [Ajustes]|Estados de pedido|Facturas', menuTabsBarText($web->get('/admin/settings/orders')->body));
    $expected = menuTabsLines(menuTabsExpected());
    $expected[1] = 'Ventas: Pedidos · Carritos · Facturas · Informes · Suscripciones';
    same($expected, menuTabsLines(menuTabsNav($web->get('/admin')->body)));
});

test('quien solo ve las herramientas tiene «Diagnóstico» sin el registro de actividad, que le sigue dando 403', function (): void {
    $app = menuTabsApp();
    $web = menuTabsRole($app, 'tecnico', ['tools.view']);
    same('Diagnóstico: Diagnóstico|[Información del sistema]|Registro', menuTabsBarText($web->get('/admin/tools/info')->body));
    same(403, $web->get('/admin/r/audit')->status);
    same(['Sistema: Diagnóstico · Caché y mantenimiento'], array_slice(menuTabsLines(menuTabsNav($web->get('/admin')->body)), 1));
});

test('quien solo ve pedidos tiene Ventas con sus informes y, en Ajustes, los estados de pedido con su propio nombre', function (): void {
    $app = menuTabsApp();
    $web = menuTabsRole($app, 'pedidos', ['orders.view']);
    same(['Ventas: Pedidos · Carritos · Informes', 'Ajustes: Estados de pedido'], array_slice(menuTabsLines(menuTabsNav($web->get('/admin')->body)), 1));
    same('', menuTabsBarText($web->get('/admin/r/order_states')->body), 'una sola pantalla del conjunto: sin barra');
    same(403, $web->get('/admin/payments')->status);
});

test('el buscador distingue por su pestaña dos pantallas que se llaman igual, y deja con su nombre las demás', function (): void {
    $app = menuTabsApp();
    $web = adminClient($app);
    $web->get('/admin/palette.json?q=buscar');
    $labels = array_column($web->json()['items'] ?? [], 'url', 'label');
    same('/admin/search', $labels['Buscar (Consultas)'] ?? '');
    same('/admin/settings/search', $labels['Buscar (Ajustes)'] ?? '');
    $web->get('/admin/palette.json?q=papeles');
    // Lo primero son las pantallas del menú; detrás, lo que añaden los módulos (la ayuda, por ejemplo).
    same(['label' => 'Papeles', 'group' => 'Ajustes', 'url' => '/admin/r/roles'], ($web->json()['items'] ?? [[]])[0]);
});
