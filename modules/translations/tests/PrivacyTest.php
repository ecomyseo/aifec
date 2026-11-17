<?php
/**
 * AIFEC — pruebas de que nunca se traduce un pedido, un cliente ni un dato personal: una fila que los lee (venga de un plugin) se
 * descarta, no se puede lanzar y su texto no viaja a la IA.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Translations\Brief;
use Aifec\Modules\Translations\Fill;
use Aifec\Modules\Translations\Scope;
use Aifec\Modules\Translations\Scopes;
use Aifec\Modules\Translations\Translations;

require_once __DIR__ . '/TranslationsKit.php';

if (!class_exists('TrxCustomersScope')) {
    /** La fila de un plugin que quiere traducir los nombres de los clientes (no debe poder). */
    final class TrxCustomersScope implements Scope
    {
        /** @var string[] */
        public $entities = ['customer'];

        public function code(): string
        {
            return 'customers';
        }

        public function label(): string
        {
            return 'Customers';
        }

        public function describe(): string
        {
            return 'Names of the customers';
        }

        public function entities(): array
        {
            return $this->entities;
        }

        public function sourceLang(): string
        {
            return 'es';
        }

        public function brief(): string
        {
            return 'Customer names.';
        }

        public function blocked(string $lang): string
        {
            return '';
        }

        public function coverage(string $lang): array
        {
            return ['total' => 1, 'translated' => 0, 'proposed' => 0, 'missing' => 1];
        }

        public function next(string $lang, string $after, int $maxUnits, int $maxChars): array
        {
            return $after === ''
                ? ['units' => [['key' => '1', 'text' => 'Zoe Cliente Ejemplo, zoe@cliente.test', 'format' => 'text']], 'cursor' => '1', 'done' => true]
                : ['units' => [], 'cursor' => $after, 'done' => true];
        }

        public function save(string $lang, array $texts, array $units, bool $publish, int $jobId): array
        {
            return [];
        }

        public function approveAll(string $lang, int $max = 1000): int
        {
            return 0;
        }

        public function approvalNeedsAi(): bool
        {
            return false;
        }

        public function viewUrl(string $lang): string
        {
            return '/admin';
        }

        public function rows(string $lang, int $page, int $perPage): ?array
        {
            return null;
        }

        public function tokensPerUnit(): array
        {
            return [10, 10];
        }
    }
}

/** Registra la fila de clientes de un «plugin». */
function trxAddCustomers(App $app, array $entities = ['customer']): TrxCustomersScope
{
    $scope = new TrxCustomersScope();
    $scope->entities = $entities;
    $app->hooks()->filter('translations.scopes', static function (array $list) use ($scope): array {
        $list[] = $scope;

        return $list;
    });

    return $scope;
}

test('una fila que lee clientes se descarta: ni sale en la tabla ni se puede lanzar', function (): void {
    [$app] = trxAiApp();
    trxAddCustomers($app);
    same(null, Scopes::get($app, 'customers'));
    $codes = array_column((new Translations($app))->coverage('fr'), 'code');
    check(!in_array('customers', $codes, true), 'no sale en la cobertura');
    $error = '';
    try {
        (new Fill($app))->start('fr', 'customers');
    } catch (\InvalidArgumentException $e) {
        $error = $e->getMessage();
    }
    same('unknown', $error);
});

test('con todas las filas lanzadas, ningún encargo lleva el nombre ni el correo de un cliente', function (): void {
    [$app, $ai] = trxAiApp();
    trxAddCustomers($app);
    aifecProduct($app, ['name' => 'Silla']);
    cmsInsertPage($app, ['title' => 'Envíos']);
    foreach (array_keys(Scopes::all($app)) as $code) {
        try {
            (new Fill($app))->start('fr', $code);
        } catch (\InvalidArgumentException $e) {
            // Las filas que no se rellenan con IA (categorías, correos) dicen por qué; no es un fallo.
        }
    }
    trxWork($app);
    check(count($ai->requests) >= 3, 'se han hecho encargos (' . count($ai->requests) . ')');
    $sent = trxSent($ai);
    notContains('Zoe Cliente', $sent);
    notContains('zoe@cliente.test', $sent);
    contains('Silla', $sent);
});

test('cualquier entidad de personas descarta la fila, y una fila que no dice qué lee también', function (): void {
    foreach (['order', 'Orders', 'address', 'users', 'review', 'form_entries', 'conversation'] as $entity) {
        $scope = new TrxCustomersScope();
        $scope->entities = ['product', $entity];
        check(!Scopes::allowed($scope), $entity);
    }
    $scope = new TrxCustomersScope();
    $scope->entities = [];
    check(!Scopes::allowed($scope), 'sin entidades');
    $scope->entities = ['product'];
    check(Scopes::allowed($scope), 'una de producto sí');
});

test('el encargo se niega aunque alguien lo pida directamente con una fila de clientes', function (): void {
    [$app, $ai] = trxAiApp();
    throws(function () use ($app): void {
        Brief::ask($app, new TrxCustomersScope(), 'fr', [['key' => '1', 'text' => 'Zoe Cliente Ejemplo', 'format' => 'text']]);
    }, \LogicException::class);
    same(0, count($ai->requests));
});
