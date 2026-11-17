<?php
/**
 * AIFEC — un lector de migración: sabe leer UNA tienda de origen de UNA manera (por su API o de una copia de su
 * base de datos) y entregarla en el formato neutro (NeutralSchema). Solo lee: no escribe en el origen ni en AIFEC.
 *
 * Reglas de todo lector:
 *   - El cursor avanza por lo LEÍDO del origen, nunca por lo entregado: un registro descartado cuenta como leído.
 *     El final se dice con «next» a null, no porque una tanda venga corta.
 *   - Un fallo del origen (red, 5xx, consulta que revienta) lanza SourceError; un «no tienes permiso», SourceDenied.
 *     Una lista vacía NO es un fallo.
 *   - Los identificadores de origen son cadenas opacas y únicas dentro de su tipo («f12» para una característica y
 *     «a12» para un grupo de atributos de PrestaShop, que comparten numeración).
 *   - Lo que no cabe en ningún campo va a «extra»; lo que no se puede traer de ninguna forma, a unmapped().
 *   - Nunca escribe una clave ni una contraseña en un registro de actividad, un mensaje de error o una excepción.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Import;

interface SourceReader
{
    /**
     * La ficha del lector, para que el asistente pinte su formulario sin conocerlo. No abre ninguna conexión.
     *
     * Constructor de todo lector: __construct($connection, array $config), donde $connection es un
     * Aifec\Http\HttpClient (modos «api» y «public») o un \PDO de solo lectura (modo «db»), y $config trae los
     * campos de esta ficha ya validados (los de tipo «secret» llegan en claro, solo en memoria).
     *
     * @return array{source:string,mode:string,label:string,help:string,fields:array<string,array<string,mixed>>}
     *                label y help, en inglés (se enseñan con t()); fields = [nombre => ['type' => text|url|secret|path|select,
     *                'label' => …, 'required' => bool, 'hint' => …, 'default' => …, 'options' => [valor => etiqueta]]].
     *                Nombres reservados que la base entiende: url (dirección de la tienda), key, secret (API), prefix
     *                (prefijo de tablas), files (carpeta local de ficheros), base_url (de dónde bajar las imágenes),
     *                shop (tienda de una multitienda), timezone, cookie_key. En modo «db», los datos de conexión
     *                (servidor, puerto, base de datos, usuario y contraseña) los pide la base: no van en esta ficha.
     */
    public static function describe(): array;

    /** De qué plataforma lee: «woocommerce» o «prestashop». */
    public function source(): string;

    /** Cómo lee: «api» (con clave), «public» (sin clave: solo lo publicado) o «db» (copia de la base de datos). */
    public function mode(): string;

    /**
     * Identifica la tienda de origen para no mezclar dos del mismo tipo: su dominio (API) o el nombre de la base de
     * datos y su prefijo (copia). 60 caracteres como mucho, sin credenciales.
     */
    public function key(): string;

    /**
     * Comprueba la conexión haciendo la PRIMERA lectura real que hará la migración, y cuenta lo que devuelve.
     *
     * @return array{ok:bool,message:string,version:string,shops:array<int,array{id:string,name:string}>,languages:string[],currency:string,warnings:string[]}
     *                ok = conecta y hay algo que leer; message, en inglés (se enseña con t()); shops, las tiendas del
     *                origen cuando hay varias (multitienda, multisitio); warnings, lo que conviene saber antes de empezar
     */
    public function probe(): array;

    /**
     * Tipos que este lector sabe leer de este origen (subconjunto de Types::ORDER).
     *
     * @return string[]
     */
    public function types(): array;

    /** Cuántos registros hay de un tipo en el origen, o null si no se puede saber sin leerlos todos. */
    public function count(string $type): ?int;

    /**
     * Lee una tanda de un tipo.
     *
     * @param string $cursor '' la primera vez; después, el «next» que devolvió la tanda anterior
     * @param int    $limit  cuántos registros del origen leer como mucho (no cuántos entregar)
     *
     * @return array{records:array<int,array<string,mixed>>,next:?string,read:int,skipped:array<int,array{id:string,reason:string}>}
     *                records, en formato neutro; next = null cuando el origen se ha agotado; read, cuántos registros del
     *                origen ha consumido la tanda (entregados + descartados); skipped, los descartados y por qué
     *
     * @throws SourceError  el origen falla (se reintenta; a la tercera seguida, la migración se pausa)
     * @throws SourceDenied el origen niega el acceso (la migración se pausa en el acto)
     */
    public function read(string $type, string $cursor, int $limit): array;

    /**
     * Sumas de control del origen para compararlas con el destino al terminar. Las que el lector sepa dar:
     * orders_total (céntimos), orders_count, stock_total, customers_count, products_count…
     *
     * @return array<string,int>
     */
    public function checksums(): array;

    /**
     * Lo que el origen tiene y esta versión no puede traer (módulos de terceros, funciones sin equivalente).
     *
     * @return array<int,array{kind:string,name:string,count:int,note:string}>
     */
    public function unmapped(): array;
}
