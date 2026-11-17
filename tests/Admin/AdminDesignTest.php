<?php
/**
 * AIFEC — pruebas del sistema de diseño del panel: las tres medidas (5 colores, 8 espacios, 5 tamaños de letra), sin
 * degradados ni sombras, el contraste de la paleta, lo que se pulsa con el dedo, las tablas que pasan a tarjetas y los
 * mínimos de accesibilidad (WCAG 2.2 AA) que se pueden comprobar leyendo las hojas y las plantillas.
 *
 * Nace del encargo del usuario del 13/11/2026: «define un conjunto de espaciados, un conjunto de tamaños de fuente y como
 * máximo 5 variables de color; usa solo estos para rehacerlo todo, no se permite usar gradientes», «área de clic desde
 * 44 px», «la tabla por debajo de 768 se cambia a tarjetas apiladas, no se permite el desplazamiento horizontal» y
 * «pásalo por WCAG 2.2 AA». Lo que solo se ve con un navegador lo mide `fabrica/panel_ux.py`.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Autoload;

/** Las hojas que viste el panel: la del núcleo, la de sus piezas y las de los módulos que pintan pantallas de administración. */
const DESIGN_SHEETS = [
    'core/assets/admin/admin.css',
    'core/assets/admin/widgets.css',
    'modules/cms/assets/blocks-editor.css',
    'modules/cms/assets/menus.css',
    'modules/media/assets/media.css',
    'modules/catalog/assets/category-picker.css',
    'modules/import/assets/import.css',
    'modules/copilot/assets/copilot.css',
    'modules/themes/assets/admin.css',
    'modules/orders/assets/admin-manual-order.css',
];

const DESIGN_COLORS = ['bg', 'surface', 'ink', 'muted', 'accent'];

function designCss(string $relative): string
{
    $css = (string) file_get_contents(Autoload::root() . '/' . $relative);

    return (string) preg_replace('#/\*.*?\*/#s', '', $css);
}

/** @return array<string,string> las variables de un bloque `selector { … }` */
function designVars(string $css, string $selector): array
{
    if (preg_match('#' . preg_quote($selector, '#') . '\s*\{([^}]*)\}#', $css, $m) !== 1) {
        return [];
    }
    preg_match_all('#--([a-z0-9-]+)\s*:\s*([^;]+);#', $m[1], $all, PREG_SET_ORDER);
    $vars = [];
    foreach ($all as $one) {
        $vars[$one[1]] = trim($one[2]);
    }

    return $vars;
}

function designLuminance(string $hex): float
{
    $hex = ltrim($hex, '#');
    $sum = 0.0;
    foreach ([0.2126, 0.7152, 0.0722] as $i => $weight) {
        $c = hexdec(substr($hex, $i * 2, 2)) / 255;
        $sum += $weight * ($c <= 0.03928 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4));
    }

    return $sum;
}

function designContrast(string $a, string $b): float
{
    $x = designLuminance($a);
    $y = designLuminance($b);

    return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
}

/** @return array<int,array{0:string,1:string}> las declaraciones «propiedad: valor» de una hoja, sin las de las variables */
function designDeclarations(string $css): array
{
    preg_match_all('#(?<![a-zA-Z-])([a-z-]+)\s*:\s*([^;{}]+)[;}]#', $css, $all, PREG_SET_ORDER);
    $out = [];
    foreach ($all as $one) {
        if (strpos($one[1], '--') !== 0 && substr($one[0], 0, 2) !== '--') {
            $out[] = [$one[1], trim($one[2])];
        }
    }

    return $out;
}

test('la paleta del panel son 5 colores, ni uno más, en el tema claro y en el oscuro', function (): void {
    $css = designCss('core/assets/admin/admin.css');
    foreach ([':root' => 'claro', ':root[data-theme="dark"]' => 'oscuro'] as $selector => $theme) {
        $vars = designVars($css, $selector);
        $colors = [];
        foreach ($vars as $name => $value) {
            if (preg_match('/#[0-9a-f]{3,8}\b|rgba?\(|hsla?\(|color-mix\(/i', $value) === 1) {
                $colors[] = $name;
            }
        }
        same(DESIGN_COLORS, $colors, 'tema ' . $theme . ': las variables de color son exactamente bg, surface, ink, muted y accent');
        foreach (DESIGN_COLORS as $name) {
            check(preg_match('/^#[0-9a-f]{6}$/', (string) $vars[$name]) === 1, 'tema ' . $theme . ': --' . $name . ' es un color de seis cifras');
        }
    }
    same(DESIGN_COLORS, array_keys(designVars($css, ':root[data-theme="dark"]')), 'el tema oscuro solo cambia el valor de los 5 colores');
});

test('ninguna hoja del panel escribe un color fuera de la paleta, ni un degradado, ni una sombra', function (): void {
    foreach (DESIGN_SHEETS as $sheet) {
        $css = designCss($sheet);
        $rules = (string) preg_replace('#:root(\[data-theme="dark"\])?\s*\{[^}]*\}#', '', $css);
        same(0, preg_match_all('/#[0-9a-fA-F]{3,8}\b|rgba?\(|hsla?\(/', $rules), $sheet . ': hay un color escrito a mano');
        notContains('gradient(', $css, $sheet . ': hay un degradado');
        preg_match_all('#var\(--([a-z0-9-]+)\)#', $css, $used);
        foreach (array_unique($used[1]) as $name) {
            check(
                in_array($name, DESIGN_COLORS, true) || preg_match('/^(s[0-7]|f[1-5]|radius|radius-sm|sidebar|top|tap|tap-sm|font|mono|[a-z]+-[a-z0-9-]+)$/', $name) === 1,
                $sheet . ': usa la variable --' . $name . ', que no es del sistema'
            );
            check(
                !in_array($name, ['surface-2', 'surface-3', 'ink-2', 'line', 'line-2', 'accent-ink', 'accent-soft', 'accent-hover', 'ok', 'ok-soft', 'warn', 'warn-soft', 'bad', 'bad-soft', 'info', 'info-soft', 'focus', 'shadow', 'brand', 'brand-ink', 'danger-bg', 'danger-ink'], true),
                $sheet . ': usa la variable antigua --' . $name . ' (ya no existe: la regla se queda sin color)'
            );
        }
        foreach (designDeclarations($css) as $d) {
            if ($d[0] !== 'box-shadow' || $d[1] === 'none') {
                continue;
            }
            // Solo anillos y marcas (desenfoque 0): «inset 3px 0 0 color» o «0 0 0 1px color». Una sombra de verdad difumina.
            foreach (explode(',', (string) preg_replace('#\([^)]*\)#', '', $d[1])) as $layer) {
                preg_match_all('/(?<![a-z-])-?\d*\.?\d+(?:px|rem|em|vmax)?(?![a-z0-9%-])/', $layer, $lengths);
                $blur = isset($lengths[0][2]) ? (float) $lengths[0][2] : 0.0;
                same(0.0, $blur, $sheet . ': «box-shadow: ' . $d[1] . '» es una sombra difuminada');
            }
        }
    }
});

test('los tamaños de letra y los espacios salen de sus dos juegos: 5 tamaños y 8 espacios', function (): void {
    $root = designVars(designCss('core/assets/admin/admin.css'), ':root');
    $sizes = array_values(array_filter(array_keys($root), static function (string $name): bool {
        return preg_match('/^f\d$/', $name) === 1;
    }));
    $spaces = array_values(array_filter(array_keys($root), static function (string $name): bool {
        return preg_match('/^s\d$/', $name) === 1;
    }));
    same(['f1', 'f2', 'f3', 'f4', 'f5'], $sizes, 'cinco tamaños de letra');
    same(['s0', 's1', 's2', 's3', 's4', 's5', 's6', 's7'], $spaces, 'ocho espacios');
    $previous = 0.0;
    foreach (array_merge($sizes, []) as $name) {
        check((float) $root[$name] > $previous, '--' . $name . ' es mayor que el anterior');
        $previous = (float) $root[$name];
    }
    $spacing = '/^(padding|margin|gap|row-gap|column-gap)(-(top|right|bottom|left|inline|block)(-(start|end))?)?$/';
    foreach (DESIGN_SHEETS as $sheet) {
        foreach (designDeclarations(designCss($sheet)) as $d) {
            if ($d[0] === 'font-size') {
                // El único porcentaje es el del documento (la base de la que salen los cinco): 93,75 % en escritorio y 100 % al tacto.
                check(preg_match('/^(var\(--f[1-5]\)|inherit|0|93\.75%|100%)$/', $d[1]) === 1, $sheet . ': «font-size: ' . $d[1] . '» no es uno de los 5 tamaños');
            } elseif (preg_match($spacing, $d[0]) === 1 && strpos($d[1], 'calc(') === false) {
                foreach (preg_split('/\s+/', str_replace('!important', '', $d[1])) ?: [] as $token) {
                    check(
                        $token === '' || preg_match('/^(0|auto|inherit|1px|var\(--s[0-7]\)|\d+%)$/', $token) === 1,
                        $sheet . ': «' . $d[0] . ': ' . $d[1] . '» lleva un espacio que no es del juego (' . $token . ')'
                    );
                }
            }
        }
    }
});

test('contraste de la paleta: el texto llega a 4,5:1 y los bordes de los campos a 3:1, en claro y en oscuro', function (): void {
    $css = designCss('core/assets/admin/admin.css');
    foreach ([':root' => 'claro', ':root[data-theme="dark"]' => 'oscuro'] as $selector => $theme) {
        $c = designVars($css, $selector);
        $text = [['ink', 'surface'], ['ink', 'bg'], ['muted', 'surface'], ['muted', 'bg'], ['accent', 'surface'], ['accent', 'bg'], ['surface', 'accent'], ['surface', 'ink'], ['bg', 'ink']];
        foreach ($text as $pair) {
            $ratio = designContrast((string) $c[$pair[0]], (string) $c[$pair[1]]);
            check($ratio >= 4.5, 'tema ' . $theme . ': --' . $pair[0] . ' sobre --' . $pair[1] . ' da ' . number_format($ratio, 2) . ':1 (mínimo 4,5)');
        }
        foreach ([['muted', 'surface'], ['muted', 'bg'], ['accent', 'surface'], ['accent', 'bg']] as $pair) {
            check(designContrast((string) $c[$pair[0]], (string) $c[$pair[1]]) >= 3.0, 'tema ' . $theme . ': el borde --' . $pair[0] . ' sobre --' . $pair[1] . ' llega a 3:1');
        }
    }
    // Los campos se bordean con el color apagado (3:1 de sobra), nunca con el del fondo.
    contains('.af-input, .af-select, .af-textarea { width: 100%; min-height: var(--tap); padding: var(--s1) var(--s3); border: 1px solid var(--muted);', $css);
    // Un enlace dentro de un texto no se distingue solo por el color: va subrayado.
    contains('a { color: var(--accent); text-decoration: underline;', $css);
});

test('el foco se ve siempre y nada lo quita; con «reducir movimiento» no se mueve nada', function (): void {
    $css = designCss('core/assets/admin/admin.css');
    contains(':focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }', $css);
    contains('.af-input:focus, .af-select:focus, .af-textarea:focus { outline: 2px solid var(--accent);', $css);
    contains('a.af-sr:focus {', $css, 'el enlace «saltar al contenido» se ve cuando recibe el foco');
    same(0, preg_match_all('/outline\s*:\s*(none|0)\b/', $css), 'admin.css no quita ningún marco de foco');
    // Los que quitan el marco del navegador ponen el suyo en la misma hoja.
    $allowed = [
        'core/assets/admin/widgets.css' => ['.af-rt:focus-within { outline: 2px solid var(--accent)'],
        'modules/cms/assets/blocks-editor.css' => ['.afb-block:focus-within { border-color: var(--accent)', '.afb-rich:focus { border-color: var(--accent)'],
    ];
    foreach (DESIGN_SHEETS as $sheet) {
        $sheetCss = designCss($sheet);
        $removed = preg_match_all('/outline\s*:\s*(none|0)\b/', $sheetCss);
        if ($removed > 0) {
            check(isset($allowed[$sheet]), $sheet . ': quita un marco de foco');
            foreach (isset($allowed[$sheet]) ? $allowed[$sheet] : [] as $own) {
                contains($own, $sheetCss, $sheet . ': quien quita el marco del navegador pinta el suyo');
            }
        }
    }
    contains('@media (prefers-reduced-motion: reduce) { *, *::before, *::after { transition: none !important; animation: none !important; scroll-behavior: auto !important; } }', $css);
    contains('scroll-padding:', $css, 'lo que recibe el foco no queda tapado por la barra de arriba ni por la de guardar');
});

test('tableta y móvil: todo lo que se pulsa mide 44 px y, por debajo de 768, cada fila de una tabla es una tarjeta', function (): void {
    $css = designCss('core/assets/admin/admin.css');
    check(preg_match('#@media \(max-width: 900px\) \{\s*:root \{ --tap: 44px; --tap-sm: 44px; \}#', $css) === 1, 'hasta 900 px las dos alturas de control valen 44 px');
    foreach (['.af-btn {', '.af-input, .af-select, .af-textarea {', '.af-tab {', '.af-nav-group > summary, .af-nav-link {', '.af-toggle {', '.af-dropdown-menu a, .af-dropdown-menu button {', '.af-top-search {'] as $rule) {
        $at = strpos($css, $rule);
        check($at !== false && strpos(substr($css, (int) $at, 420), 'min-height: var(--tap)') !== false, 'la regla «' . $rule . '» pide la altura de control');
    }
    contains('.af-icon-btn { display: inline-grid; place-items: center; width: var(--tap); height: var(--tap);', $css);
    $narrow = (string) substr($css, (int) strpos($css, '@media (max-width: 767.98px)'));
    contains('.af-table-wrap { overflow-x: visible; }', $narrow, 'sin desplazamiento de lado');
    contains('.af-table thead { position: absolute; width: 1px; height: 1px;', $narrow, 'la cabecera sigue para un lector de pantalla');
    contains('content: attr(data-label)', $narrow, 'cada celda enseña el nombre de su columna');
    contains('.af-table td.af-hide-sm { display: flex !important; }', $narrow, 'en tarjeta vuelven las columnas que se escondían por falta de ancho');
    contains('.af-tabs { display: flex; flex-wrap: wrap;', $css, 'las pestañas pasan a otra línea: no se desplazan de lado');
    notContains('overflow-x: auto', (string) preg_replace('#\.af-table-wrap \{ overflow-x: auto;[^}]*\}#', '', $css), 'solo la tabla (de 768 en adelante) se puede desplazar dentro de su caja');
    // En el cajón cerrado no se puede entrar con el tabulador.
    contains('transform: translateX(-100%); visibility: hidden;', $css);
});

test('Table.php: cada celda lleva el nombre de su columna y la casilla de elegir se pulsa por su etiqueta', function (): void {
    $php = (string) file_get_contents(Autoload::root() . '/core/src/Admin/Table.php');
    contains("' data-label=\"' . \$labels[(string) \$name] . '\">'", $php);
    same(2, substr_count($php, '<label class="af-pick"><input type="checkbox"'), 'elegir todo y elegir una fila');
    contains('<details class="af-filters" open data-af-filters="', $php, 'los filtros van en un bloque que se pliega en estrecho y abierto sin JavaScript');
    $js = (string) file_get_contents(Autoload::root() . '/core/assets/admin/admin.js');
    contains("all('table.af-table', scope).forEach(labelTable);", $js, 'las tablas escritas a mano reciben su data-label');
    foreach (["'role', 'table'", "'role', 'row'", "'columnheader'", "'rowheader' : 'cell'"] as $role) {
        contains($role, $js, 'la tabla conserva sus papeles aunque la hoja le cambie el display');
    }
});

test('los botones que solo llevan un icono tienen nombre, y el marco trae el enlace de saltar al contenido', function (): void {
    $root = Autoload::root();
    $files = array_merge(glob($root . '/core/views/admin/*.php') ?: [], glob($root . '/modules/*/views/admin/*.php') ?: [], glob($root . '/core/src/Admin/*.php') ?: [], glob($root . '/modules/*/src/Admin/*.php') ?: []);
    check(count($files) > 40, 'se han mirado las plantillas del panel');
    foreach ($files as $file) {
        $html = (string) file_get_contents($file);
        // Las etiquetas llevan PHP dentro («$app->url()»): en vez de recortar la etiqueta se mira su vecindad.
        preg_match_all('#class="[^"]*\baf-icon-btn\b#', $html, $tags, PREG_OFFSET_CAPTURE);
        foreach ($tags[0] as $tag) {
            $from = (int) strrpos(substr($html, 0, (int) $tag[1]), '<');
            $around = substr($html, $from, 600);
            $end = strpos($around, '</');
            check(strpos($end !== false ? substr($around, 0, $end) : $around, 'aria-label=') !== false, basename($file) . ': un botón de icono sin aria-label: ' . substr($around, 0, 90));
        }
    }
    $layout = (string) file_get_contents($root . '/core/views/admin/layout.php');
    $skip = strpos($layout, '<a class="af-sr" href="#af-main">');
    check($skip !== false && $skip < strpos($layout, '<aside'), 'lo primero que encuentra el tabulador es «saltar al contenido»');
    contains('<main class="af-content" id="af-main">', $layout);
    same(0, preg_match_all('#\sstyle="#', $layout), 'el marco no lleva estilos escritos en las etiquetas');
    $js = (string) file_get_contents($root . '/core/assets/admin/admin.js');
    contains('function setMenu(open, refocus)', $js, 'al abrir el cajón el foco entra en él y al cerrarlo vuelve a su botón');
    contains("on('keydown', '.af-dropdown'", $js, 'los desplegables se recorren con las flechas');
});
