<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Ai;

/** Una llamada a herramienta que pide el modelo: qué herramienta y con qué argumentos. */
final class AiToolCall
{
    /** @var string identificador de la llamada (sirve para casar el resultado en OpenAI) */
    public string $id;

    /** @var string */
    public string $name;

    /** @var array<string,mixed> */
    public array $arguments;

    /**
     * Lo que el proveedor mandó junto a la llamada y tiene que volver intacto cuando la llamada se le devuelve en el turno
     * siguiente, guardado bajo el nombre del proveedor. En Gemini es la firma de pensamiento de la parte: sin ella la API
     * contesta 400 y no hay conversación con herramientas. Quien monta la conversación no lo toca: pasa el mismo objeto.
     *
     * @var array<string,mixed>
     */
    public array $extra;

    /**
     * @param array<string,mixed> $arguments
     * @param array<string,mixed> $extra
     */
    public function __construct(string $id = '', string $name = '', array $arguments = [], array $extra = [])
    {
        $this->id = $id;
        $this->name = $name;
        $this->arguments = $arguments;
        $this->extra = $extra;
    }
}
