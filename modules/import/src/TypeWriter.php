<?php
/**
 * AIFEC — un escritor de migración: recibe registros neutros de UN tipo y los deja en AIFEC. No sabe de qué
 * plataforma vienen.
 *
 * Reglas de todo escritor:
 *   - Casa por WriteContext::mapped(): si el registro ya se trajo y su resumen no ha cambiado, «unchanged»; si cambió
 *     en el origen, se actualiza; si además alguien lo editó aquí después de traerlo (su updated_at es posterior a
 *     written_at) y no es un tipo de configuración, «conflict» y no se toca, salvo que la opción overwrite_local lo pida.
 *   - En simulacro (WriteContext::dryRun()) decide lo mismo y NO escribe nada: ni filas, ni ficheros, ni caché.
 *   - No avisa a nadie: no encola correos, no llama a pasarelas, no dispara ganchos de «pedido pagado» o «cliente
 *     registrado». Lo importado es histórico.
 *   - Lo que llega sin sanear (HTML) se sanea aquí. Lo que no tiene sitio se cuenta con WriteContext::unmapped().
 *   - Una referencia que no se encuentra (la categoría de un producto que no vino) no tumba el registro: se escribe
 *     sin ella y se apunta con WriteContext::note().
 *   - Un fallo al escribir UN registro se devuelve como «skipped» con su motivo; no lanza.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Import;

interface TypeWriter
{
    public const CREATED = 'created';
    public const UPDATED = 'updated';
    public const UNCHANGED = 'unchanged';
    public const CONFLICT = 'conflict';
    public const SKIPPED = 'skipped';

    /** El tipo que escribe (uno de Types::ORDER). */
    public function type(): string;

    /**
     * Escribe (o simula) un registro neutro ya comprobado.
     *
     * @param array<string,mixed> $record
     *
     * @return array{result:string,id:?int,reason:string} result = una de las constantes; id = el de aquí (null si no
     *                                                    hay); reason = motivo en inglés cuando es conflict o skipped
     */
    public function write(array $record, WriteContext $ctx): array;

    /**
     * Segunda pasada, cuando ya está escrito todo el tipo: referencias a registros del mismo tipo (categoría padre,
     * productos relacionados, página padre, elementos de menú). Solo se llama para los tipos de Types::LINKED.
     *
     * @param array<string,mixed> $record
     */
    public function link(array $record, WriteContext $ctx): void;

    /**
     * Cifras del destino para compararlas con las del origen al terminar (las mismas claves que
     * SourceReader::checksums() que correspondan a este tipo: «products_count», «orders_total»…).
     *
     * @return array<string,int>
     */
    public function checksums(WriteContext $ctx): array;
}
