<?php
/**
 * AIFEC — AI-First E-Commerce. Punto de entrada web: es el único fichero PHP de la carpeta pública.
 *
 * Si el código no está en la carpeta de al lado (instalación con el código fuera de la raíz web),
 * un fichero aifec-root.php junto a este devuelve su ruta:  <?php return '/ruta/al/codigo';
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

// Servidor de desarrollo de PHP (php -S): los ficheros que existen los sirve él.
if (PHP_SAPI === 'cli-server') {
    $aifecFile = __DIR__ . (string) parse_url(isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
    if (is_file($aifecFile) && basename($aifecFile) !== 'index.php') {
        return false;
    }
}

$aifecRoot = is_file(__DIR__ . '/aifec-root.php') ? (string) require __DIR__ . '/aifec-root.php' : dirname(__DIR__);
require $aifecRoot . '/core/bootstrap.php';

// La carpeta de datos puede estar fuera del código: data-path.php (junto a la carpeta core) devuelve su ruta.
$aifecData = is_file($aifecRoot . '/data-path.php') ? (string) require $aifecRoot . '/data-path.php' : $aifecRoot . '/sites';

\Aifec\Kernel\Front::run(
    $aifecRoot,
    $aifecData,
    __DIR__,
    // El servidor de desarrollo de PHP pone en SCRIPT_NAME la propia dirección pedida cuando lleva un punto
    // («/admin/palette.json»): ahí la carpeta pública es siempre la raíz.
    PHP_SAPI === 'cli-server' ? '' : \Aifec\Kernel\Front::publicBase(isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '/index.php')
)->send();
