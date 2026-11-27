<?php
/** AIFEC — destino indicado por el visitante para una cotización, fuera de la dirección del pedido. @license Ver LICENSE.md */
declare(strict_types=1);

namespace Aifec\Modules\Assistant;

use Aifec\Geo\Countries;
use Aifec\Geo\Spain;

final class QuoteDestination
{
    /** Un destino nombrado dentro de una pregunta de transporte o como respuesta al código postal. */
    public static function named(string $normal, bool $waiting, string $lang = 'es'): ?array
    {
        // Cash on delivery and pago contra entrega name a payment method.
        $intent = str_replace(['cash on delivery', 'pago contra entrega', 'pago a la entrega'], ' ', $normal);
        if (!$waiting && !preg_match('/\b(?:envios?|enviais|envian|enviar|entrega|entregais|mandais|vivo en|resido en|shipping|ship|deliver|delivery|i live in)\b/', $intent)) {
            return null;
        }
        static $regions;
        static $labels;
        if ($regions === null) {
            $regions = [];
            $labels = [];
            foreach (Spain::provinces() as $province) {
                foreach ([$province['region'], $province['name']] as $name) {
                    $key = RuleBrain::norm($name);
                    $labels[$key] = $name;
                    $regions[$key][] = (string) $province['prefix'];
                }
            }
            foreach (['tenerife' => ['38'], 'la gomera' => ['38'], 'el hierro' => ['38'], 'la palma del condado' => ['21'], 'la palma' => ['38'], 'mallorca' => ['07'], 'menorca' => ['07'], 'ibiza' => ['07'], 'formentera' => ['07'], 'gran canaria' => ['35'], 'lanzarote' => ['35'], 'fuerteventura' => ['35'], 'canary islands' => ['35','38']] as $name => $codes) {
                $regions[$name] = $codes;
                $labels[$name] = ucwords($name);
            }
            uksort($regions, static function (string $a, string $b): int { return strlen($b) <=> strlen($a); });
        }
        $haystack = ' ' . $normal . ' ';
        static $countries;
        if ($countries === null) {
            $countries = ['uk' => 'GB', 'usa' => 'US', 'ee uu' => 'US'];
            foreach (['en', 'es', 'fr', 'de', 'it', 'pt', 'nl'] as $locale) {
                foreach (Countries::all($locale) as $code => $name) {
                    $key = RuleBrain::norm($name);
                    if (strlen($key) > 2) {
                        $countries[$key] = $code;
                    }
                }
            }
            uksort($countries, static function (string $a, string $b): int { return strlen($b) <=> strlen($a); });
        }
        $country = null;
        foreach ($countries as $name => $code) {
            if (strpos($haystack, ' ' . $name . ' ') !== false) {
                $country = ['country' => $code, 'prefixes' => [], 'name' => Countries::name($code, $lang)];
                break;
            }
        }
        if ($country !== null && $country['country'] !== 'ES') {
            return $country;
        }
        foreach ($regions as $name => $codes) {
            if (strpos($haystack, ' ' . $name . ' ') !== false) {
                return ['country' => 'ES', 'prefixes' => array_values(array_unique($codes)), 'name' => $labels[$name]];
            }
        }
        return $country;
    }

    /** Solo usa la dirección de la cuenta ante una pregunta de envío que no nombra ningún destino. */
    public static function savedFor(string $normal): ?array
    {
        if (!preg_match('/^(?:(?:cuanto (?:cuesta|vale)|que (?:cuesta|vale)|costes? de|gastos de|precio del?) (?:el |los )?envios?|envios?|cuando me (?:llegaria|llega)|cuanto (?:me )?falta para (?:el )?envio (?:gratis|gratuito)|how much is (?:the )?(?:shipping|delivery)|shipping costs?|when will it arrive)$/', $normal)) {
            return null;
        }
        $app = app();
        $customer = $app->has('customers') ? $app->service('customers')->current() : null;
        if (!$customer || !empty($customer['guest'])) { return null; }
        foreach ($app->service('customers')->addresses((int) $customer['id']) as $address) {
            if (!empty($address['default_shipping']) && !empty($address['postcode']) && !empty($address['country'])) {
                return ['country' => (string) $address['country'], 'postcode' => (string) $address['postcode']];
            }
        }
        return null;
    }

    public static function postcode(string $message, array $destination): string
    {
        if (!preg_match('/\b(?:[a-z]{1,2}\d[a-z\d]?\s?\d[a-z]{2}|\d{4}-\d{3}|\d{5}(?:-\d{4})?|\d{4}\s?[a-z]{2}|\d{4}|AD\d{3})\b/i', $message, $match)) {
            return '';
        }
        $postcode = strtoupper($match[0]);
        if (!Countries::validPostcode($destination['country'], $postcode)) {
            return '';
        }
        return empty($destination['prefixes']) || in_array(substr($postcode, 0, 2), $destination['prefixes'], true) ? $postcode : '';
    }
}
