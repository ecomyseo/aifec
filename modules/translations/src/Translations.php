<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Translations;

use Aifec\Kernel\App;

/**
 * El servicio «translations»: la tabla de cobertura de un idioma, la estimación de lo que costaría que la IA rellenara una fila, el
 * catálogo del sitio y los trabajos de la IA. Lo usan el panel, la consola y quien quiera saber cuánto falta.
 */
final class Translations
{
    /** Fichas fijas de cada encargo (instrucciones, contexto, glosario y forma de la respuesta), para la estimación. */
    private const OVERHEAD = 450;

    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * Los idiomas de la tienda que no son el principal (una pestaña cada uno).
     *
     * @return string[]
     */
    public function languages(): array
    {
        $default = $this->app->defaultLang();

        return array_values(array_filter($this->app->languages(), static function (string $lang) use ($default): bool {
            return $lang !== $default;
        }));
    }

    /**
     * La tabla de cobertura de un idioma: una fila por Scope, con sus números y su estimación.
     *
     * @return array<int,array<string,mixed>>
     */
    public function coverage(string $lang): array
    {
        $rows = [];
        foreach (Scopes::all($this->app) as $code => $scope) {
            $numbers = $scope->coverage($lang);
            $rows[] = [
                'code' => $code,
                'label' => $scope->label(),
                'describe' => $scope->describe(),
                'total' => $numbers['total'],
                'translated' => $numbers['translated'],
                'proposed' => $numbers['proposed'],
                'missing' => $numbers['missing'],
                'percent' => $numbers['total'] > 0 ? (int) floor($numbers['translated'] * 100 / $numbers['total']) : 100,
                'blocked' => $scope->blocked($lang),
                'approval_needs_ai' => $scope->approvalNeedsAi(),
                'view_url' => $scope->viewUrl($lang),
                'estimate' => $this->estimate($scope, $numbers['missing']),
            ];
        }

        return $rows;
    }

    /**
     * Lo que costaría rellenar con la IA lo que falta en una fila (una estimación, no una medida).
     *
     * @return array{tokens_in:int,tokens_out:int,cost_cents:int}
     */
    public function estimate(Scope $scope, int $missing): array
    {
        if ($missing <= 0) {
            return ['tokens_in' => 0, 'tokens_out' => 0, 'cost_cents' => 0];
        }
        [$in, $out] = $scope->tokensPerUnit();
        $requests = (int) ceil($missing / Fill::CHUNK_UNITS);
        $tokensIn = $missing * $in + $requests * self::OVERHEAD;
        $tokensOut = $missing * $out;
        $settings = $this->app->settings();
        // Precios del nivel económico en céntimos por millón de fichas (los del módulo de IA).
        $micro = $tokensIn * max(0, $settings->int('ai.economy.price_in', 0)) + $tokensOut * max(0, $settings->int('ai.economy.price_out', 0));

        return ['tokens_in' => $tokensIn, 'tokens_out' => $tokensOut, 'cost_cents' => intdiv($micro + 999999, 1000000)];
    }

    public function catalog(): SiteCatalog
    {
        return new SiteCatalog($this->app);
    }

    public function fill(): Fill
    {
        return new Fill($this->app);
    }
}
