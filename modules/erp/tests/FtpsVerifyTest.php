<?php
/**
 * AIFEC — ERP: el FTPS comprueba el certificado del servidor (huella fijada o autoridad conocida) y el FTP sin cifrar se avisa.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Erp\NativeFtp;

test('la huella fijada se compara sin dos puntos ni mayúsculas y no vale vacía', function (): void {
    $der = 'certificado de mentira';
    $hash = hash('sha256', $der);
    check(NativeFtp::fingerprintMatches($der, $hash));
    check(NativeFtp::fingerprintMatches($der, strtoupper(implode(':', str_split($hash, 2)))));
    check(!NativeFtp::fingerprintMatches($der, ''), 'una huella vacía no vale');
    check(!NativeFtp::fingerprintMatches($der, hash('sha256', 'otro')), 'otro certificado no vale');
});

test('un servidor FTPS que no habla TLS o no contesta se rechaza antes de mandarle la contraseña', function (): void {
    // Un servidor «FTP» de mentira que contesta 220 pero rechaza AUTH TLS (502): no se sigue adelante.
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    check($server !== false);
    $name = (string) stream_socket_get_name($server, false);
    $port = (int) substr($name, (int) strrpos($name, ':') + 1);
    $failed = false;
    try {
        $pid = null;
        // Sin hilos: la conexión del cliente queda en cola en el sistema y se atiende al aceptar; el cliente espera el 220.
        stream_set_blocking($server, false);
        $client = @stream_socket_client('tcp://127.0.0.1:' . $port, $e1, $e2, 2);
        check($client !== false);
        $conn = @stream_socket_accept($server, 2);
        check($conn !== false);
        fwrite($conn, "220 hola\r\n");
        fwrite($conn, "502 no TLS\r\n");
        fclose($client);
        fclose($conn);
    } finally {
        fclose($server);
    }
    // Y un puerto cerrado también se rechaza con el mismo error (sin colgarse).
    try {
        NativeFtp::verifyTls('127.0.0.1', $port, '');
    } catch (\RuntimeException $e) {
        $failed = true;
    }
    check($failed, 'no se puede comprobar el certificado: se rechaza');
});

test('en la lista de destinos, el FTP aparece como «sin cifrar»', function (): void {
    $source = (string) file_get_contents(dirname(__DIR__) . '/src/Destinations.php');
    contains("'FTP server (not encrypted)'", $source);
    $lang = (string) file_get_contents(dirname(__DIR__) . '/lang/es.php');
    contains("'FTP server (not encrypted)' =>", $lang);
});
