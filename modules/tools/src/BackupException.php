<?php
/**
 * AIFEC — una copia que no se puede comprobar o restaurar. El mensaje va en inglés y, si hace falta, lleva el detalle tras « :: ».
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Tools;

final class BackupException extends \RuntimeException
{
    /** El mensaje traducido: la parte fija pasa por `t()` y el detalle (un nombre de fichero, un error del motor) se deja como está. */
    public static function text(string $message): string
    {
        $pos = strpos($message, ' :: ');
        if ($pos === false) {
            return t($message);
        }

        return t(substr($message, 0, $pos)) . ' (' . substr($message, $pos + 4) . ')';
    }
}
