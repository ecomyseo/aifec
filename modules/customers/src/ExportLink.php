<?php
/**
 * AIFEC — botón «Exportar la lista» del listado de clientes: se pinta al mostrar la pantalla, solo para quien puede exportar.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Customers;

use Aifec\Admin\Admin;
use Aifec\Kernel\App;

/**
 * El listado de un recurso se declara como un array al cargar el panel, antes de saber quién mira la pantalla.
 * Este objeto va en la barra del listado (clave «toolbar», que se convierte en texto al pintar) y decide entonces:
 * sin el permiso «customers.export» no sale nada. El enlace conserva la búsqueda y los filtros que se ven.
 */
final class ExportLink
{
    public function __toString(): string
    {
        $app = App::current();
        if (!$app->auth()->can('customers.export')) {
            return '';
        }
        $link = $app->url()->plain('/admin/customers/export.csv', $app->request()->query);

        return '<a class="af-btn" href="' . e($link) . '">' . Admin::icon('download') . e(t('Export the list')) . '</a>';
    }
}
