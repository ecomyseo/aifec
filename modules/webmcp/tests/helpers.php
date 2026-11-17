<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * Ayudas de las pruebas de WebMCP:
 *
 *   webmcpApp(array $ajustes = [], array $modulos = []): App
 *       La tienda de pruebas del asistente (carrito, pedidos, buscador) con el módulo webmcp. $ajustes son ajustes del sitio.
 *
 *   webmcpTools(App $app): array
 *       La tabla de herramientas compartida (servicio «tools»): nombre => definición.
 *
 *   webmcpList(TestClient $web): array
 *       La lista de /webmcp/tools.json ya descodificada: nombre => herramienta.
 *
 *   webmcpFormProblems(string $html): array
 *       Lo que le falta a los formularios marcados de una página: nombre y descripción de la herramienta, descripción de
 *       cada campo, nombres repetidos y nombres mal escritos.
 *
 *   webmcpSchemaProblems(array $esquema, string $ruta = 'input'): array
 *       Lo que está mal en un esquema JSON de entrada (el trozo de JSON Schema que usan las herramientas).
 *
 *   webmcpLlmsProblems(string $texto): array
 *       Lo que le falta a un llms.txt para cumplir el formato: título, resumen en cita y secciones con listas de enlaces.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;

require_once __DIR__ . '/../../assistant/tests/helpers.php';
require_once __DIR__ . '/../../semantic/tests/helpers.php';
require_once __DIR__ . '/../../orders/tests/FrontKit.php';

if (!function_exists('webmcpApp')) {
    /**
     * @param array<string,mixed> $ajustes
     * @param string[]            $modulos
     */
    function webmcpApp(array $ajustes = [], array $modulos = []): App
    {
        return assistantApp($ajustes, ['modules' => array_merge(['webmcp'], $modulos)]);
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    function webmcpTools(App $app): array
    {
        return $app->service('tools')->all();
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    function webmcpList(TestClient $web): array
    {
        $r = $web->get('/webmcp/tools.json');
        check($r->status === 200, '/webmcp/tools.json responde ' . $r->status);
        $data = json_decode($r->body, true);
        $out = [];
        foreach (is_array($data) && isset($data['tools']) && is_array($data['tools']) ? $data['tools'] : [] as $tool) {
            $out[(string) $tool['name']] = $tool;
        }

        return $out;
    }

    /**
     * @return string[]
     */
    function webmcpFormProblems(string $html): array
    {
        $dom = new DOMDocument();
        $before = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($before);
        $xp = new DOMXPath($dom);
        $problems = [];
        $names = [];
        foreach ($xp->query('//form[@toolname or @tooldescription]') ?: [] as $form) {
            /** @var DOMElement $form */
            $name = trim($form->getAttribute('toolname'));
            $names[] = $name;
            if ($name === '') {
                $problems[] = 'un formulario con descripción de herramienta y sin nombre';
                continue;
            }
            if (preg_match('/^[a-z][a-z0-9_]{1,63}$/', $name) !== 1) {
                $problems[] = $name . ': el nombre no es válido (minúsculas, cifras y guion bajo)';
            }
            if (strlen(trim($form->getAttribute('tooldescription'))) < 12) {
                $problems[] = $name . ': le falta la descripción de la herramienta';
            }
            foreach ($xp->query('.//input | .//select | .//textarea', $form) ?: [] as $field) {
                /** @var DOMElement $field */
                $type = strtolower($field->getAttribute('type'));
                if ($field->getAttribute('name') === '' || in_array($type, ['submit', 'button', 'image', 'reset'], true)) {
                    continue;
                }
                if (strlen(trim($field->getAttribute('toolparamdescription'))) < 4) {
                    $problems[] = $name . ': al campo «' . $field->getAttribute('name') . '» le falta toolparamdescription';
                }
            }
        }
        foreach (array_count_values($names) as $name => $times) {
            if ($times > 1) {
                $problems[] = $name . ': el nombre se repite ' . $times . ' veces en la página';
            }
        }
        foreach ($xp->query('//*[@toolparamdescription][not(ancestor::form[@toolname])]') ?: [] as $orphan) {
            /** @var DOMElement $orphan */
            $problems[] = 'toolparamdescription fuera de un formulario marcado: ' . $orphan->getAttribute('name');
        }

        return $problems;
    }

    /**
     * @param array<string,mixed> $esquema
     *
     * @return string[]
     */
    function webmcpSchemaProblems(array $esquema, string $ruta = 'input'): array
    {
        $problems = [];
        $types = ['object', 'string', 'integer', 'number', 'boolean', 'array'];
        $type = isset($esquema['type']) ? $esquema['type'] : null;
        if (!is_string($type) || !in_array($type, $types, true)) {
            return [$ruta . ': type tiene que ser uno de ' . implode(', ', $types)];
        }
        $known = ['type', 'description', 'default', 'enum', 'properties', 'required', 'additionalProperties', 'minimum', 'maximum', 'minLength', 'maxLength', 'minItems', 'maxItems', 'items', 'title'];
        foreach (array_keys($esquema) as $key) {
            if (!in_array($key, $known, true)) {
                $problems[] = $ruta . ': la palabra «' . $key . '» no es del trozo de JSON Schema que se usa';
            }
        }
        if (isset($esquema['description']) && (!is_string($esquema['description']) || trim($esquema['description']) === '')) {
            $problems[] = $ruta . ': description vacía';
        }
        if ($type === 'object') {
            $props = isset($esquema['properties']) ? $esquema['properties'] : [];
            if (!is_array($props)) {
                return [$ruta . ': properties tiene que ser un objeto'];
            }
            if (isset($esquema['required'])) {
                if (!is_array($esquema['required']) || $esquema['required'] !== array_values(array_filter($esquema['required'], 'is_string'))) {
                    $problems[] = $ruta . ': required tiene que ser una lista de nombres';
                } else {
                    foreach ($esquema['required'] as $name) {
                        if (!array_key_exists($name, $props)) {
                            $problems[] = $ruta . ': required pide «' . $name . '», que no está en properties';
                        }
                    }
                }
            }
            if (isset($esquema['additionalProperties']) && !is_bool($esquema['additionalProperties'])) {
                $problems[] = $ruta . ': additionalProperties tiene que ser verdadero o falso';
            }
            foreach ($props as $name => $prop) {
                if (!is_array($prop)) {
                    $problems[] = $ruta . '.' . $name . ': la propiedad tiene que ser un objeto';
                    continue;
                }
                $problems = array_merge($problems, webmcpSchemaProblems($prop, $ruta . '.' . $name));
            }
        }
        if ($type === 'array' && isset($esquema['items'])) {
            $problems = is_array($esquema['items']) ? array_merge($problems, webmcpSchemaProblems($esquema['items'], $ruta . '[]')) : array_merge($problems, [$ruta . ': items tiene que ser un esquema']);
        }
        if (in_array($type, ['integer', 'number'], true)) {
            foreach (['minimum', 'maximum'] as $key) {
                if (isset($esquema[$key]) && !is_numeric($esquema[$key])) {
                    $problems[] = $ruta . ': ' . $key . ' tiene que ser un número';
                }
            }
            if (isset($esquema['minimum'], $esquema['maximum']) && $esquema['minimum'] > $esquema['maximum']) {
                $problems[] = $ruta . ': minimum es mayor que maximum';
            }
        }
        if ($type === 'string' && isset($esquema['minLength'], $esquema['maxLength']) && $esquema['minLength'] > $esquema['maxLength']) {
            $problems[] = $ruta . ': minLength es mayor que maxLength';
        }
        if (isset($esquema['enum']) && (!is_array($esquema['enum']) || $esquema['enum'] === [])) {
            $problems[] = $ruta . ': enum tiene que ser una lista con algo dentro';
        }
        if (array_key_exists('default', $esquema)) {
            $default = $esquema['default'];
            $ok = ($type === 'integer' && is_int($default)) || ($type === 'number' && is_numeric($default)) || ($type === 'boolean' && is_bool($default))
                || ($type === 'string' && is_string($default)) || ($type === 'array' && is_array($default)) || ($type === 'object' && is_array($default));
            if (!$ok) {
                $problems[] = $ruta . ': default no es del tipo ' . $type;
            }
            if (isset($esquema['enum']) && is_array($esquema['enum']) && !in_array($default, $esquema['enum'], true)) {
                $problems[] = $ruta . ': default no está en enum';
            }
        }

        return $problems;
    }

    /**
     * El formato de llms.txt (llmstxt.org): un H1 con el nombre, un resumen en cita, texto libre opcional sin encabezados y
     * secciones H2 que son listas de enlaces «- [título](dirección)» con notas opcionales tras dos puntos.
     *
     * @return string[]
     */
    function webmcpLlmsProblems(string $text): array
    {
        $problems = [];
        if ($text === '' || substr($text, -1) !== "\n") {
            $problems[] = 'el fichero tiene que acabar en salto de línea';
        }
        if (preg_match('/[^\x09\x0A\x0D\x20-\x{10FFFF}]/u', $text) === 1 || !mb_check_encoding($text, 'UTF-8')) {
            $problems[] = 'no es UTF-8 limpio';
        }
        $lines = explode("\n", rtrim($text, "\n"));
        if (!isset($lines[0]) || preg_match('/^# \S.*$/', $lines[0]) !== 1) {
            $problems[] = 'la primera línea tiene que ser el título: «# Nombre»';
        }
        $next = 1;
        while (isset($lines[$next]) && trim($lines[$next]) === '') {
            $next++;
        }
        if (!isset($lines[$next]) || preg_match('/^> \S.*$/', $lines[$next]) !== 1) {
            $problems[] = 'después del título tiene que venir el resumen en cita: «> resumen»';
        }
        $section = '';
        $items = [];
        $sections = 0;
        $urls = [];
        for ($i = $next + 1; $i < count($lines); $i++) {
            $line = $lines[$i];
            if (preg_match('/^#+ /', $line) === 1) {
                if (preg_match('/^## \S.*$/', $line) !== 1) {
                    $problems[] = 'línea ' . ($i + 1) . ': solo se admiten encabezados de segundo nivel («## Sección»): ' . $line;
                    continue;
                }
                if ($section !== '' && ($items[$section] ?? 0) === 0) {
                    $problems[] = 'la sección «' . $section . '» no tiene ningún enlace';
                }
                $section = substr($line, 3);
                $items[$section] = 0;
                $sections++;
                continue;
            }
            if (trim($line) === '') {
                continue;
            }
            if ($section === '') {
                // Entre el resumen y la primera sección puede haber texto libre (sin encabezados).
                continue;
            }
            // El título puede llevar corchetes escapados con barra invertida (Markdown).
            if (preg_match('/^( {0,2})- \[((?:\\\\.|[^\]\\\\\n])+)\]\((https?:\/\/[^\s)]+)\)(: \S.*)?$/u', $line, $m) !== 1) {
                $problems[] = 'línea ' . ($i + 1) . ': en una sección solo van enlaces «- [título](dirección)»: ' . mb_substr($line, 0, 80, 'UTF-8');
                continue;
            }
            $items[$section]++;
            if (isset($urls[$m[3]])) {
                $problems[] = 'línea ' . ($i + 1) . ': el enlace ' . $m[3] . ' sale dos veces';
            }
            $urls[$m[3]] = true;
        }
        if ($section !== '' && ($items[$section] ?? 0) === 0) {
            $problems[] = 'la sección «' . $section . '» no tiene ningún enlace';
        }
        if ($sections === 0) {
            $problems[] = 'no hay ninguna sección «## …» con enlaces';
        }
        foreach ($lines as $i => $line) {
            if ($i > 0 && preg_match('/^# /', $line) === 1) {
                $problems[] = 'línea ' . ($i + 1) . ': hay más de un título de primer nivel';
            }
        }

        return $problems;
    }
}
