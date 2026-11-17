<?php
/**
 * AIFEC — el árbol de accesibilidad de la tienda: ningún atributo ARIA en un elemento que no lo admite.
 *
 * Con ese árbol navegan los lectores de pantalla y los agentes de IA. PageSpeed Insights lo mide en su categoría
 * «Navegación agéntica»; el 13/11/2026 falló por un <label aria-pressed> en el selector de variantes. Esta prueba
 * recorre las páginas de la tienda tal como salen y aplica las reglas que más se rompen sin que nadie lo vea.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/../../modules/orders/tests/FrontKit.php';
require_once __DIR__ . '/../../modules/cms/tests/helpers.php';

/** El valor de un atributo, o «» si no está. */
function a11yAttr(DOMElement $el, string $name): string
{
    return $el->hasAttribute($name) ? trim($el->getAttribute($name)) : '';
}

/** La etiqueta con sus atributos más útiles, para decir de cuál se habla. */
function a11yShow(DOMElement $el): string
{
    $out = '<' . strtolower($el->nodeName);
    foreach (['id', 'class', 'type', 'name', 'href', 'role'] as $attr) {
        if ($el->hasAttribute($attr)) {
            $out .= ' ' . $attr . '="' . mb_substr($el->getAttribute($attr), 0, 40, 'UTF-8') . '"';
        }
    }

    return $out . '>';
}

/**
 * ¿Está fuera del árbol por tener, él o un ancestro, el atributo hidden, inert o un display:none en línea? Con $aria, también
 * si lo oculta un aria-hidden="true" (lo enfocable que quede ahí dentro lo vigila otra regla).
 */
function a11yHidden(DOMElement $el, bool $aria = false): bool
{
    for ($node = $el; $node instanceof DOMElement; $node = $node->parentNode) {
        if ($node->hasAttribute('hidden') || $node->hasAttribute('inert') || preg_match('/display\s*:\s*none/i', $node->getAttribute('style')) === 1) {
            return true;
        }
        if ($aria && strtolower($node->getAttribute('aria-hidden')) === 'true') {
            return true;
        }
    }

    return false;
}

/** ¿Se puede enfocar? (enlace, botón, campo activo, resumen, marco o cualquier cosa con tabindex). */
function a11yFocusable(DOMElement $el): bool
{
    $tag = strtolower($el->nodeName);
    if ($el->hasAttribute('disabled')) {
        return false;
    }
    if (in_array($tag, ['button', 'select', 'textarea', 'summary', 'iframe'], true)) {
        return true;
    }
    if ($tag === 'input') {
        return strtolower($el->getAttribute('type')) !== 'hidden';
    }
    if (($tag === 'a' || $tag === 'area') && $el->hasAttribute('href')) {
        return true;
    }
    if (strtolower($el->getAttribute('contenteditable')) === 'true') {
        return true;
    }

    return $el->hasAttribute('tabindex') && is_numeric(trim($el->getAttribute('tabindex')));
}

/** ¿Está en el orden del teclado? (enfocable y sin tabindex negativo). */
function a11yTabbable(DOMElement $el): bool
{
    return a11yFocusable($el) && !($el->hasAttribute('tabindex') && is_numeric(trim($el->getAttribute('tabindex'))) && (int) $el->getAttribute('tabindex') < 0);
}

/** El nombre que un elemento saca de su contenido: texto, alt de las imágenes y aria-label de lo que lleva dentro. */
function a11yText(DOMNode $node): string
{
    $out = '';
    foreach ($node->childNodes as $child) {
        if ($child instanceof DOMText) {
            $out .= $child->nodeValue;
            continue;
        }
        if (!$child instanceof DOMElement) {
            continue;
        }
        $tag = strtolower($child->nodeName);
        if ($child->hasAttribute('hidden') || strtolower($child->getAttribute('aria-hidden')) === 'true' || in_array($tag, ['script', 'style', 'template', 'input', 'select', 'textarea'], true)) {
            continue;
        }
        $label = a11yAttr($child, 'aria-label');
        if ($label !== '') {
            $out .= ' ' . $label . ' ';
        } elseif ($tag === 'img') {
            $out .= ' ' . $child->getAttribute('alt') . ' ';
        } elseif ($tag === 'svg') {
            foreach ($child->childNodes as $inner) {
                if ($inner instanceof DOMElement && strtolower($inner->nodeName) === 'title') {
                    $out .= ' ' . $inner->textContent . ' ';
                }
            }
        } else {
            $out .= ' ' . a11yText($child) . ' ';
        }
    }

    return trim((string) preg_replace('/\s+/u', ' ', $out));
}

/**
 * El nombre accesible de un elemento: aria-labelledby, aria-label, su etiqueta (campos), su contenido (si el papel lo
 * admite) y el atributo title, por ese orden. «» si no tiene.
 */
function a11yName(DOMElement $el, DOMXPath $xp, bool $fromContent): string
{
    $by = a11yAttr($el, 'aria-labelledby');
    if ($by !== '') {
        $text = '';
        foreach (preg_split('/\s+/', $by) ?: [] as $id) {
            $found = $xp->query('//*[@id=' . a11yQuote($id) . ']');
            if ($found !== false && $found->length > 0 && $found->item(0) instanceof DOMElement) {
                $item = $found->item(0);
                $text .= ' ' . a11yText($item) . ' ' . a11yAttr($item, 'aria-label');
            }
        }
        if (trim($text) !== '') {
            return trim($text);
        }
    }
    $label = a11yAttr($el, 'aria-label');
    if ($label !== '') {
        return $label;
    }
    $tag = strtolower($el->nodeName);
    if (in_array($tag, ['input', 'select', 'textarea'], true)) {
        $text = '';
        if ($el->hasAttribute('id') && $el->getAttribute('id') !== '') {
            $labels = $xp->query('//label[@for=' . a11yQuote($el->getAttribute('id')) . ']');
            foreach ($labels !== false ? $labels : [] as $lab) {
                $text .= ' ' . a11yText($lab);
            }
        }
        for ($up = $el->parentNode; $up instanceof DOMElement; $up = $up->parentNode) {
            if (strtolower($up->nodeName) === 'label') {
                $text .= ' ' . a11yText($up);
                break;
            }
        }
        if (trim($text) !== '') {
            return trim($text);
        }
    }
    if ($fromContent) {
        $content = a11yText($el);
        if ($content !== '') {
            return $content;
        }
    }

    return a11yAttr($el, 'title');
}

/** Un texto como literal de XPath. */
function a11yQuote(string $text): string
{
    if (strpos($text, "'") === false) {
        return "'" . $text . "'";
    }

    return '"' . str_replace('"', '', $text) . '"';
}

/** ¿Es un valor válido de autocomplete? (HTML: sección, envío o facturación, tipo de contacto, campo). */
function a11yAutocompleteValid(string $value): bool
{
    $tokens = preg_split('/\s+/', strtolower(trim($value))) ?: [];
    if ($tokens === [] || $tokens === ['']) {
        return false;
    }
    if (count($tokens) === 1 && in_array($tokens[0], ['on', 'off'], true)) {
        return true;
    }
    $fields = ['name', 'honorific-prefix', 'given-name', 'additional-name', 'family-name', 'honorific-suffix', 'nickname', 'username', 'new-password',
        'current-password', 'one-time-code', 'organization-title', 'organization', 'street-address', 'address-line1', 'address-line2', 'address-line3',
        'address-level4', 'address-level3', 'address-level2', 'address-level1', 'country', 'country-name', 'postal-code', 'cc-name', 'cc-given-name',
        'cc-additional-name', 'cc-family-name', 'cc-number', 'cc-exp', 'cc-exp-month', 'cc-exp-year', 'cc-csc', 'cc-type', 'transaction-currency',
        'transaction-amount', 'language', 'bday', 'bday-day', 'bday-month', 'bday-year', 'sex', 'url', 'photo', 'tel', 'tel-country-code', 'tel-national',
        'tel-area-code', 'tel-local', 'tel-local-prefix', 'tel-local-suffix', 'tel-extension', 'email', 'impp'];
    $contact = ['tel', 'tel-country-code', 'tel-national', 'tel-area-code', 'tel-local', 'tel-local-prefix', 'tel-local-suffix', 'tel-extension', 'email', 'impp'];
    if (end($tokens) === 'webauthn') {
        array_pop($tokens);
    }
    while ($tokens !== [] && strpos($tokens[0], 'section-') === 0) {
        array_shift($tokens);
    }
    if ($tokens !== [] && in_array($tokens[0], ['shipping', 'billing'], true)) {
        array_shift($tokens);
    }
    if (count($tokens) === 2 && in_array($tokens[0], ['home', 'work', 'mobile', 'fax', 'pager'], true)) {
        return in_array($tokens[1], $contact, true);
    }

    return count($tokens) === 1 && in_array($tokens[0], $fields, true);
}

/**
 * Las reglas de axe que usa la auditoría «Árbol de accesibilidad» de Lighthouse (categoría de navegación agéntica) y que
 * se pueden comprobar sobre el HTML servido: nombre accesible de botones, enlaces, campos, marcos y papeles; alt de las
 * imágenes; idioma, título y zum del documento; listas y definiciones; encabezados; tabindex; aria-hidden sobre algo
 * enfocable; interactivos anidados; papeles con sus hijos y sus padres obligatorios; autocomplete.
 *
 * @return string[]
 */
function a11yTreeProblems(string $source): array
{
    $html = (string) preg_replace('~<(script|style|template)\b[^>]*>.*?</\1>~is', '', $source);
    $html = (string) preg_replace('~<!--.*?-->~s', '', $html);
    $isDocument = preg_match('~<html\b~i', $html) === 1;
    $dom = new DOMDocument();
    $before = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($before);
    $xp = new DOMXPath($dom);
    $problems = [];
    $add = static function (DOMElement $el, string $why) use (&$problems): void {
        $problems[] = a11yShow($el) . ' · ' . $why;
    };
    $each = static function (string $query) use ($xp): array {
        $found = [];
        $nodes = $xp->query($query);
        foreach ($nodes !== false ? $nodes : [] as $node) {
            if ($node instanceof DOMElement && !a11yHidden($node, true)) {
                $found[] = $node;
            }
        }

        return $found;
    };

    // Documento: idioma, título y zum.
    if ($isDocument) {
        $root = $xp->query('/html')->item(0);
        $lang = $root instanceof DOMElement ? a11yAttr($root, 'lang') : '';
        if ($lang === '') {
            $problems[] = '<html> · falta el atributo lang';
        } elseif (preg_match('/^[a-zA-Z]{2,3}(-[a-zA-Z0-9]{2,8})*$/', $lang) !== 1) {
            $problems[] = '<html lang="' . $lang . '"> · el idioma no es válido';
        }
        $title = $xp->query('//head/title');
        if ($title === false || $title->length === 0 || trim($title->item(0)->textContent) === '') {
            $problems[] = '<title> · el documento no tiene título';
        }
        $meta = $xp->query('//meta[@name="viewport"]');
        if ($meta !== false && $meta->length > 0 && $meta->item(0) instanceof DOMElement) {
            $content = strtolower((string) $meta->item(0)->getAttribute('content'));
            if (preg_match('/user-scalable\s*=\s*(no|0)\b/', $content) === 1) {
                $problems[] = '<meta name="viewport"> · user-scalable bloquea el zum';
            }
            if (preg_match('/maximum-scale\s*=\s*([0-9.]+)/', $content, $scale) === 1 && (float) $scale[1] < 5.0) {
                $problems[] = '<meta name="viewport"> · maximum-scale=' . $scale[1] . ' limita el zum';
            }
        }
        $h1 = count($each('//h1'));
        if ($h1 > 1) {
            $problems[] = '<h1> · hay ' . $h1 . ' encabezados h1 en la página';
        }
    }

    // Nombre accesible de botones, enlaces, campos y marcos.
    foreach ($each('//button') as $el) {
        if (a11yName($el, $xp, true) === '') {
            $add($el, 'botón sin nombre accesible');
        }
    }
    foreach ($each('//a[@href]') as $el) {
        if (a11yName($el, $xp, true) === '') {
            $add($el, 'enlace sin nombre accesible');
        }
    }
    foreach ($each('//input | //select | //textarea') as $el) {
        $tag = strtolower($el->nodeName);
        $type = $tag === 'input' ? strtolower($el->hasAttribute('type') ? $el->getAttribute('type') : 'text') : '';
        if (in_array($type, ['hidden', 'submit', 'reset'], true)) {
            continue;
        }
        if ($type === 'image') {
            if (a11yAttr($el, 'alt') === '' && a11yName($el, $xp, false) === '') {
                $add($el, 'input de tipo imagen sin alt');
            }
        } elseif ($type === 'button') {
            if (a11yAttr($el, 'value') === '' && a11yName($el, $xp, false) === '') {
                $add($el, 'botón input sin value');
            }
        } elseif (a11yName($el, $xp, false) === '') {
            $add($el, 'campo sin etiqueta ni nombre accesible');
        }
    }
    foreach ($each('//iframe | //frame') as $el) {
        if (a11yAttr($el, 'title') === '' && a11yName($el, $xp, false) === '') {
            $add($el, 'marco sin título');
        }
    }
    foreach ($each('//label[@for]') as $el) {
        $target = $xp->query('//*[@id=' . a11yQuote($el->getAttribute('for')) . ']');
        if ($target === false || $target->length === 0) {
            $add($el, 'label for="' . $el->getAttribute('for') . '" apunta a un campo que no está');
        }
    }

    // Imágenes: alt siempre (vacío si es decorativa), y las que hacen de imagen con su nombre.
    foreach ($each('//img') as $el) {
        $role = strtolower(a11yAttr($el, 'role'));
        if (!$el->hasAttribute('alt') && !in_array($role, ['presentation', 'none'], true) && a11yAttr($el, 'aria-label') === '' && a11yAttr($el, 'aria-labelledby') === '') {
            $add($el, 'imagen sin atributo alt');
        }
    }
    foreach ($each('//svg[@role="img"]') as $el) {
        $hasTitle = false;
        foreach ($el->childNodes as $inner) {
            if ($inner instanceof DOMElement && strtolower($inner->nodeName) === 'title' && trim($inner->textContent) !== '') {
                $hasTitle = true;
            }
        }
        if (!$hasTitle && a11yName($el, $xp, false) === '') {
            $add($el, 'svg con papel img sin nombre');
        }
    }

    // Papeles con nombre obligatorio (los elementos nativos ya se miraron arriba).
    $named = ['button' => true, 'link' => true, 'checkbox' => true, 'radio' => true, 'switch' => true, 'menuitem' => true, 'menuitemcheckbox' => true,
        'menuitemradio' => true, 'tab' => true, 'option' => true, 'treeitem' => true, 'img' => true, 'heading' => true,
        'combobox' => false, 'textbox' => false, 'searchbox' => false, 'slider' => false, 'spinbutton' => false, 'listbox' => false, 'dialog' => false,
        'alertdialog' => false, 'progressbar' => false, 'meter' => false, 'tooltip' => false];
    foreach ($each('//*[@role]') as $el) {
        $tag = strtolower($el->nodeName);
        $role = strtolower(trim(explode(' ', trim($el->getAttribute('role')))[0]));
        if (!isset($named[$role]) || in_array($tag, ['button', 'a', 'input', 'select', 'textarea', 'img', 'svg', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li'], true)) {
            continue;
        }
        if (a11yName($el, $xp, $named[$role]) === '') {
            $add($el, 'papel «' . $role . '» sin nombre accesible');
        }
    }

    // Encabezados: ni vacíos ni con saltos hacia abajo.
    $previous = 0;
    foreach ($each('//h1 | //h2 | //h3 | //h4 | //h5 | //h6 | //*[@role="heading"]') as $el) {
        $tag = strtolower($el->nodeName);
        $level = preg_match('/^h([1-6])$/', $tag, $m) === 1 ? (int) $m[1] : (int) a11yAttr($el, 'aria-level');
        if (a11yName($el, $xp, true) === '') {
            $add($el, 'encabezado vacío');
        }
        if ($level > 0) {
            if ($previous > 0 && $level > $previous + 1) {
                $add($el, 'el encabezado salta de h' . $previous . ' a h' . $level);
            }
            $previous = $level;
        }
    }

    // Teclado: nada con tabindex positivo, nada enfocable dentro de aria-hidden.
    foreach ($each('//*[@tabindex]') as $el) {
        if (is_numeric(trim($el->getAttribute('tabindex'))) && (int) $el->getAttribute('tabindex') > 0) {
            $add($el, 'tabindex positivo');
        }
    }
    $hiddenNodes = $xp->query('//*[@aria-hidden="true"]');
    foreach ($hiddenNodes !== false ? $hiddenNodes : [] as $el) {
        if (!$el instanceof DOMElement || a11yHidden($el)) {
            continue;
        }
        $candidates = [$el];
        foreach ($xp->query('.//*', $el) ?: [] as $inner) {
            if ($inner instanceof DOMElement) {
                $candidates[] = $inner;
            }
        }
        foreach ($candidates as $inner) {
            if (a11yTabbable($inner) && !a11yHidden($inner)) {
                $add($el, 'aria-hidden="true" con algo enfocable dentro: ' . a11yShow($inner));
                break;
            }
        }
    }

    // Interactivos anidados.
    foreach ($each('//button | //*[@role="button" or @role="checkbox" or @role="radio" or @role="switch" or @role="tab" or @role="option" or @role="menuitemcheckbox" or @role="menuitemradio"]') as $el) {
        foreach ($xp->query('.//*', $el) ?: [] as $inner) {
            if ($inner instanceof DOMElement && a11yTabbable($inner) && !a11yHidden($inner)) {
                $add($el, 'tiene un elemento interactivo dentro: ' . a11yShow($inner));
                break;
            }
        }
    }
    foreach ($each('//a[@href]') as $el) {
        foreach ($xp->query('.//*', $el) ?: [] as $inner) {
            if ($inner instanceof DOMElement && a11yFocusable($inner) && !a11yHidden($inner) && !($inner->hasAttribute('tabindex') && !in_array(strtolower($inner->nodeName), ['a', 'button', 'input', 'select', 'textarea'], true))) {
                $add($el, 'enlace con un elemento interactivo dentro: ' . a11yShow($inner));
                break;
            }
        }
    }

    // Listas y definiciones.
    foreach ($each('//ul | //ol | //menu') as $el) {
        if (in_array(strtolower(a11yAttr($el, 'role')), ['none', 'presentation'], true)) {
            continue;
        }
        foreach ($el->childNodes as $child) {
            if ($child instanceof DOMElement && !in_array(strtolower($child->nodeName), ['li', 'script', 'template'], true)) {
                $add($el, 'lista con un hijo que no es li: ' . a11yShow($child));
                break;
            }
        }
    }
    foreach ($each('//li') as $el) {
        $parent = $el->parentNode;
        $ok = $parent instanceof DOMElement && (in_array(strtolower($parent->nodeName), ['ul', 'ol', 'menu'], true) || strtolower(a11yAttr($parent, 'role')) === 'list');
        if (!$ok) {
            $add($el, 'li fuera de una lista');
        }
    }
    foreach ($each('//dl') as $el) {
        foreach ($el->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($child->nodeName);
            if ($tag === 'div') {
                foreach ($child->childNodes as $grand) {
                    if ($grand instanceof DOMElement && !in_array(strtolower($grand->nodeName), ['dt', 'dd', 'script', 'template'], true)) {
                        $add($el, 'lista de definiciones con un hijo que no es dt ni dd: ' . a11yShow($grand));
                    }
                }
            } elseif (!in_array($tag, ['dt', 'dd', 'script', 'template'], true)) {
                $add($el, 'lista de definiciones con un hijo que no es dt ni dd: ' . a11yShow($child));
            }
        }
    }
    foreach ($each('//dt | //dd') as $el) {
        $parent = $el->parentNode;
        $ok = $parent instanceof DOMElement && (strtolower($parent->nodeName) === 'dl'
            || (strtolower($parent->nodeName) === 'div' && $parent->parentNode instanceof DOMElement && strtolower($parent->parentNode->nodeName) === 'dl'));
        if (!$ok) {
            $add($el, strtolower($el->nodeName) . ' fuera de una lista de definiciones');
        }
    }

    // Papeles con sus hijos obligatorios y con sus padres obligatorios.
    $needChildren = [
        'tablist' => './/*[@role="tab"]',
        'list' => './/li | .//*[@role="listitem"]',
        'listbox' => './/*[@role="option"] | .//option',
        'menu' => './/*[@role="menuitem" or @role="menuitemcheckbox" or @role="menuitemradio"]',
        'menubar' => './/*[@role="menuitem" or @role="menuitemcheckbox" or @role="menuitemradio"]',
        'tree' => './/*[@role="treeitem"]',
        'radiogroup' => './/*[@role="radio"] | .//input[@type="radio"]',
    ];
    foreach ($needChildren as $role => $query) {
        foreach ($each('//*[@role="' . $role . '"]') as $el) {
            $found = $xp->query($query, $el);
            if ($found === false || $found->length === 0) {
                $add($el, 'el papel «' . $role . '» necesita hijos con el papel que le toca');
            }
        }
    }
    $needParent = [
        'tab' => ['tablist'],
        'listitem' => ['list'],
        'option' => ['listbox'],
        'menuitem' => ['menu', 'menubar', 'group'],
        'menuitemcheckbox' => ['menu', 'menubar', 'group'],
        'menuitemradio' => ['menu', 'menubar', 'group'],
        'treeitem' => ['tree', 'group'],
    ];
    foreach ($needParent as $role => $parents) {
        foreach ($each('//*[@role="' . $role . '"]') as $el) {
            $ok = false;
            for ($up = $el->parentNode; $up instanceof DOMElement; $up = $up->parentNode) {
                $upRole = strtolower(a11yAttr($up, 'role'));
                if (in_array($upRole, $parents, true) || ($role === 'listitem' && in_array(strtolower($up->nodeName), ['ul', 'ol'], true))) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                $add($el, 'el papel «' . $role . '» tiene que estar dentro de ' . implode(' o ', $parents));
            }
        }
    }

    // autocomplete válido.
    foreach ($each('//input[@autocomplete] | //select[@autocomplete] | //textarea[@autocomplete]') as $el) {
        if (!a11yAutocompleteValid($el->getAttribute('autocomplete'))) {
            $add($el, 'autocomplete="' . $el->getAttribute('autocomplete') . '" no es un valor válido');
        }
    }

    return array_values(array_unique($problems));
}

/**
 * Los problemas de ARIA de un HTML: cada uno «<etiqueta …> · motivo».
 *
 * @return string[]
 */
function ariaProblems(string $html): array
{
    $source = $html;
    // Fuera lo que no es marcado: guiones, estilos, comentarios y el contenido de <template>.
    $html = (string) preg_replace('~<(script|style|template)\b[^>]*>.*?</\1>~is', '', $html);
    $html = (string) preg_replace('~<!--.*?-->~s', '', $html);
    $problems = [];
    if (preg_match_all('~<([a-zA-Z][a-zA-Z0-9]*)\b([^<>]*)>~', $html, $tags, PREG_SET_ORDER) === false) {
        return $problems;
    }
    $buttonLike = ['button', 'tab', 'switch', 'menuitem', 'menuitemcheckbox', 'menuitemradio', 'checkbox', 'radio', 'option', 'treeitem'];
    $generic = ['div', 'span', 'p', 'strong', 'em', 'b', 'i', 'small', 'code', 'label', 'li', 'dd', 'dt'];
    foreach ($tags as $tag) {
        $name = strtolower($tag[1]);
        $attrs = [];
        if (preg_match_all('~([a-zA-Z_:][-a-zA-Z0-9_:.]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+)))?~', $tag[2], $found, PREG_SET_ORDER) !== false) {
            foreach ($found as $a) {
                $attrs[strtolower($a[1])] = isset($a[2]) && $a[2] !== '' ? $a[2] : (isset($a[3]) && $a[3] !== '' ? $a[3] : (isset($a[4]) ? $a[4] : ''));
            }
        }
        $role = isset($attrs['role']) ? strtolower(trim($attrs['role'])) : '';
        $shown = '<' . $name . ' ' . trim((string) substr($tag[2], 0, 110)) . '>';

        if (isset($attrs['aria-pressed']) && !($name === 'button' || ($name === 'input' && isset($attrs['type']) && in_array(strtolower($attrs['type']), ['button', 'submit'], true)) || $role === 'button')) {
            $problems[] = $shown . ' · aria-pressed solo vale en un botón';
        }
        if (isset($attrs['aria-checked']) && !in_array($role, ['checkbox', 'radio', 'switch', 'menuitemcheckbox', 'menuitemradio', 'option', 'treeitem'], true)) {
            $problems[] = $shown . ' · aria-checked necesita un papel de casilla, radio o interruptor';
        }
        if (isset($attrs['aria-selected']) && !in_array($role, ['option', 'tab', 'row', 'gridcell', 'columnheader', 'rowheader', 'treeitem'], true)) {
            $problems[] = $shown . ' · aria-selected necesita un papel de opción, pestaña o celda';
        }
        if (isset($attrs['aria-expanded'])) {
            $ok = in_array($name, ['button', 'summary', 'a'], true) || ($name === 'input' && $role === 'combobox')
                || in_array($role, ['button', 'combobox', 'link', 'listbox', 'row', 'tab', 'treeitem', 'menuitem', 'gridcell', 'switch', 'checkbox'], true);
            if (!$ok) {
                $problems[] = $shown . ' · aria-expanded no vale en este elemento';
            }
        }
        if (in_array($name, $generic, true) && $role === '' && (isset($attrs['aria-label']) || isset($attrs['aria-labelledby']))) {
            // <label> y <li> sin papel son «genéricos» para ARIA 1.2: un nombre accesible ahí no se anuncia y se marca como error.
            if (!($name === 'li' || $name === 'dd' || $name === 'dt')) {
                $problems[] = $shown . ' · aria-label en un elemento sin papel';
            }
        }
        if ($role !== '' && preg_match('/^[a-z]+( [a-z]+)*$/', $role) !== 1) {
            $problems[] = $shown . ' · papel mal escrito';
        }
        foreach (['aria-hidden' => ['true', 'false'], 'aria-current' => ['page', 'step', 'location', 'date', 'time', 'true', 'false'], 'aria-live' => ['polite', 'assertive', 'off'],
            'aria-pressed' => ['true', 'false', 'mixed'], 'aria-expanded' => ['true', 'false'], 'aria-selected' => ['true', 'false'], 'aria-busy' => ['true', 'false'],
            'aria-invalid' => ['true', 'false', 'grammar', 'spelling'], 'aria-modal' => ['true', 'false'], 'aria-haspopup' => ['true', 'false', 'menu', 'listbox', 'tree', 'grid', 'dialog']] as $attr => $allowed) {
            if (isset($attrs[$attr]) && !in_array(strtolower($attrs[$attr]), $allowed, true)) {
                $problems[] = $shown . ' · ' . $attr . '="' . $attrs[$attr] . '" no es un valor válido';
            }
        }
        if ($role !== '') {
            foreach (explode(' ', $role) as $one) {
                if (!in_array($one, ['alert', 'alertdialog', 'application', 'article', 'banner', 'button', 'cell', 'checkbox', 'columnheader', 'combobox', 'complementary',
                    'contentinfo', 'definition', 'dialog', 'directory', 'document', 'feed', 'figure', 'form', 'grid', 'gridcell', 'group', 'heading', 'img', 'link', 'list',
                    'listbox', 'listitem', 'log', 'main', 'marquee', 'math', 'menu', 'menubar', 'menuitem', 'menuitemcheckbox', 'menuitemradio', 'meter', 'navigation', 'none',
                    'note', 'option', 'presentation', 'progressbar', 'radio', 'radiogroup', 'region', 'row', 'rowgroup', 'rowheader', 'scrollbar', 'search', 'searchbox',
                    'separator', 'slider', 'spinbutton', 'status', 'switch', 'tab', 'table', 'tablist', 'tabpanel', 'term', 'textbox', 'timer', 'toolbar', 'tooltip', 'tree',
                    'treegrid', 'treeitem'], true)) {
                    $problems[] = $shown . ' · el papel «' . $one . '» no existe';
                }
            }
        }
    }
    // Identificadores repetidos y referencias a identificadores que no están: rompen aria-labelledby, aria-controls y for.
    $ids = [];
    if (preg_match_all('~\sid="([^"]+)"~', $html, $found) !== false) {
        foreach (array_count_values($found[1]) as $id => $times) {
            $ids[$id] = true;
            if ($times > 1) {
                $problems[] = 'id="' . $id . '" repetido ' . $times . ' veces';
            }
        }
    }
    if (preg_match_all('~\s(aria-labelledby|aria-describedby|aria-controls)="([^"]+)"~', $html, $refs, PREG_SET_ORDER) !== false) {
        foreach ($refs as $ref) {
            foreach (preg_split('/\s+/', trim($ref[2])) ?: [] as $id) {
                if ($id !== '' && !isset($ids[$id])) {
                    $problems[] = $ref[1] . '="' . $ref[2] . '" apunta a un id que no está en la página';
                }
            }
        }
    }

    // Las reglas de axe que usa la auditoría «árbol de accesibilidad» de Lighthouse, sobre el árbol del documento.
    return array_values(array_unique(array_merge($problems, a11yTreeProblems($source))));
}

test('la regla caza lo que tiene que cazar (y nada más)', function (): void {
    same([], ariaProblems('<button aria-pressed="true">A</button><a href="#" aria-expanded="false">B</a><nav aria-label="Menú"><ul><li>x</li></ul></nav>'));
    $bad = ariaProblems('<label class="af-variant" aria-pressed="true"><input type="radio" name="talla"> M</label>');
    same(1, count($bad));
    contains('aria-pressed solo vale en un botón', $bad[0]);
    contains('aria-selected', implode(' ', ariaProblems('<div aria-selected="true">x</div>')));
    contains('aria-expanded', implode(' ', ariaProblems('<div aria-expanded="true">x</div>')));
    contains('aria-label en un elemento sin papel', implode(' ', ariaProblems('<div aria-label="Cesta">3</div>')));
    contains('no existe', implode(' ', ariaProblems('<div role="botonaco">x</div>')));
    contains('no es un valor válido', implode(' ', ariaProblems('<button aria-pressed="si">x</button>')));
    contains('repetido', implode(' ', ariaProblems('<p id="a">1</p><p id="a">2</p>')));
    contains('apunta a un id que no está', implode(' ', ariaProblems('<section aria-labelledby="titulo">x</section>')));
    same([], ariaProblems('<script>var a = "<div aria-pressed=true>";</script><section aria-labelledby="t"><h2 id="t">T</h2></section>'), 'lo que hay dentro de un guion no es marcado');
});

/**
 * Un caso que rompe una regla y otro que no. Cada uno: [HTML que la rompe, texto del problema, HTML correcto].
 *
 * @return array<string,array{0:string,1:string,2:string}>
 */
function a11yRuleCases(): array
{
    $doc = static function (string $head, string $body, string $lang = ' lang="es"'): string {
        return '<!doctype html><html' . $lang . '><head><meta charset="utf-8">' . $head . '</head><body>' . $body . '</body></html>';
    };

    return [
        'botón sin nombre' => ['<button type="button"></button>', 'botón sin nombre accesible', '<button type="button" aria-label="Cerrar"><svg aria-hidden="true"></svg></button>'],
        'botón con solo un icono oculto' => ['<button type="button"><span aria-hidden="true">+</span></button>', 'botón sin nombre accesible', '<button type="button"><span aria-hidden="true">+</span> Sumar</button>'],
        'enlace sin nombre' => ['<a href="/ir"></a>', 'enlace sin nombre accesible', '<a href="/ir"><img src="/a.png" alt="Carrito" width="1" height="1"></a>'],
        'enlace con imagen sin alt' => ['<a href="/ir"><img src="/a.png" alt="" width="1" height="1"></a>', 'enlace sin nombre accesible', '<a href="/ir" aria-label="Ir al carrito"><img src="/a.png" alt="" width="1" height="1"></a>'],
        'campo sin etiqueta' => ['<input type="text" name="q">', 'campo sin etiqueta ni nombre accesible', '<label for="q">Buscar</label><input id="q" type="text" name="q">'],
        'campo con etiqueta que lo envuelve' => ['<label><input type="checkbox" name="a"></label>', 'campo sin etiqueta ni nombre accesible', '<label><input type="checkbox" name="a"> Acepto</label>'],
        'lista desplegable sin etiqueta' => ['<select name="s"><option>1</option></select>', 'campo sin etiqueta ni nombre accesible', '<select name="s" aria-label="Orden"><option>1</option></select>'],
        'área de texto sin etiqueta' => ['<textarea name="m"></textarea>', 'campo sin etiqueta ni nombre accesible', '<label for="m">Mensaje</label><textarea id="m" name="m"></textarea>'],
        'campo con etiqueta que apunta a otro sitio' => ['<label for="otro">Nombre</label><input id="uno" type="text" name="n" aria-label="N">', 'apunta a un campo que no está', '<label for="uno">Nombre</label><input id="uno" type="text" name="n">'],
        'input de imagen sin alt' => ['<input type="image" src="/a.png">', 'input de tipo imagen sin alt', '<input type="image" src="/a.png" alt="Buscar">'],
        'botón input sin value' => ['<input type="button">', 'botón input sin value', '<input type="button" value="Pulsar">'],
        'marco sin título' => ['<iframe src="/mapa"></iframe>', 'marco sin título', '<iframe src="/mapa" title="Mapa de la tienda"></iframe>'],
        'imagen sin alt' => ['<img src="/a.png" width="1" height="1">', 'imagen sin atributo alt', '<img src="/a.png" alt="" width="1" height="1">'],
        'svg con papel img sin nombre' => ['<svg role="img"><path d="M0 0"/></svg>', 'svg con papel img sin nombre', '<svg role="img"><title>Estrella</title><path d="M0 0"/></svg>'],
        'documento sin idioma' => [$doc('<title>T</title>', '<h1>T</h1>', ''), 'falta el atributo lang', $doc('<title>T</title>', '<h1>T</h1>')],
        'documento con idioma inventado' => [$doc('<title>T</title>', '<h1>T</h1>', ' lang="castellano!"'), 'el idioma no es válido', $doc('<title>T</title>', '<h1>T</h1>', ' lang="es-ES"')],
        'documento sin título' => [$doc('', '<h1>T</h1>'), 'el documento no tiene título', $doc('<title>T</title>', '<h1>T</h1>')],
        'zum bloqueado' => [$doc('<title>T</title><meta name="viewport" content="width=device-width, user-scalable=no">', '<h1>T</h1>'), 'user-scalable bloquea el zum', $doc('<title>T</title><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">', '<h1>T</h1>')],
        'zum limitado' => [$doc('<title>T</title><meta name="viewport" content="width=device-width, maximum-scale=1">', '<h1>T</h1>'), 'maximum-scale=1 limita el zum', $doc('<title>T</title><meta name="viewport" content="width=device-width, maximum-scale=5">', '<h1>T</h1>')],
        'dos h1' => [$doc('<title>T</title>', '<h1>A</h1><h1>B</h1>'), 'hay 2 encabezados h1', $doc('<title>T</title>', '<h1>A</h1><h2>B</h2>')],
        'lista con un hijo ajeno' => ['<ul><div>x</div></ul>', 'lista con un hijo que no es li', '<ul><li>x</li></ul>'],
        'li suelto' => ['<li>x</li>', 'li fuera de una lista', '<ol><li>x</li></ol>'],
        'definiciones mal formadas' => ['<dl><p>x</p></dl>', 'lista de definiciones con un hijo que no es dt ni dd', '<dl><div><dt>a</dt><dd>b</dd></div></dl>'],
        'dt suelto' => ['<dt>x</dt>', 'dt fuera de una lista de definiciones', '<dl><dt>x</dt><dd>y</dd></dl>'],
        'encabezado que salta' => ['<h1>A</h1><h3>B</h3>', 'el encabezado salta de h1 a h3', '<h1>A</h1><h2>B</h2><h3>C</h3><h2>D</h2>'],
        'encabezado vacío' => ['<h2></h2>', 'encabezado vacío', '<h2>Ofertas</h2>'],
        'tabindex positivo' => ['<div tabindex="3">x</div>', 'tabindex positivo', '<div tabindex="0">x</div><div tabindex="-1">y</div>'],
        'aria-hidden sobre un enlace' => ['<div aria-hidden="true"><a href="/x">X</a></div>', 'aria-hidden="true" con algo enfocable dentro', '<div aria-hidden="true"><a href="/x" tabindex="-1">X</a></div>'],
        'aria-hidden sobre un campo' => ['<span aria-hidden="true"><input type="text" aria-label="q"></span>', 'aria-hidden="true" con algo enfocable dentro', '<span aria-hidden="true"><input type="text" aria-label="q" disabled></span>'],
        'botón con un enlace dentro' => ['<button type="button">Ir <a href="/x">ahora</a></button>', 'tiene un elemento interactivo dentro', '<button type="button">Ir ahora</button>'],
        'enlace con un botón dentro' => ['<a href="/x">Ir <button type="button">ahora</button></a>', 'enlace con un elemento interactivo dentro', '<a href="/x">Ir ahora</a>'],
        'tablist sin pestañas' => ['<div role="tablist" aria-label="Datos"><span>x</span></div>', 'el papel «tablist» necesita hijos', '<div role="tablist" aria-label="Datos"><button type="button" role="tab" aria-selected="true">A</button></div>'],
        'pestaña fuera de un tablist' => ['<button type="button" role="tab" aria-selected="false">A</button>', 'el papel «tab» tiene que estar dentro de tablist', '<div role="tablist" aria-label="D"><button type="button" role="tab" aria-selected="false">A</button></div>'],
        'lista con papel y sin elementos' => ['<div role="list"><p>x</p></div>', 'el papel «list» necesita hijos', '<div role="list"><div role="listitem">x</div></div>'],
        'listitem fuera de una lista' => ['<div role="listitem">x</div>', 'el papel «listitem» tiene que estar dentro de list', '<div role="list"><div role="listitem">x</div></div>'],
        'radiogroup sin radios' => ['<div role="radiogroup" aria-label="Talla"><p>x</p></div>', 'el papel «radiogroup» necesita hijos', '<div role="radiogroup" aria-label="Talla"><label><input type="radio" name="t" value="s"> S</label></div>'],
        'papel con nombre obligatorio' => ['<div role="dialog"></div>', 'papel «dialog» sin nombre accesible', '<div role="dialog" aria-labelledby="tt"><h2 id="tt">Título</h2></div>'],
        'botón hecho con un div' => ['<div role="button" tabindex="0"></div>', 'papel «button» sin nombre accesible', '<div role="button" tabindex="0">Abrir</div>'],
        'autocomplete inventado' => ['<input type="text" aria-label="n" autocomplete="nombre">', 'autocomplete="nombre" no es un valor válido', '<input type="text" aria-label="n" autocomplete="section-envio shipping home tel">'],
    ];
}

test('cada regla de accesibilidad del árbol salta con un HTML que la rompe y no con uno correcto', function (): void {
    foreach (a11yRuleCases() as $name => $case) {
        $broken = implode(' | ', ariaProblems($case[0]));
        contains($case[1], $broken, $name . ': la regla tiene que saltar');
        same([], ariaProblems($case[2]), $name . ': el caso correcto no tiene ningún problema');
    }
    check(count(a11yRuleCases()) >= 38, 'hay un caso por regla');
});

test('un autocomplete se valida con la gramática de HTML', function (): void {
    foreach (['off', 'on', 'name', 'given-name', 'email', 'postal-code', 'shipping address-line1', 'billing country', 'section-a shipping home tel', 'username webauthn', 'current-password', 'cc-number'] as $valid) {
        check(a11yAutocompleteValid($valid), $valid . ' es válido');
    }
    foreach (['', 'nombre', 'shipping', 'home name', 'work shipping tel', 'email email', 'on off'] as $invalid) {
        check(!a11yAutocompleteValid($invalid), '«' . $invalid . '» no es válido');
    }
});

/**
 * Los temas que existen: la carpeta de cada uno con su theme.json.
 *
 * @return string[]
 */
function ariaThemes(): array
{
    $themes = [];
    foreach (glob(dirname(__DIR__, 2) . '/themes/*/theme.json') ?: [] as $file) {
        $themes[] = basename(dirname($file));
    }
    sort($themes);

    return $themes;
}

/**
 * Todas las páginas de una tienda con ese tema, tal como las sirve: etiqueta => [estado, HTML].
 *
 * @return array<string,array{0:int,1:string}>
 */
function ariaPages(string $theme): array
{
    static $cache = [];
    if (isset($cache[$theme])) {
        return $cache[$theme];
    }
    $cache[$theme] = ariaBuildPages($theme);

    return $cache[$theme];
}

/**
 * @return array<string,array{0:int,1:string}>
 */
function ariaBuildPages(string $theme): array
{
    $app = frontShop(['modules' => ['search', 'cms', 'assistant', 'consent', 'webmcp', 'reviews'], 'settings' => ['theme.active' => $theme]]);
    $category = aifecCategory($app, 'Ropa');
    aifecAttribute($app, 'talla', 'Talla', ['s' => 'S', 'm' => 'M']);
    aifecProduct($app, [
        'name' => 'Camiseta', 'slug' => 'camiseta', 'price' => 1500, 'categories' => [$category], 'stock' => 5,
        'variants' => [
            ['sku' => 'CAM-S', 'stock' => 3, 'values' => ['talla' => 's']],
            ['sku' => 'CAM-M', 'price' => 2500, 'compare_price' => 3000, 'stock' => 0, 'values' => ['talla' => 'm']],
        ],
    ]);
    $simple = frontProduct($app);
    $page = cmsInsertPage($app, ['title' => 'Aviso legal', 'slug' => 'aviso-legal']);
    $post = cmsInsertPost($app, ['title' => 'Novedades de otoño', 'slug' => 'novedades-de-otono']);
    $web = new TestClient($app);
    $url = $app->url();
    $pages = [];
    $get = static function (string $label, string $path, TestClient $client) use (&$pages): void {
        $r = $client->get($path);
        $pages[$label] = [$r->status, $r->body];
    };

    $get('portada', '/', $web);
    $get('categoría', '/categoria/ropa', $web);
    $get('ficha con variantes', '/producto/camiseta', $web);
    $get('ficha sin variantes', $url->entity('product', $simple), $web);
    $get('buscador', '/buscar?q=camiseta', $web);
    $get('buscador sin resultados', '/buscar?q=zzzzzzzz', $web);
    $get('carrito vacío', '/carrito', $web);
    $get('entrar', '/mi-cuenta/entrar', $web);
    $get('registro', '/mi-cuenta/registro', $web);
    $get('blog', '/blog', $web);
    $get('página', $url->entity('page', $page), $web);
    $get('entrada', $url->entity('post', $post), $web);
    $get('404', '/no-existe-esta-pagina', $web);
    // Con algo en el carrito: el carrito lleno y la caja; después, el pedido hecho (la página de gracias) y la cuenta.
    frontAdd($web, $simple, 1);
    $get('carrito lleno', '/carrito', $web);
    $get('caja', '/finalizar-compra', $web);
    $checkout = $web->get('/finalizar-compra');
    $place = $web->post('/finalizar-compra/place', frontForm($web, frontFill(), $checkout->body));
    if ($place->status === 303) {
        $thanks = $web->follow();
        $pages['gracias'] = [$thanks->status, $thanks->body];
    }
    aifecCartCustomer($app, 'luis@cliente.test');
    if (frontLogin($web, 'luis@cliente.test') !== null) {
        foreach (['cuenta' => '/mi-cuenta', 'pedidos' => '/mi-cuenta/pedidos', 'direcciones' => '/mi-cuenta/direcciones'] as $label => $path) {
            $get($label, $path, $web);
        }
    }

    return $pages;
}

test('ninguna página de la tienda rompe una regla del árbol de accesibilidad, con cada tema que existe', function (): void {
    $themes = ariaThemes();
    check(in_array('base', $themes, true), 'existe el tema base');
    $all = [];
    foreach ($themes as $theme) {
        $pages = ariaPages($theme);
        foreach (['portada', 'categoría', 'ficha con variantes', 'ficha sin variantes', 'buscador', 'carrito lleno', 'caja', 'gracias', 'cuenta', 'blog', 'página', 'entrada', '404'] as $label) {
            check(isset($pages[$label]), $theme . ': falta la página «' . $label . '»');
        }
        // Que la ficha con variantes lleve de verdad sus radios: si no, la prueba no vigilaría el selector de opciones.
        check(strpos($pages['ficha con variantes'][1], 'name="opt[talla]"') !== false, $theme . ': la ficha con variantes lleva sus radios de opción');
        check(strpos($pages['portada'][1], 'data-af-assistant') !== false, $theme . ': la portada lleva el asistente');
        foreach ($pages as $label => $page) {
            check(in_array($page[0], [200, 404], true), $theme . ' · ' . $label . ' responde ' . $page[0]);
            foreach (ariaProblems($page[1]) as $problem) {
                $all[] = $theme . ' · ' . $label . ' · ' . $problem;
            }
        }
    }
    same([], $all, 'problemas del árbol de accesibilidad');
});

// ---------------------------------------------------------------------------------------------------- estabilidad visual

/**
 * Las imágenes de un HTML sin ancho o sin alto: reservan su hueco antes de cargar, y sin eso la página salta (CLS).
 *
 * @return string[]
 */
function ariaImagesWithoutSize(string $html): array
{
    $html = (string) preg_replace('~<(script|style|template)\b[^>]*>.*?</\1>~is', '', $html);
    $bad = [];
    if (preg_match_all('~<(img|iframe)\b[^>]*>~i', $html, $tags) !== false) {
        foreach ($tags[0] as $tag) {
            if (preg_match('/\swidth="[1-9][0-9]*"/', $tag) !== 1 || preg_match('/\sheight="[1-9][0-9]*"/', $tag) !== 1) {
                $bad[] = mb_substr($tag, 0, 140, 'UTF-8');
            }
        }
    }

    return $bad;
}

test('ariaImagesWithoutSize caza una imagen sin ancho o sin alto y deja pasar la que tiene los dos', function (): void {
    same([], ariaImagesWithoutSize('<img src="/a.png" alt="" width="480" height="480"><iframe src="/m" title="m" width="600" height="400"></iframe>'));
    same(1, count(ariaImagesWithoutSize('<img src="/a.png" alt="" height="480">')));
    same(1, count(ariaImagesWithoutSize('<img src="/a.png" alt="" width="480">')));
    same(1, count(ariaImagesWithoutSize('<img src="/a.png" alt="" width="0" height="0">')));
    same(1, count(ariaImagesWithoutSize('<IMG SRC="/a.png" alt="">')));
    same(1, count(ariaImagesWithoutSize('<iframe src="/m" title="m"></iframe>')));
    same([], ariaImagesWithoutSize('<script>var s = "<img src=x>";</script>'));
});

test('toda imagen de las páginas de la tienda lleva width y height, con cada tema', function (): void {
    $bad = [];
    foreach (ariaThemes() as $theme) {
        foreach (ariaPages($theme) as $label => $page) {
            foreach (ariaImagesWithoutSize($page[1]) as $tag) {
                $bad[] = $theme . ' · ' . $label . ' · ' . $tag;
            }
        }
    }
    same([], $bad, 'imágenes sin ancho y alto');
});

test('ninguna plantilla de la tienda escribe una imagen sin width y height', function (): void {
    $root = dirname(__DIR__, 2);
    $files = [];
    foreach (['themes/*/templates', 'modules/*/views/front', 'modules/*/views'] as $pattern) {
        foreach (glob($root . '/' . $pattern, GLOB_ONLYDIR) ?: [] as $dir) {
            if (strpos(str_replace('\\', '/', $dir), '/views/admin') !== false) {
                continue;
            }
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                $path = str_replace('\\', '/', (string) $file);
                if (substr($path, -4) === '.php' && strpos($path, '/views/admin/') === false && strpos($path, '/admin/') === false) {
                    $files[$path] = true;
                }
            }
        }
    }
    check(count($files) > 100, 'se recorren las plantillas (' . count($files) . ')');
    $bad = [];
    foreach (array_keys($files) as $path) {
        $code = (string) file_get_contents($path);
        // Una etiqueta puede llevar un trozo de PHP dentro, y el «>» que lo cierra no es el de la etiqueta.
        if (preg_match_all('~<(?:img|iframe)\b(?:<\?.*?\?>|[^>])*>~s', $code, $tags) !== false) {
            foreach ($tags[0] as $tag) {
                if (preg_match('/\swidth=/', $tag) !== 1 || preg_match('/\sheight=/', $tag) !== 1) {
                    $bad[] = substr($path, strlen($root) + 1) . ' · ' . mb_substr(preg_replace('/\s+/', ' ', $tag), 0, 120, 'UTF-8');
                }
            }
        }
    }
    same([], $bad, 'plantillas con una imagen sin ancho y alto');
});

test('lo que llega después no empuja la página: el aviso de cookies, el asistente, las notificaciones, los paneles y la barra de móvil son fijos, y el contador del carrito va dentro de su botón', function (): void {
    $root = dirname(__DIR__, 2);
    $css = static function (string $path) use ($root): string {
        $text = (string) file_get_contents($root . '/' . $path);

        return (string) preg_replace('/\s+/', '', (string) preg_replace('~/\*.*?\*/~s', '', $text));
    };
    contains('.af-consent{position:fixed', $css('modules/consent/assets/consent.css'), 'el aviso de cookies');
    contains('.af-assistant-fab{position:fixed', $css('modules/assistant/assets/assistant.css'), 'el botón del asistente');
    contains('.af-assistant-panel{position:fixed', $css('modules/assistant/assets/assistant.css'), 'la ventana del asistente');
    $theme = $css('themes/base/assets/css/theme.css');
    foreach (['.af-toasts{position:fixed', '.af-drawer{position:fixed', '.af-drawer-backdrop{position:fixed', '.af-mobile-bar{display:flex;position:fixed', '.af-cart-count{position:absolute', '.af-icon-btn{position:relative'] as $rule) {
        contains($rule, $theme, $rule);
    }
    contains('aspect-ratio:', $theme, 'las tarjetas y la galería reservan su proporción');
});
