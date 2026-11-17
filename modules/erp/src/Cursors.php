<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Erp;

use Aifec\Kernel\App;

/**
 * Por dónde va cada perfil «solo lo nuevo». La marca avanza por lo CONSUMIDO (lo que se ha entregado de verdad) y nunca por un
 * recuento; un envío que falla no la mueve.
 */
final class Cursors
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function get(string $scope): ?string
    {
        $value = $this->app->db()->value('SELECT value FROM {erp_cursors} WHERE scope = ?', [$scope]);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function set(string $scope, string $value): void
    {
        $this->app->db()->upsert('erp_cursors', ['scope' => $scope, 'value' => $value, 'updated_at' => $this->app->clock()->sql()], ['scope']);
    }

    public function forget(string $scope): void
    {
        $this->app->db()->delete('erp_cursors', ['scope' => $scope]);
    }

    public static function scope(int $profileId): string
    {
        return 'export:' . $profileId;
    }
}
