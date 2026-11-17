<?php
/**
 * AIFEC — consentimiento: bloques de contenido para la política de cookies (la tabla de cookies y el botón de preferencias).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Consent\ConsentService;

return [
    'consent-cookies' => static function (array $attrs): string {
        /** @var ConsentService $consent */
        $consent = app()->service('consent');

        return $consent->cookieTable();
    },
    'consent-preferences' => static function (array $attrs): string {
        /** @var ConsentService $consent */
        $consent = app()->service('consent');

        return '<p>' . $consent->preferencesButton() . '</p>';
    },
];
