<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Catalog;

use Aifec\Kernel\App;

/**
 * El precio dicho siempre de las dos maneras: la base sin impuestos y el total con ellos, con la divisa, el tipo aplicado y el país al que
 * pertenece ese tipo. Una persona (o un agente) que compra para una empresa necesita la base; quien compra como particular, el total; y
 * ninguno tiene por qué adivinar cuál de las dos cifras es «el precio». Lo usan la ficha, la versión en texto, la API, MCP y los feeds.
 */
final class PriceDetail
{
    /**
     * @param array<string,mixed> $price un bloque de precio (Pricing::block)
     *
     * @return array{currency:string,net:int,tax:int,gross:int,tax_rate:float|int,tax_name:string,shown:string,country:string}|null null si el precio no se enseña o no trae los importes
     */
    public static function of(App $app, array $price): ?array
    {
        if (!empty($price['hidden']) || !isset($price['net'], $price['gross'], $price['tax'])) {
            return null;
        }
        $rate = isset($price['tax_rate']) ? (int) $price['tax_rate'] : 0;
        $percent = $rate % 100 === 0 ? intdiv($rate, 100) : round($rate / 100, 2);
        $country = strtoupper(trim($app->settings()->str('tax.base_country', 'ES')));

        return [
            'currency' => isset($price['currency']) && $price['currency'] !== '' ? (string) $price['currency'] : $app->settings()->str('shop.currency', 'EUR'),
            'net' => (int) $price['net'],
            'tax' => (int) $price['tax'],
            'gross' => (int) $price['gross'],
            'tax_rate' => $percent,
            // El nombre del impuesto sin su tipo («IVA 21 %» → «IVA»): el tipo va aparte.
            'tax_name' => isset($price['tax_name']) ? trim((string) preg_replace('/\s*[0-9][0-9.,]*\s*%\s*$/u', '', (string) $price['tax_name'])) : '',
            'shown' => !empty($price['with_tax']) ? 'gross' : 'net',
            'country' => $country !== '' ? $country : 'ES',
        ];
    }

    /**
     * La línea en el idioma de la persona: «Base 16,52 € · IVA 21 % 3,47 € · Total con IVA 19,99 € (ES)».
     *
     * @param array{currency:string,net:int,tax:int,gross:int,tax_rate:float|int,tax_name:string,shown:string,country:string} $detail
     */
    public static function line(App $app, array $detail): string
    {
        $i18n = $app->i18n();
        $name = $detail['tax_name'] !== '' ? $detail['tax_name'] : t('Tax');
        if ($detail['tax'] === 0 && $detail['net'] === $detail['gross']) {
            return t('{gross}, no {name} applied ({rate} %, {country})', [
                'gross' => $i18n->money($detail['gross'], $detail['currency']), 'name' => $name, 'rate' => $detail['tax_rate'], 'country' => $detail['country'],
            ]);
        }

        return t('Base {net} · {name} {rate} % {tax} · Total with {name} {gross} ({country})', [
            'net' => $i18n->money($detail['net'], $detail['currency']), 'name' => $name, 'rate' => $detail['tax_rate'],
            'tax' => $i18n->money($detail['tax'], $detail['currency']), 'gross' => $i18n->money($detail['gross'], $detail['currency']), 'country' => $detail['country'],
        ]);
    }
}
