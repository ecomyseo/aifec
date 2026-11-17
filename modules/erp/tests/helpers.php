<?php
/**
 * AIFEC — ayudas de prueba del módulo ERP. No es una prueba (no acaba en «Test.php»): se carga con require_once.
 *
 *   erpApp(array $opciones = []): App
 *       Aplicación con «erp», «invoices», «orders» (y lo que necesitan), «tools», «mail», «media» y «jobs», con el cliente HTTP de
 *       mentira. Opciones de invoicesApp() (settings, modules…). Con 'invoices' => false se monta sin el módulo de facturas.
 *
 *   erpConnection(App $app, ?array $ambitos = null, string $nombre = 'ERP de pruebas'): array
 *       Da de alta una conexión y devuelve ['id', 'key']. Sin ámbitos, todos.
 *
 *   erpCall(App $app, string $metodo, string $ruta, $cuerpo = null, ?string $clave = null, array $cabeceras = []): Response
 *       Una petición a /api/v1/erp<ruta> con la clave como «Authorization: Bearer». $cuerpo es un array (se manda como JSON) o un texto.
 *
 *   erpJson(Response $r): array     el cuerpo como array
 *   erpOrder(App $app, int $unidades = 1, int $precio = 2000): int   pedido cobrado (con factura); devuelve su id
 *   erpOrderRows(App $app, int $n, string $fecha = ''): void       n pedidos sueltos directos en la tabla (para probar cursores)
 *   erpSubscribe(App $app, int $conexion, array $eventos, string $url = 'https://erp.test/hook'): array   suscripción de avisos
 *   erpFtp(App $app): AifecErpFakeFtp        servidor FTP de mentira ya registrado en el servicio «erp.ftp»
 *   erpExportProfile(App $app, array $valores = []): int   perfil de exportación (valores planos de formulario; por defecto pedidos en CSV)
 *   erpImportProfile(App $app, array $valores = []): int   perfil de importación (por defecto, CSV de una carpeta con SKU y stock)
 *   erpRun(App $app, int $perfil, array $opciones = []): array       una pasada de exportación
 *   erpRunFile(App $app, array $pasada): string                      el contenido del fichero de una pasada (se puede descargar)
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Modules\Erp\FtpTransport;

require_once __DIR__ . '/../../invoices/tests/helpers.php';
require_once __DIR__ . '/../../orders/tests/AdminKit.php';

if (!class_exists('AifecErpFakeFtp')) {
    /** Servidor FTP de mentira: guarda lo que se sube y puede fallar a propósito. */
    final class AifecErpFakeFtp implements FtpTransport
    {
        /** @var array<string,string> ruta => contenido */
        public $files = [];

        /** @var bool */
        public $fail = false;

        /** @var array<int,array<string,mixed>> las conexiones con las que se ha llamado */
        public $connections = [];

        public function available(bool $secure): bool
        {
            return true;
        }

        public function put(array $connection, string $remotePath, string $contents): void
        {
            $this->connections[] = $connection;
            if ($this->fail) {
                throw new \RuntimeException('The FTP user or password was refused');
            }
            $this->files[$remotePath] = $contents;
        }

        public function get(array $connection, string $remotePath): string
        {
            if (!isset($this->files[$remotePath])) {
                throw new \RuntimeException('The file could not be downloaded');
            }

            return $this->files[$remotePath];
        }

        public function names(array $connection, string $remoteDir): array
        {
            $out = [];
            foreach (array_keys($this->files) as $path) {
                if (dirname($path) === rtrim($remoteDir, '/') || (rtrim($remoteDir, '/') === '' && dirname($path) === '/')) {
                    $out[] = basename($path);
                }
            }

            return $out;
        }

        public function rename(array $connection, string $from, string $to): void
        {
            if (isset($this->files[$from])) {
                $this->files[$to] = $this->files[$from];
                unset($this->files[$from]);
            }
        }
    }
}

if (!function_exists('erpApp')) {
    /**
     * @param array<string,mixed> $opciones
     */
    function erpApp(array $opciones = []): App
    {
        $modules = ['erp', 'tools'];
        $withInvoices = !(array_key_exists('invoices', $opciones) && $opciones['invoices'] === false);
        unset($opciones['invoices']);
        $opciones['modules'] = array_merge($modules, isset($opciones['modules']) && is_array($opciones['modules']) ? $opciones['modules'] : []);
        // El módulo no viene encendido de serie (default: false): se enciende como lo haría el panel, con «modules.active».
        $active = array_merge($opciones['modules'], ['orders', 'catalog', 'customers', 'tax', 'shipping', 'coupons', 'mail', 'media', 'jobs'], $withInvoices ? ['invoices'] : []);
        $settings = isset($opciones['settings']) && is_array($opciones['settings']) ? $opciones['settings'] : [];
        $opciones['settings'] = $settings + ['modules.active' => array_values(array_unique($active))];
        if ($withInvoices) {
            $app = invoicesApp($opciones);
        } else {
            $app = aifecOrderApp($opciones);
            aifecOrderPayment($app)->test = false;
        }
        $app->boot();

        return $app;
    }

    /**
     * @param string[]|null $ambitos
     *
     * @return array{id:int,key:string}
     */
    function erpConnection(App $app, ?array $ambitos = null, string $nombre = 'ERP de pruebas'): array
    {
        return $app->service('erp')->connections()->create($nombre, $ambitos !== null ? $ambitos : array_keys(\Aifec\Modules\Erp\Connections::SCOPES));
    }

    /**
     * @param array<string,mixed>|string|null $cuerpo
     * @param array<string,string>            $cabeceras
     */
    function erpCall(App $app, string $metodo, string $ruta, $cuerpo = null, ?string $clave = null, array $cabeceras = []): Response
    {
        $headers = $cabeceras + ['user-agent' => 'ErpTest/1.0'];
        if ($clave !== null) {
            $headers += ['authorization' => 'Bearer ' . $clave];
        }
        $body = '';
        if (is_array($cuerpo)) {
            $body = (string) json_encode($cuerpo);
            $headers += ['content-type' => 'application/json'];
        } elseif (is_string($cuerpo)) {
            $body = $cuerpo;
            $headers += ['content-type' => 'application/json'];
        }
        $request = Request::create($metodo, '/api/v1/erp' . $ruta, [], [], $headers, $body);
        $request->host = 'tienda.test';
        $request->secure = true;

        return $app->handle($request);
    }

    /**
     * @return array<string,mixed>
     */
    function erpJson(Response $r): array
    {
        $data = json_decode($r->body, true);

        return is_array($data) ? $data : [];
    }

    /** Un pedido cobrado (con su factura): $unidades de un producto nuevo a $precio céntimos. */
    function erpOrder(App $app, int $unidades = 1, int $precio = 2000): int
    {
        static $n = 0;
        ++$n;
        $producto = aifecProduct($app, ['name' => 'Camiseta ' . $n, 'sku' => 'CAM-' . $n, 'price' => $precio, 'stock' => 100, 'weight' => 200]);

        return invoicesOrder($app, [[$producto, 0, $unidades]]);
    }

    /** Pedidos mínimos escritos directamente en la tabla (sirven para probar cursores sin pasar 250 veces por la caja). */
    function erpOrderRows(App $app, int $n, string $fecha = ''): void
    {
        $fecha = $fecha !== '' ? $fecha : $app->clock()->sql();
        $seq = (int) $app->db()->value('SELECT COUNT(*) FROM {orders}');
        for ($i = 0; $i < $n; ++$i) {
            ++$seq;
            $app->db()->insert('orders', [
                'number' => 'T-' . str_pad((string) $seq, 6, '0', STR_PAD_LEFT), 'email' => 'cliente' . $seq . '@cliente.test', 'status' => 'processing', 'payment_status' => 'paid',
                'items_total' => 1000, 'total' => 1000, 'total_net' => 826, 'total_tax' => 174, 'paid_total' => 1000, 'placed_at' => $fecha, 'created_at' => $fecha, 'updated_at' => $fecha,
            ]);
        }
    }

    /**
     * @param string[] $eventos
     *
     * @return array{id:int,secret:?string,created:bool}
     */
    function erpSubscribe(App $app, int $conexion, array $eventos, string $url = 'https://erp.test/hook'): array
    {
        return $app->service('erp')->webhooks()->save($conexion, $url, $eventos, true);
    }

    function erpFtp(App $app): AifecErpFakeFtp
    {
        $ftp = new AifecErpFakeFtp();
        $app->bind('erp.ftp', static function () use ($ftp): AifecErpFakeFtp {
            return $ftp;
        });

        return $ftp;
    }

    /**
     * @param array<string,mixed> $valores
     */
    function erpExportProfile(App $app, array $valores = []): int
    {
        $v = $valores + [
            'name' => 'Pedidos de pruebas', 'kind' => 'orders', 'enabled' => 0, 'format' => 'csv', 'columns' => [], 'csv_delimiter' => ';', 'csv_quote' => '"',
            'csv_encoding' => 'utf8', 'csv_decimal' => 'dot', 'csv_date_format' => 'iso', 'csv_eol' => 'lf', 'csv_header' => 1, 'csv_safe' => 1, 'json_layout' => 'list',
            'xml_root' => 'items', 'xml_item' => 'item', 'statuses' => [], 'only_new' => 0, 'destination' => 'download', 'schedule' => 'manual',
        ];
        $errors = $app->service('erp')->profiles()->check($v, 'export');
        check($errors === [], 'el perfil vale: ' . json_encode($errors));

        return $app->service('erp')->profiles()->save($v, 'export', null);
    }

    /**
     * @param array<string,mixed> $valores
     */
    function erpImportProfile(App $app, array $valores = []): int
    {
        $v = $valores + [
            'name' => 'Existencias del ERP', 'enabled' => 0, 'format' => 'csv', 'destination' => 'folder', 'dest_folder' => 'existencias', 'dest_pattern' => '*.csv',
            'col_sku' => 'sku', 'col_stock' => 'stock', 'col_price' => '', 'col_sale_price' => '', 'stock_mode' => 'absolute', 'prices_include_tax' => 1, 'schedule' => 'manual',
        ];
        $errors = $app->service('erp')->profiles()->check($v, 'import');
        check($errors === [], 'el perfil vale: ' . json_encode($errors));

        return $app->service('erp')->profiles()->save($v, 'import', null);
    }

    /**
     * @param array<string,mixed> $opciones
     *
     * @return array<string,mixed>
     */
    function erpRun(App $app, int $perfil, array $opciones = []): array
    {
        return $app->service('erp')->exporter()->run($perfil, $opciones);
    }

    /**
     * @param array<string,mixed> $pasada
     */
    function erpRunFile(App $app, array $pasada): string
    {
        $file = $app->service('erp')->exporter()->download((int) $pasada['id']);
        check($file !== null, 'la pasada tiene su fichero descargable');

        return (string) file_get_contents($file['path']);
    }

    /** Deja un fichero en la carpeta de entrada del perfil de importación y devuelve su ruta. */
    function erpDrop(App $app, string $carpeta, string $nombre, string $contenido): string
    {
        $dir = $app->service('erp')->destinations()->folder('in', $carpeta);
        file_put_contents($dir . '/' . $nombre, $contenido);

        return $dir . '/' . $nombre;
    }
}
