<?php
/**
 * AIFEC — lo que un escritor de migración tiene a mano: la aplicación, las opciones de la migración, el casamiento
 * de identificadores, las equivalencias elegidas por el comerciante y el informe.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Import;

use Aifec\Kernel\App;

interface WriteContext
{
    public function app(): App;

    /** true = simulacro: se decide todo y no se escribe nada. */
    public function dryRun(): bool;

    /** «woocommerce» o «prestashop» (para el campo «source» de clientes y pedidos, nada más). */
    public function source(): string;

    /**
     * Una opción de la migración: keep_urls (bool), overwrite_local (bool), import_images (bool),
     * prices_include_tax_here (bool: cómo se escriben los precios en ESTE sitio)…
     *
     * @param mixed $default
     *
     * @return mixed
     */
    public function option(string $key, $default = null);

    /** El identificador de aquí de un registro que ya se trajo (de cualquier migración anterior del mismo origen), o null. */
    public function localId(string $type, string $sourceId): ?int;

    /**
     * La pareja guardada de un registro, o null si nunca se trajo.
     *
     * @return array{local_id:int,source_hash:string,written_at:string}|null
     */
    public function mapped(string $type, string $sourceId): ?array;

    /** Apunta (o actualiza) la pareja tras escribir. En simulacro no hace nada. */
    public function remember(string $type, string $sourceId, int $localId, string $sourceHash): void;

    /**
     * La equivalencia que eligió el comerciante en el asistente para un valor del origen.
     *
     * @param string $kind order_state (devuelve un código de estado de aquí), tax_class (código de clase),
     *                     customer_group (id de grupo), payment_method (código de forma de pago)
     *
     * @return string|null null = sin equivalencia: el escritor decide por el significado y lo apunta
     */
    public function equivalent(string $kind, string $sourceValue): ?string;

    /** Una línea del informe registro a registro. $result: conflict, skipped, error o note. */
    public function note(string $type, string $sourceId, string $result, string $reason): void;

    /** Algo que venía en el registro y aquí no tiene sitio: se suma a «sin equivalente» del informe. */
    public function unmapped(string $kind, string $name, int $count = 1, string $note = ''): void;

    /**
     * Idiomas del sitio de destino (el principal, el primero). Los textos en otros idiomas no se traen y se avisa.
     *
     * @return string[]
     */
    public function languages(): array;

    /**
     * Lleva una imagen neutra a la biblioteca de medios (sin duplicar la que ya está) y devuelve su identificador;
     * null si no se pudo (queda apuntado) o si es un simulacro o la opción import_images está apagada.
     *
     * @param array<string,mixed> $image
     */
    public function image(array $image, string $ownerType, string $ownerId): ?int;

    /**
     * Registra las rutas antiguas de una entidad: como su dirección amigable si la opción keep_urls está puesta, o como
     * redirección permanente a la nueva si no. En simulacro no hace nada.
     *
     * @param string   $entity el nombre de entidad de las direcciones amigables («product», «category», «page»…)
     * @param string[] $paths
     */
    public function oldPaths(string $entity, int $localId, array $paths, string $lang = ''): void;
}
