<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Api;

/**
 * Cada campo de cada paso de «Compra con enlaces» (/buy-with-links), con su tipo, si es obligatorio y un ejemplo con datos
 * INVENTADOS, y los códigos de error con lo que hay que hacer. Es la única fuente: de aquí salen la propia página, el documento
 * OpenAPI y el llms.txt, así que no pueden contar cosas distintas.
 */
final class LinkFields
{
    /** Los datos del ejemplo: inventados, de nadie. */
    private const EXAMPLE = [
        'email' => 'ana@cliente.test', 'first_name' => 'Ana', 'last_name' => 'Ruiz Ejemplo', 'company' => 'Ejemplo S.L.', 'vat_id' => 'B12345674',
        'phone' => '+44 7700 900123', 'line1' => 'Calle de Ejemplo 1', 'line2' => '2.º B', 'postcode' => '28013', 'city' => 'Madrid', 'region' => 'M', 'country' => 'ES',
    ];

    /**
     * Los campos de cada paso: paso => nombre => [tipo, obligatorio (true, false o la condición), ejemplo, qué es].
     *
     * @return array<string,array<string,array{type:string,required:bool|string,example:string,note:string}>>
     */
    public static function steps(): array
    {
        $f = static function (string $type, $required, string $example, string $note): array {
            return ['type' => $type, 'required' => $required, 'example' => $example, 'note' => $note];
        };
        $e = self::EXAMPLE;

        return [
            'add' => [
                'product_id' => $f('integer', true, '12', 'The id of the product, as the catalogue gives it'),
                'variant_id' => $f('integer', 'only for a product with variants', '31', 'The option of the product (size, colour…); 0 or missing for a product without options'),
                'qty' => $f('integer', false, '1', 'Units, from 1 to 9999. Missing: 1'),
            ],
            'customer' => [
                'email' => $f('string, max 191', true, $e['email'], 'Where the shop writes to the buyer, including the verification code of the review'),
                'first_name' => $f('string, max 80', true, $e['first_name'], 'Name of the buyer. A word such as "yes" or "ok" is refused'),
                'last_name' => $f('string, max 120', false, $e['last_name'], 'Surname(s)'),
                'company' => $f('string, max 160', false, $e['company'], 'Only when buying as a company; send it empty to remove one saved before'),
                'vat_id' => $f('string, max 40', 'only when buying as a company', $e['vat_id'], 'Tax ID of the company, with or without country letters'),
                'phone' => $f('string, 6 to 15 digits', false, $e['phone'], 'With country code for a number outside Spain'),
                'line1' => $f('string, max 191', 'if the order ships', $e['line1'], 'Street and number'),
                'line2' => $f('string, max 191', false, $e['line2'], 'Floor, door, staircase. Send it empty to remove one saved before'),
                'postcode' => $f('string, max 16', 'if the order ships', $e['postcode'], 'It must be a valid postcode of the country'),
                'city' => $f('string, max 100', 'if the order ships', $e['city'], 'Town or city'),
                'region' => $f('string, max 16', false, $e['region'], 'Province or region code'),
                'country' => $f('string, 2 letters', false, $e['country'], 'ISO code of the country. Missing: the country of the shop'),
                'text' => $f('string', false, 'Ana Ruiz Ejemplo, Calle de Ejemplo 1, 28013 Madrid, +44 7700 900123, ana@cliente.test', 'The same data written freely, in any order. Only read when no separate field is sent'),
                'note' => $f('string', false, 'Call before delivering', 'A note for the order'),
            ],
            'shipping' => [
                'carrier_id' => $f('integer', true, '3', 'The id of one option of shipping-options'),
            ],
            'payment' => [
                'method' => $f('string', true, 'transfer', 'The code of one option of payment-options. Choosing it charges nothing: the person pays on the page of the shop'),
            ],
            'code' => [
                'confirm_token' => $f('string', true, 'a3f9c2d41b7e0865', 'The confirm_token the review gave: a long text, copy it as it is'),
                'digits' => $f('string, up to 6 digits', false, '48', 'The digits of the buyer code typed so far, for tools that can only open addresses'),
            ],
            'place' => [
                'confirm_token' => $f('string', true, 'a3f9c2d41b7e0865', 'The confirm_token the review gave: a long text, copy it as it is. Any change in the cart invalidates it'),
                'buyer_confirmed' => $f('boolean', true, 'true', 'The person has seen the total and said yes'),
                'buyer_code' => $f('string, 6 digits', true, '482913', 'The code the shop emailed to the buyer at the review'),
            ],
        ];
    }

    /**
     * Los errores: estado HTTP, cuándo sale y qué hacer.
     *
     * @return array<int,array{status:int,when:string,do:string}>
     */
    public static function errors(): array
    {
        return [
            ['status' => 400, 'when' => 'A value is missing or has the wrong type or size (the message names the field)', 'do' => 'Correct that field and open the same address again'],
            ['status' => 401, 'when' => 'The cart written in the address is not valid, or no longer exists', 'do' => 'Open the start step again and use the cart of its answer'],
            ['status' => 403, 'when' => 'The shop does not take orders placed by programs, or this browser cannot modify carts through links', 'do' => 'Do not retry: send the person to the checkout page of the shop'],
            ['status' => 404, 'when' => 'The step, the product or the option does not exist', 'do' => 'Check the steps in the guide and the ids in the catalogue'],
            ['status' => 409, 'when' => 'The cart has already been ordered', 'do' => 'Start a new cart; never place the same order twice'],
            ['status' => 422, 'when' => 'The cart is empty, the data of the buyer are incomplete, the total is above the highest amount for assistants, or the confirm_token is no longer valid', 'do' => 'Read the message: it says what is missing. If the cart changed after the review, review again'],
            ['status' => 429, 'when' => 'Too many requests, or too many wrong codes', 'do' => 'Wait the seconds of the Retry-After header (or a minute) and try again'],
        ];
    }

    /**
     * Los campos del paso «customer» como parámetros de consulta del documento OpenAPI: nombre => descripción. El «(required)» solo se
     * escribe cuando el campo es obligatorio SIEMPRE; si depende, se dice con otras palabras.
     *
     * @return array<string,string>
     */
    public static function query(): array
    {
        $out = [];
        foreach (self::steps()['customer'] as $name => $field) {
            $when = $field['required'] === true ? 'required for this step' : ($field['required'] === false ? 'optional' : $field['required']);
            $out[$name] = 'customer: ' . $field['note'] . ' (' . $field['type'] . '; ' . $when . '; example: ' . $field['example'] . ')';
        }

        return $out;
    }

    /**
     * El texto para la guía del documento OpenAPI.
     *
     * @return string[]
     */
    public static function guideLines(): array
    {
        $lines = ['', 'Buying with links: the fields of each step (name, type, required, example with made-up data):'];
        foreach (self::steps() as $step => $fields) {
            $parts = [];
            foreach ($fields as $name => $field) {
                $required = $field['required'] === true ? 'required' : ($field['required'] === false ? 'optional' : 'required ' . $field['required']);
                $parts[] = $name . ' (' . $field['type'] . ', ' . $required . ', e.g. ' . $field['example'] . ')';
            }
            $lines[] = '- ' . $step . ': ' . implode('; ', $parts) . '.';
        }
        $lines[] = 'Buying with links: the errors.';
        foreach (self::errors() as $error) {
            $lines[] = '- ' . $error['status'] . ': ' . $error['when'] . '. ' . $error['do'] . '.';
        }

        return $lines;
    }

    /** Una nota corta para el llms.txt: lo obligatorio de cada paso y los estados de error. */
    public static function compact(): string
    {
        $parts = [];
        foreach (self::steps() as $step => $fields) {
            $names = [];
            foreach ($fields as $name => $field) {
                if ($field['required'] === true) {
                    $names[] = $name;
                }
            }
            $parts[] = $step . ': ' . ($names !== [] ? implode(', ', $names) : 'no required field');
        }
        $codes = [];
        foreach (self::errors() as $error) {
            $codes[] = (string) $error['status'];
        }

        return 'Required fields by step: ' . implode('; ', $parts) . '. Each field has its type and an example on the page. Errors: ' . implode(', ', $codes) . ', explained on the page.';
    }
}
