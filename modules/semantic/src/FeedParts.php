<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Semantic;

/**
 * Un feed JSON troceado: los mismos productos del feed entero repartidos en páginas de menos de MAX_BYTES, cada una con su cabecera
 * (tienda, moneda, página y total de páginas) y los enlaces a la anterior, la siguiente, la primera, la última, el índice y el feed
 * entero. Un lector de páginas corta hacia los 100.000 caracteres: con el feed entero veía veinte productos; con una página los ve todos y
 * sabe dónde sigue.
 *
 * Los productos se van escribiendo en ficheros temporales según llegan (la memoria no depende del tamaño del catálogo) y las páginas
 * se componen al terminar, cuando ya se sabe cuántas son.
 */
final class FeedParts
{
    /** Tamaño aproximado de los productos de una página, en bytes (la cabecera y los enlaces van aparte, unos 1.200 más). */
    public const MAX_BYTES = 68000;

    /** @var string */
    private $dir;

    /** @var string */
    private $name;

    /** @var int página en curso (1 la primera) */
    private $page = 0;

    /** @var resource|null */
    private $handle;

    /** @var array<int,array{items:int,bytes:int}> */
    private $pages = [];

    public function __construct(string $dir, string $name)
    {
        $this->dir = rtrim($dir, '/\\');
        $this->name = $name;
    }

    /** El fichero de una página ya compuesta: «openai.p2.json». */
    public static function file(string $name, int $page): string
    {
        return $name . '.p' . $page . '.json';
    }

    private function tmp(int $page): string
    {
        return $this->dir . '/.' . $this->name . '.p' . $page . '.items';
    }

    /** Un producto más (ya en JSON). Si no cabe en la página en curso, abre otra. */
    public function add(string $json): void
    {
        if ($this->page === 0 || ($this->pages[$this->page]['bytes'] > 0 && $this->pages[$this->page]['bytes'] + strlen($json) + 2 > self::MAX_BYTES)) {
            $this->open();
        }
        $handle = $this->handle;
        if (!is_resource($handle)) {
            throw new \RuntimeException('Cannot write the feed part');
        }
        $prefix = $this->pages[$this->page]['items'] > 0 ? ",\n" : "\n";
        fwrite($handle, $prefix . $json);
        $this->pages[$this->page]['items']++;
        $this->pages[$this->page]['bytes'] += strlen($json) + strlen($prefix);
    }

    private function open(): void
    {
        $this->close();
        $this->page++;
        $handle = fopen($this->tmp($this->page), 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Cannot write the feed part');
        }
        $this->handle = $handle;
        $this->pages[$this->page] = ['items' => 0, 'bytes' => 0];
    }

    private function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
        $this->handle = null;
    }

    /**
     * Compone las páginas y borra las sobrantes de una generación anterior.
     *
     * @param string                $header  los campos de arriba del feed, ya en JSON y sin llaves («"generated_at":…,"shop":…»)
     * @param callable(int):string  $urlFor  la dirección pública de una página
     * @param string                $allUrl  la del feed entero
     * @param string                $indexUrl la del índice
     *
     * @return array<int,array{page:int,items:int,bytes:int}> lo que mide cada página (vacía si no hubo productos)
     */
    public function finish(string $header, callable $urlFor, string $allUrl, string $indexUrl): array
    {
        $this->close();
        $total = count($this->pages);
        $meta = [];
        for ($n = 1; $n <= $total; $n++) {
            $links = ['page' => $n, 'pages' => $total, 'first' => $urlFor(1), 'last' => $urlFor($total), 'index' => $indexUrl, 'all' => $allUrl];
            if ($n > 1) {
                $links['prev'] = $urlFor($n - 1);
            }
            if ($n < $total) {
                $links['next'] = $urlFor($n + 1);
            }
            $final = $this->dir . '/' . self::file($this->name, $n);
            $out = fopen($final . '.tmp', 'wb');
            $in = fopen($this->tmp($n), 'rb');
            if ($out === false || $in === false) {
                throw new \RuntimeException('Cannot compose the feed part');
            }
            fwrite($out, '{' . $header . ',"pagination":' . json_encode($links, JSON_UNESCAPED_SLASHES) . ',"products":[');
            stream_copy_to_stream($in, $out);
            fwrite($out, '],"count":' . $this->pages[$n]['items'] . "}\n");
            fclose($in);
            fclose($out);
            unlink($this->tmp($n));
            if (!rename($final . '.tmp', $final)) {
                throw new \RuntimeException('Cannot replace the feed part');
            }
            $meta[] = ['page' => $n, 'items' => $this->pages[$n]['items'], 'bytes' => (int) filesize($final)];
        }
        // Las páginas de más de una generación anterior que ya no existen.
        foreach (glob($this->dir . '/' . $this->name . '.p*.json') ?: [] as $file) {
            if (preg_match('/\.p(\d+)\.json$/', $file, $m) === 1 && (int) $m[1] > $total) {
                unlink($file);
            }
        }

        return $meta;
    }

    /** Borra lo que quedó a medias si la generación falló. */
    public function abort(): void
    {
        $this->close();
        foreach (array_keys($this->pages) as $n) {
            if (is_file($this->tmp($n))) {
                unlink($this->tmp($n));
            }
        }
    }
}
