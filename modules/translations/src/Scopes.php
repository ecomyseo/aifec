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
use Aifec\Modules\Translations\Scopes\CatalogScope;
use Aifec\Modules\Translations\Scopes\InterfaceScope;
use Aifec\Modules\Translations\Scopes\MailScope;
use Aifec\Modules\Translations\Scopes\MenusScope;
use Aifec\Modules\Translations\Scopes\PagesScope;
use Aifec\Modules\Translations\Scopes\ProductsScope;

/**
 * Las filas de la tabla de cobertura, en orden: interfaz de la tienda, del panel, correos, productos, categorías, atributos,
 * páginas y menús (cada una solo si está su módulo), más las que añadan los plugins con el filtro «translations.scopes».
 *
 * Nunca se traduce un pedido, un cliente ni un dato personal: una fila que declare leer una de las entidades de PERSONAL (o que
 * no declare ninguna) se descarta aquí, venga de donde venga, y se apunta en el registro.
 */
final class Scopes
{
    /** Entidades con datos de personas: ninguna fila puede leerlas. */
    public const PERSONAL = [
        'customer', 'customers', 'order', 'orders', 'order_line', 'order_lines', 'address', 'addresses', 'user', 'users', 'cart', 'carts',
        'review', 'reviews', 'form_entry', 'form_entries', 'conversation', 'conversations', 'message', 'messages', 'subscriber',
        'subscribers', 'invoice', 'invoices', 'return', 'returns', 'payment', 'payments', 'mail_message', 'mail_messages',
    ];

    /**
     * @return array<string,Scope> código => fila
     */
    public static function all(App $app): array
    {
        $list = [new InterfaceScope($app, Strings::SHOP), new InterfaceScope($app, Strings::PANEL)];
        if ($app->has('mailer')) {
            $list[] = new MailScope($app);
        }
        if ($app->has('catalog') && $app->db()->tableExists('product_i18n')) {
            $list[] = new ProductsScope($app);
            $list[] = new CatalogScope($app, 'categories');
            $list[] = new CatalogScope($app, 'attributes');
        }
        if ($app->has('cms') && $app->db()->tableExists('page_i18n')) {
            $list[] = new PagesScope($app);
            $list[] = new MenusScope($app);
        }
        $extra = $app->hooks()->apply('translations.scopes', [], $app);
        foreach (is_array($extra) ? $extra : [] as $scope) {
            if ($scope instanceof Scope) {
                $list[] = $scope;
            }
        }
        $out = [];
        foreach ($list as $scope) {
            $code = $scope->code();
            if (preg_match('/^[a-z][a-z0-9_]{0,23}$/', $code) !== 1 || isset($out[$code])) {
                continue;
            }
            if (!self::allowed($scope)) {
                $app->log()->warning('A translation row that reads personal data was refused', ['row' => $code]);
                continue;
            }
            $out[$code] = $scope;
        }

        return $out;
    }

    public static function get(App $app, string $code): ?Scope
    {
        $all = self::all($app);

        return isset($all[$code]) ? $all[$code] : null;
    }

    /** ¿Puede esta fila traducirse? No, si lee datos de personas o no dice qué lee. */
    public static function allowed(Scope $scope): bool
    {
        $entities = $scope->entities();
        if ($entities === []) {
            return false;
        }
        foreach ($entities as $entity) {
            if (in_array(strtolower(trim((string) $entity)), self::PERSONAL, true)) {
                return false;
            }
        }

        return true;
    }
}
