<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

/*
 * El contenedor de Google Tag Manager que se entrega (modules/datalayer/gtm/aifec-gtm-ga4.json): es JSON válido con la forma de una
 * exportación de GTM, tiene un activador y una etiqueta de GA4 por cada evento que la capa de datos de AIFEC empuja al navegador, y
 * todas las etiquetas exigen el consentimiento de estadísticas. Si se añade un evento a la capa de datos y no al contenedor, esta
 * prueba falla.
 */

/** @return array<string,mixed> */
function gtmContainer(): array
{
    $file = dirname(__DIR__) . '/gtm/aifec-gtm-ga4.json';
    check(is_file($file), 'el contenedor está en modules/datalayer/gtm');
    $data = json_decode((string) file_get_contents($file), true);
    check(is_array($data), 'es JSON válido: ' . json_last_error_msg());

    return $data;
}

test('el contenedor de GTM tiene la forma de una exportación y una variable con el ID de medición', function (): void {
    $data = gtmContainer();
    same(2, $data['exportFormatVersion']);
    $version = $data['containerVersion'];
    foreach (['tag', 'trigger', 'variable', 'builtInVariable', 'container'] as $part) {
        check(isset($version[$part]) && is_array($version[$part]) && $version[$part] !== [], 'lleva ' . $part);
    }
    same(['WEB'], $version['container']['usageContext']);
    $measurement = null;
    foreach ($version['variable'] as $variable) {
        if ($variable['name'] === 'AIFEC - ID de medición de GA4') {
            $measurement = $variable;
        }
    }
    check($measurement !== null && $measurement['type'] === 'c', 'la variable constante con el ID de medición');
    same(1, preg_match('/^G-[A-Z0-9]{4,14}$/', (string) $measurement['parameter'][0]['value']), 'con la forma de un ID de GA4 (G-XXXXXXXXXX hasta que se cambie)');
    // Los identificadores no se repiten y cada etiqueta dispara con un activador que existe.
    $triggerIds = array_column($version['trigger'], 'triggerId');
    same(count($triggerIds), count(array_unique($triggerIds)), 'activadores sin repetir');
    $tagIds = array_column($version['tag'], 'tagId');
    same(count($tagIds), count(array_unique($tagIds)), 'etiquetas sin repetir');
    $builtIn = ['2147479553', '2147479572', '2147479573'];
    foreach ($version['tag'] as $tag) {
        foreach ($tag['firingTriggerId'] as $id) {
            check(in_array($id, $triggerIds, true) || in_array($id, $builtIn, true), $tag['name'] . ': el activador ' . $id . ' existe');
        }
    }
});

test('cada evento que la capa de datos de AIFEC empuja al navegador tiene su activador y su etiqueta de GA4 en el contenedor', function (): void {
    $version = gtmContainer()['containerVersion'];
    $events = [];
    foreach ($version['trigger'] as $trigger) {
        $events[] = $trigger['customEventFilter'][0]['parameter'][1]['value'];
    }
    $tagEvents = [];
    foreach ($version['tag'] as $tag) {
        if ($tag['type'] === 'gaawe') {
            foreach ($tag['parameter'] as $parameter) {
                if ($parameter['key'] === 'eventName') {
                    $tagEvents[] = $parameter['value'];
                }
            }
        }
    }
    sort($events);
    sort($tagEvents);
    same($events, $tagEvents, 'un activador y una etiqueta por evento');

    // Lo que empuja AIFEC: el guion del navegador y el constructor de eventos del servidor (menos el reembolso, que va por el servidor).
    $source = (string) file_get_contents(dirname(__DIR__) . '/assets/datalayer.js') . (string) file_get_contents(dirname(__DIR__) . '/src/EventBuilder.php');
    $source .= (string) file_get_contents(dirname(__DIR__, 2) . '/assistant/assets/assistant.js');
    preg_match_all("/event(?:'\\s*=>|:)\\s*'([a-z_]+)'/", $source, $m);
    $pushed = array_values(array_diff(array_unique($m[1]), ['refund', 'gtm.js']));
    foreach (['login', 'sign_up'] as $viaCookie) {
        if (!in_array($viaCookie, $pushed, true)) {
            $pushed[] = $viaCookie;
        }
    }
    sort($pushed);
    foreach ($pushed as $event) {
        check(in_array($event, $events, true), 'el evento «' . $event . '» que empuja AIFEC está en el contenedor');
    }
    foreach ($events as $event) {
        check(in_array($event, $pushed, true), 'el evento «' . $event . '» del contenedor lo empuja AIFEC');
    }
});

test('todas las etiquetas esperan el consentimiento de estadísticas y los eventos de comercio leen la capa de datos', function (): void {
    $version = gtmContainer()['containerVersion'];
    foreach ($version['tag'] as $tag) {
        same('NEEDED', $tag['consentSettings']['consentStatus'], $tag['name'] . ' exige consentimiento');
        same('analytics_storage', $tag['consentSettings']['consentType']['list'][0]['map'][0]['value'], $tag['name']);
        if ($tag['type'] === 'gaawe') {
            $keys = array_column($tag['parameter'], 'value', 'key');
            same('{{AIFEC - ID de medición de GA4}}', $keys['measurementIdOverride'], $tag['name'] . ' usa la variable con el ID');
            if ($keys['sendEcommerceData'] === 'true') {
                same('dataLayer', $keys['getEcommerceDataFrom'], $tag['name'] . ' lee el comercio de la capa de datos');
            }
        }
    }
    $google = array_values(array_filter($version['tag'], static function (array $tag): bool {
        return $tag['type'] === 'googtag';
    }));
    same(1, count($google), 'una sola etiqueta de Google');
    same(['2147479573'], $google[0]['firingTriggerId'], 'que se lanza al inicializar en todas las páginas');
    // Los parámetros de los eventos sueltos salen de variables de la capa de datos que existen.
    $variables = array_column($version['variable'], 'name');
    foreach ($version['tag'] as $tag) {
        foreach ($tag['parameter'] as $parameter) {
            if ($parameter['key'] === 'eventSettingsTable') {
                foreach ($parameter['list'] as $row) {
                    $value = $row['map'][1]['value'];
                    check(in_array(trim($value, '{}'), $variables, true), $tag['name'] . ': la variable ' . $value . ' existe');
                }
            }
        }
    }
});
