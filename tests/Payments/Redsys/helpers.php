<?php
/**
 * AIFEC — ayudas comunes de las pruebas de Redsys (no es una prueba: el lanzador solo carga *Test.php).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Payments\Redsys\RedsysConfig;
use Aifec\Payments\Redsys\RedsysSigner;

/** Clave inventada para las pruebas: no es de ningún comercio ni vale contra Redsys. */
const REDSYS_TEST_KEY = 'Y2xhdmUtaW52ZW50YWRhLUFJRkVDLTAx';

/** Número de comercio inventado. */
const REDSYS_TEST_MERCHANT = '123456789';

/** Otra clave inventada, distinta. */
const REDSYS_OTHER_KEY = 'b3RyYS1jbGF2ZS1pbnZlbnRhZGEtMDAy';

/** Un comercio de pruebas inventado, en el entorno de pruebas. */
function redsysTestConfig(): RedsysConfig
{
    return new RedsysConfig(REDSYS_TEST_MERCHANT, '1', REDSYS_TEST_KEY, RedsysConfig::ENV_TEST, '978', 'Test');
}

/**
 * Aviso firmado como lo fabrica Redsys; $urlSafe pasa la firma a la variante - y _ .
 *
 * @param array<string,mixed> $data
 *
 * @return array<string,string>
 */
function redsysSignedPost(array $data, string $key = REDSYS_TEST_KEY, bool $urlSafe = false): array
{
    $json = json_encode($data, JSON_UNESCAPED_SLASHES);
    $params = base64_encode((string) $json);
    $signature = RedsysSigner::sign($params, (string) $data['Ds_Order'], $key);
    if ($urlSafe) {
        $signature = strtr($signature, '+/', '-_');
    }

    return ['Ds_SignatureVersion' => 'HMAC_SHA256_V1', 'Ds_MerchantParameters' => $params, 'Ds_Signature' => $signature];
}
