<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Db;

/**
 * Definición de una tabla, independiente del motor. La rellena la función que se pasa a Schema::create().
 *
 *   $t->id();
 *   $t->string('sku', 64)->nullable();
 *   $t->integer('price')->default(0);        // dinero: céntimos
 *   $t->boolean('active')->default(1);
 *   $t->text('data')->nullable();            // texto largo o JSON
 *   $t->datetime('created_at');              // UTC «Y-m-d H:i:s»
 *   $t->index(['status', 'created_at']);
 *   $t->unique(['lang', 'path']);
 *
 * Toda columna es NOT NULL salvo que se marque nullable(). Los modificadores nullable() y default()
 * se aplican a la última columna declarada.
 */
final class TableDef
{
    /** @var array<int,array<string,mixed>> */
    public $columns = [];

    /** @var array<int,array{columns:string[],unique:bool,name:?string}> */
    public $indexes = [];

    /** @var string[] clave primaria compuesta (si no hay id()) */
    public $primary = [];

    public function id(string $name = 'id'): self
    {
        return $this->add($name, 'id');
    }

    /**
     * Texto corto. Sin longitud son 191 caracteres; con longitud, los que se pidan (hasta 768, lo que todavía cabe en un
     * índice de InnoDB). MySQL y MariaDB rechazan lo que no cabe —SQLite no mira longitudes—, así que la longitud declarada
     * tiene que ser la de verdad: hasta el 13/11/2026 todo se recortaba a 191 en silencio y una descripción meta de 300
     * caracteres daba «Data too long» solo en los sitios con MariaDB.
     */
    public function string(string $name, int $length = 191): self
    {
        return $this->add($name, 'string', ['length' => max(1, min(768, $length))]);
    }

    /** Texto largo (hasta 16 MB): descripciones, contenido en bloques, JSON. */
    public function text(string $name): self
    {
        return $this->add($name, 'text');
    }

    public function integer(string $name): self
    {
        return $this->add($name, 'integer');
    }

    public function bigInteger(string $name): self
    {
        return $this->add($name, 'bigint');
    }

    public function boolean(string $name): self
    {
        return $this->add($name, 'boolean');
    }

    /** Número con decimales exactos (tipos de impuesto, pesos). Nunca para dinero: el dinero son céntimos enteros. */
    public function decimal(string $name, int $precision = 12, int $scale = 4): self
    {
        return $this->add($name, 'decimal', ['precision' => $precision, 'scale' => $scale]);
    }

    public function float(string $name): self
    {
        return $this->add($name, 'float');
    }

    public function datetime(string $name): self
    {
        return $this->add($name, 'datetime');
    }

    public function date(string $name): self
    {
        return $this->add($name, 'date');
    }

    public function blob(string $name): self
    {
        return $this->add($name, 'blob');
    }

    /** created_at y updated_at. */
    public function timestamps(): self
    {
        $this->datetime('created_at');

        return $this->datetime('updated_at');
    }

    public function nullable(): self
    {
        $this->columns[count($this->columns) - 1]['nullable'] = true;

        return $this;
    }

    /**
     * @param string|int|float|null $value
     */
    public function default($value): self
    {
        $this->columns[count($this->columns) - 1]['default'] = $value;
        $this->columns[count($this->columns) - 1]['has_default'] = true;

        return $this;
    }

    /**
     * @param string[] $columns
     */
    public function index(array $columns, ?string $name = null): self
    {
        $this->indexes[] = ['columns' => $columns, 'unique' => false, 'name' => $name];

        return $this;
    }

    /**
     * @param string[] $columns
     */
    public function unique(array $columns, ?string $name = null): self
    {
        $this->indexes[] = ['columns' => $columns, 'unique' => true, 'name' => $name];

        return $this;
    }

    /**
     * @param string[] $columns
     */
    public function primary(array $columns): self
    {
        $this->primary = $columns;

        return $this;
    }

    /**
     * @param array<string,mixed> $extra
     */
    private function add(string $name, string $type, array $extra = []): self
    {
        $this->columns[] = ['name' => $name, 'type' => $type, 'nullable' => false, 'has_default' => false, 'default' => null] + $extra;

        return $this;
    }
}
