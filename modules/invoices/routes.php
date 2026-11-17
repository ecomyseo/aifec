<?php
/**
 * AIFEC — facturas: la descarga de la tienda y las pantallas del panel.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Router;
use Aifec\Modules\Invoices\Admin\InvoicesController;
use Aifec\Modules\Invoices\Front\InvoiceController;

return static function (Router $r, App $app): void {
    // La tienda: el número completo de la factura y la ficha del pedido dan acceso sin cuenta (PDF en línea, sin caché).
    $r->get('/factura/{number}/{secret}', [InvoiceController::class, 'download'])->name('invoices.download');

    // El panel: listado, exportación, descarga y emisión a mano. Las rutas fijas van antes que la de la factura.
    $r->group(['prefix' => '/admin/invoices', 'middleware' => ['admin']], static function (Router $r): void {
        $r->get('', [InvoicesController::class, 'index'])->name('admin.invoices');
        $r->get('/export.csv', [InvoicesController::class, 'export'])->name('admin.invoices.export');
        $r->post('/issue', [InvoicesController::class, 'issue']);
        $r->get('/{id:\d+}/pdf', [InvoicesController::class, 'download'])->name('admin.invoices.pdf');
    });
};
