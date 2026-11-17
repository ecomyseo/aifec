<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Translations;

/**
 * Una fila de la tabla de cobertura («Interfaz de la tienda», «Productos», «Menús»…): qué textos hay, cuántos están traducidos,
 * cuántos esperan aprobación, cuáles faltan (para la IA) y cómo se guardan. Un plugin puede añadir la suya con el filtro
 * «translations.scopes»; la que lea datos personales (clientes, pedidos, direcciones…) se descarta siempre (Scopes::allowed()).
 *
 * Una «unidad» es un texto suelto que se traduce: ['key' => clave única en la fila, 'text' => original, 'format' => text|html|plural].
 */
interface Scope
{
    /** Código de la fila: shop, panel, mail, products, categories, attributes, pages, menus… */
    public function code(): string;

    /** Nombre de la fila, ya traducido. */
    public function label(): string;

    /** Qué entra en esa fila, ya traducido, en una línea. */
    public function describe(): string;

    /**
     * Las entidades de las que lee texto (product, page, menu…). Una con datos personales hace que la fila se descarte.
     *
     * @return string[]
     */
    public function entities(): array;

    /** El idioma de los textos de partida: «en» para la interfaz, el principal de la tienda para el contenido. */
    public function sourceLang(): string;

    /** Qué son estos textos, en inglés y en una frase, para el encargo a la IA. */
    public function brief(): string;

    /** '' si la IA puede rellenar esta fila en ese idioma; si no, el motivo ya traducido. */
    public function blocked(string $lang): string;

    /**
     * Cuántos textos hay, cuántos están traducidos, cuántos propuestos por la IA sin aprobar y cuántos le faltan a la IA.
     *
     * @return array{total:int,translated:int,proposed:int,missing:int}
     */
    public function coverage(string $lang): array;

    /**
     * Las unidades que faltan (sin traducción ni propuesta), por orden, después del cursor. Se para al llegar a $maxUnits o a
     * $maxChars (una unidad sola más larga va igual, sola). «done» = ya no queda nada detrás del cursor devuelto.
     *
     * @return array{units:array<int,array{key:string,text:string,format:string}>,cursor:string,done:bool}
     */
    public function next(string $lang, string $after, int $maxUnits, int $maxChars): array;

    /**
     * Guarda traducciones ya comprobadas: como propuestas o, con $publish, publicadas. Devuelve las claves que no se pudieron guardar.
     *
     * @param array<string,string>                                     $texts clave => traducción
     * @param array<string,array{key:string,text:string,format:string}> $units clave => unidad
     *
     * @return string[]
     */
    public function save(string $lang, array $texts, array $units, bool $publish, int $jobId): array;

    /**
     * Aprueba lo propuesto en ese idioma (como mucho $max). Devuelve cuántas se aprobaron.
     */
    public function approveAll(string $lang, int $max = 1000): int;

    /** Si aprobar lo propuesto en esta fila necesita el módulo de IA y su permiso. */
    public function approvalNeedsAi(): bool;

    /** Dónde se ve y se edita esta fila en ese idioma. */
    public function viewUrl(string $lang): string;

    /**
     * La lista de «Ver y editar» del contenido, o null si la fila se edita en otra pantalla (la interfaz, los correos).
     *
     * @return array{fields:array<string,string>,rows:array<int,array{label:string,url:string,fields:array<string,string>}>,total:int}|null
     *         fields: campo => nombre; en cada fila, campo => ok|proposed|missing|none
     */
    public function rows(string $lang, int $page, int $perPage): ?array;

    /**
     * Fichas aproximadas de una unidad [entrada, salida], para la estimación del gasto (no es una medida).
     *
     * @return array{0:int,1:int}
     */
    public function tokensPerUnit(): array;
}
