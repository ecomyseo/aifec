<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Pdf;

/**
 * Generador de PDF 1.4 sin dependencias, pensado para facturas y albaranes.
 *
 * Quien lo usa trabaja en milímetros con el origen arriba a la izquierda; el texto entra en UTF-8 y
 * sale en WinAnsi (CP1252) con las fuentes base del PDF (Helvetica y Courier), que no se incrustan.
 *
 *   $pdf = new PdfWriter();
 *   $pdf->setCreationDate($now);                       // el instante lo pone quien llama
 *   $pdf->onFooter(function (PdfWriter $p): void {
 *       $p->setY(-15);
 *       $p->cell(0, 5, 'Página ' . $p->pageNo() . ' de {nb}', 'C');
 *   });
 *   $pdf->addPage();
 *   $pdf->setFont('Helvetica', 'B', 16);
 *   $pdf->cell(0, 10, 'Factura 2026-0001');
 *   file_put_contents('f.pdf', $pdf->output());
 *
 * «{nb}» se sustituye por el total de páginas. Las cabeceras (onHeader) se ejecutan al añadir cada
 * página, cuando el total aún no se conoce: el número de páginas va mejor en el pie, que se ejecuta
 * al final con el total ya resuelto (y con el ancho de las cifras bien medido).
 */
final class PdfWriter
{
    /** Puntos por milímetro. */
    private const K = 72 / 25.4;

    /** Tamaños de papel en puntos (ancho, alto) en vertical. */
    private const SIZES = [
        'A3' => [841.89, 1190.55],
        'A4' => [595.28, 841.89],
        'A5' => [419.53, 595.28],
        'LETTER' => [612.0, 792.0],
        'LEGAL' => [612.0, 1008.0],
    ];

    /** @var array<int,string> contenido de cada página, indexado desde 1 */
    private array $pages = [];

    private int $page = 0;

    /** Tamaño de la página en puntos. */
    private float $wPt;

    private float $hPt;

    /** Tamaño de la página en milímetros. */
    private float $w;

    private float $h;

    private float $lMargin = 10.0;

    private float $tMargin = 10.0;

    private float $rMargin = 10.0;

    private float $bMargin = 10.0;

    private float $cMargin = 1.0;

    private float $x = 10.0;

    private float $y = 10.0;

    private float $lastH = 0.0;

    private bool $autoBreak = true;

    private string $fontFamily = 'Helvetica';

    private string $fontStyle = '';

    private string $baseFont = 'Helvetica';

    private float $fontSizePt = 10.0;

    /** @var array<string,int> fuente base => número de recurso (/F1, /F2…) */
    private array $fonts = [];

    /** @var int[] */
    private array $drawRgb = [0, 0, 0];

    /** @var int[] */
    private array $fillRgb = [0, 0, 0];

    /** @var int[] */
    private array $textRgb = [0, 0, 0];

    private float $lineWidth = 0.2;

    /** @var array<string,array{0:PdfImage,1:int}> ruta => imagen y número de recurso (/I1…) */
    private array $images = [];

    private string $title = '';

    private string $author = '';

    private ?int $creationDate = null;

    private bool $compress = true;

    /** @var callable|null */
    private $headerCallback = null;

    /** @var callable|null */
    private $footerCallback = null;

    private bool $inHeaderFooter = false;

    /** Total de páginas mientras se pintan los pies; null el resto del tiempo. */
    private ?int $nbTotal = null;

    public function __construct(string $orientation = 'P', string $size = 'A4')
    {
        $o = strtoupper(substr(trim($orientation), 0, 1));
        if ($o !== 'P' && $o !== 'L') {
            throw new PdfException('Invalid orientation: ' . $orientation);
        }
        $key = strtoupper(trim($size));
        if (!isset(self::SIZES[$key])) {
            throw new PdfException('Invalid page size: ' . $size);
        }
        [$wPt, $hPt] = self::SIZES[$key];
        if ($o === 'L') {
            [$wPt, $hPt] = [$hPt, $wPt];
        }
        $this->wPt = $wPt;
        $this->hPt = $hPt;
        $this->w = $wPt / self::K;
        $this->h = $hPt / self::K;
        $this->x = $this->lMargin;
        $this->y = $this->tMargin;
    }

    // ------------------------------------------------------------------ configuración

    public function setMargins(float $left, float $top, float $right, float $bottom): void
    {
        if ($left < 0 || $top < 0 || $right < 0 || $bottom < 0 || $left + $right >= $this->w || $top + $bottom >= $this->h) {
            throw new PdfException('Invalid margins');
        }
        $this->lMargin = $left;
        $this->tMargin = $top;
        $this->rMargin = $right;
        $this->bMargin = $bottom;
        if ($this->page > 0 && $this->x < $left) {
            $this->x = $left;
        }
    }

    public function setCellMargin(float $margin): void
    {
        if ($margin < 0) {
            throw new PdfException('Invalid cell margin');
        }
        $this->cMargin = $margin;
    }

    public function setAutoPageBreak(bool $auto): void
    {
        $this->autoBreak = $auto;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function setAuthor(string $author): void
    {
        $this->author = $author;
    }

    /** Fecha de creación (marca de tiempo Unix). Sin ella no se escribe fecha y el resultado es reproducible. */
    public function setCreationDate(int $timestamp): void
    {
        $this->creationDate = $timestamp;
    }

    /** Desactiva la compresión de los flujos (solo para depurar). */
    public function setCompression(bool $on): void
    {
        $this->compress = $on;
    }

    /** Cabecera de cada página: function (PdfWriter $pdf): void. Se ejecuta al añadir la página. */
    public function onHeader(?callable $callback): void
    {
        $this->headerCallback = $callback;
    }

    /** Pie de cada página: function (PdfWriter $pdf): void. Se ejecuta al generar el PDF, con «{nb}» ya resuelto. */
    public function onFooter(?callable $callback): void
    {
        $this->footerCallback = $callback;
    }

    // ------------------------------------------------------------------ páginas y cursor

    public function addPage(): void
    {
        ++$this->page;
        $this->pages[$this->page] = '';
        $this->x = $this->lMargin;
        $this->y = $this->tMargin;
        $this->emitState();

        if ($this->headerCallback !== null && !$this->inHeaderFooter) {
            $saved = $this->snapshot();
            $this->inHeaderFooter = true;
            $this->out('q');
            try {
                ($this->headerCallback)($this);
            } finally {
                $this->out('Q');
                $this->inHeaderFooter = false;
            }
            $y = $this->y;
            $this->restore($saved);
            $this->x = $this->lMargin;
            $this->y = $y;
        }
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    /** Número de la página en la que se está escribiendo (1 si aún no hay ninguna). */
    public function pageNo(): int
    {
        return $this->page > 0 ? $this->page : 1;
    }

    /** Ancho de la página en milímetros. */
    public function pageWidth(): float
    {
        return $this->w;
    }

    /** Alto de la página en milímetros. */
    public function pageHeight(): float
    {
        return $this->h;
    }

    /** Ancho útil entre márgenes, en milímetros. */
    public function contentWidth(): float
    {
        return $this->w - $this->lMargin - $this->rMargin;
    }

    public function getX(): float
    {
        return $this->x;
    }

    public function getY(): float
    {
        return $this->y;
    }

    public function setX(float $x): void
    {
        $this->x = $x >= 0 ? $x : $this->w + $x;
    }

    /** Un valor negativo cuenta desde el borde inferior de la página. */
    public function setY(float $y): void
    {
        $this->y = $y >= 0 ? $y : $this->h + $y;
    }

    public function setXY(float $x, float $y): void
    {
        $this->setX($x);
        $this->setY($y);
    }

    /** Salto de línea: vuelve al margen izquierdo y baja $h (por omisión, el alto de la última celda). */
    public function ln(?float $h = null): void
    {
        $this->x = $this->lMargin;
        $this->y += $h ?? $this->lastH;
    }

    // ------------------------------------------------------------------ fuentes, colores y trazo

    public function setFont(string $family, string $style = '', float $sizePt = 10.0): void
    {
        if ($sizePt <= 0) {
            throw new PdfException('Invalid font size');
        }
        $this->baseFont = PdfFontMetrics::baseFont($family, $style);
        $this->fontFamily = strncmp($this->baseFont, 'Courier', 7) === 0 ? 'Courier' : 'Helvetica';
        $this->fontStyle = strtoupper($style);
        $this->fontSizePt = $sizePt;
    }

    public function setFontSize(float $sizePt): void
    {
        $this->setFont($this->fontFamily, $this->fontStyle, $sizePt);
    }

    public function setDrawColor(int $r, int $g, int $b): void
    {
        $this->drawRgb = self::rgb($r, $g, $b);
        if ($this->page > 0) {
            $this->out(self::colorOp($this->drawRgb, 'RG'));
        }
    }

    public function setFillColor(int $r, int $g, int $b): void
    {
        $this->fillRgb = self::rgb($r, $g, $b);
        if ($this->page > 0) {
            $this->out(self::colorOp($this->fillRgb, 'rg'));
        }
    }

    public function setTextColor(int $r, int $g, int $b): void
    {
        $this->textRgb = self::rgb($r, $g, $b);
    }

    /** Grosor de línea en milímetros. */
    public function setLineWidth(float $width): void
    {
        if ($width < 0) {
            throw new PdfException('Invalid line width');
        }
        $this->lineWidth = $width;
        if ($this->page > 0) {
            $this->out($this->num($width * self::K) . ' w');
        }
    }

    // ------------------------------------------------------------------ medida de texto

    /** Ancho de un texto UTF-8 en milímetros con la fuente y el tamaño actuales. */
    public function stringWidth(string $text): float
    {
        return $this->bytesWidth($this->prepare($text));
    }

    /**
     * Parte un texto en líneas que caben en $maxWidth milímetros: por palabras, y por caracteres cuando
     * una palabra sola no cabe. Respeta los saltos de línea del texto. Un texto vacío da una línea vacía.
     *
     * @return string[]
     */
    public function wrapText(string $text, float $maxWidth): array
    {
        if ($this->nbTotal !== null) {
            $text = str_replace('{nb}', (string) $this->nbTotal, $text);
        }
        $text = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], $text);
        $table = PdfFontMetrics::widths($this->baseFont);
        $scale = $this->fontSizePt / 1000 / self::K;
        $lines = [];
        foreach (explode("\n", $text) as $paragraph) {
            foreach ($this->wrapParagraph(PdfEncoding::chars($paragraph), $maxWidth, $table, $scale) as $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    // ------------------------------------------------------------------ texto y celdas

    /** Escribe el texto con la línea de base en (x, y). No mueve el cursor. */
    public function text(float $x, float $y, string $text): void
    {
        $this->ensurePage();
        $this->putText($x, $y, $this->prepare($text));
    }

    /**
     * Celda: texto alineado ('L', 'C', 'R') con borde y fondo opcionales. Con $w a 0 llega hasta el margen
     * derecho. Después, $ln = 0 deja el cursor a la derecha, 1 al principio de la línea siguiente y 2 debajo.
     */
    public function cell(float $w, float $h, string $text = '', string $align = 'L', bool $border = false, bool $fill = false, int $ln = 0): void
    {
        $this->ensurePage();
        if ($w <= 0) {
            $w = $this->w - $this->rMargin - $this->x;
        }
        if ($this->autoBreak && !$this->inHeaderFooter && $this->y + $h > $this->breakTrigger() + 0.0001) {
            $x = $this->x;
            $this->addPage();
            $this->x = $x;
        }
        $this->drawCell($this->x, $this->y, $w, $h, $this->prepare($text), $align, $border, $fill);
        $this->lastH = $h;
        if ($ln > 0) {
            $this->y += $h;
            if ($ln === 1) {
                $this->x = $this->lMargin;
            }
        } else {
            $this->x += $w;
        }
    }

    /**
     * Texto en varias líneas dentro de un ancho. Devuelve el alto usado. Al terminar, el cursor queda en el
     * margen izquierdo, debajo de la última línea.
     */
    public function multiCell(float $w, float $lineHeight, string $text, string $align = 'L'): float
    {
        $this->ensurePage();
        if ($w <= 0) {
            $w = $this->w - $this->rMargin - $this->x;
        }
        $lines = $this->wrapText($text, $w - 2 * $this->cMargin);
        foreach ($lines as $line) {
            $this->cell($w, $lineHeight, $line, $align, false, false, 2);
        }
        $this->x = $this->lMargin;

        return count($lines) * $lineHeight;
    }

    // ------------------------------------------------------------------ dibujo

    public function line(float $x1, float $y1, float $x2, float $y2): void
    {
        $this->ensurePage();
        $this->out(sprintf('%s %s m %s %s l S', $this->num($x1 * self::K), $this->py($y1), $this->num($x2 * self::K), $this->py($y2)));
    }

    /** Rectángulo; estilo 'D' (borde), 'F' (relleno) o 'DF' (los dos). */
    public function rect(float $x, float $y, float $w, float $h, string $style = 'D'): void
    {
        $this->ensurePage();
        $up = strtoupper($style);
        if ($up === 'F') {
            $op = 'f';
        } elseif ($up === 'DF' || $up === 'FD') {
            $op = 'B';
        } elseif ($up === 'D' || $up === '') {
            $op = 'S';
        } else {
            throw new PdfException('Invalid rectangle style: ' . $style);
        }
        $this->rectOp($x, $y, $w, $h, $op);
    }

    /**
     * Imagen JPEG o PNG. Con $w y $h a 0 se usa su tamaño en píxeles a 96 ppp; si solo falta una
     * dimensión se conserva la proporción.
     */
    public function image(string $path, float $x, float $y, float $w = 0.0, float $h = 0.0): void
    {
        $this->ensurePage();
        if (!isset($this->images[$path])) {
            $this->images[$path] = [PdfImage::fromFile($path), count($this->images) + 1];
        }
        [$image, $index] = $this->images[$path];
        if ($w <= 0 && $h <= 0) {
            $w = $image->width * 25.4 / 96;
            $h = $image->height * 25.4 / 96;
        } elseif ($w <= 0) {
            $w = $h * $image->width / $image->height;
        } elseif ($h <= 0) {
            $h = $w * $image->height / $image->width;
        }
        $this->out(sprintf(
            'q %s 0 0 %s %s %s cm /I%d Do Q',
            $this->num($w * self::K),
            $this->num($h * self::K),
            $this->num($x * self::K),
            $this->py($y + $h),
            $index
        ));
    }

    /**
     * Tabla. Columnas: ['label' => 'Concepto', 'width' => 80, 'align' => 'L'|'R'|'C']. Filas: listas de cadenas
     * en el mismo orden. La cabecera se repite en cada página y todas las celdas de una fila tienen el mismo alto.
     *
     * Opciones: 'lineHeight' (mm, 5), 'header' (true), 'border' (true), 'headerFill' ([230, 230, 230] o null).
     *
     * @param array<int,array<string,mixed>> $columns
     * @param array<int,array<int,mixed>>    $rows
     * @param array<string,mixed>            $options
     */
    public function table(array $columns, array $rows, array $options = []): void
    {
        $this->ensurePage();
        $cols = [];
        foreach (array_values($columns) as $column) {
            if (!is_array($column) || !isset($column['width']) || !is_numeric($column['width']) || (float) $column['width'] <= 0) {
                throw new PdfException('Every table column needs a positive width');
            }
            $align = strtoupper(isset($column['align']) ? (string) $column['align'] : 'L');
            $cols[] = [
                'label' => isset($column['label']) ? (string) $column['label'] : '',
                'width' => (float) $column['width'],
                'align' => in_array($align, ['L', 'R', 'C'], true) ? $align : 'L',
            ];
        }
        if ($cols === []) {
            throw new PdfException('A table needs at least one column');
        }
        $lh = isset($options['lineHeight']) ? (float) $options['lineHeight'] : 5.0;
        if ($lh <= 0) {
            throw new PdfException('Invalid table line height');
        }
        $headerFill = array_key_exists('headerFill', $options) ? $options['headerFill'] : [230, 230, 230];
        if (is_array($headerFill)) {
            $headerFill = array_values($headerFill);
            if (count($headerFill) !== 3) {
                throw new PdfException('headerFill needs three components');
            }
            $headerFill = self::rgb((int) $headerFill[0], (int) $headerFill[1], (int) $headerFill[2]);
        } else {
            $headerFill = null;
        }
        $ctx = [
            'x0' => $this->x,
            'cols' => $cols,
            'lh' => $lh,
            'border' => (bool) ($options['border'] ?? true),
            'header' => (bool) ($options['header'] ?? true),
            'headerFill' => $headerFill,
            'topY' => null,
        ];

        if ($ctx['header']) {
            $this->tableHeader($ctx, $lh);
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new PdfException('Every table row must be an array of cells');
            }
            $row = array_values($row);
            $lineSets = [];
            $total = 1;
            foreach ($cols as $i => $col) {
                $cell = isset($row[$i]) && !is_array($row[$i]) ? (string) $row[$i] : '';
                $lineSets[$i] = $this->wrapText($cell, $col['width'] - 2 * $this->cMargin);
                $total = max($total, count($lineSets[$i]));
            }
            $this->tableRow($ctx, $lineSets, $total);
        }
        $this->x = $ctx['x0'];
    }

    // ------------------------------------------------------------------ salida

    /** El PDF completo en una cadena. Se puede llamar varias veces: el resultado es el mismo. */
    public function output(): string
    {
        $this->ensurePage();
        $backup = $this->pages;
        try {
            $total = count($this->pages);
            $this->runFooters($total);
            $result = $this->build($total);
        } finally {
            $this->pages = $backup;
        }

        return $result;
    }

    public function save(string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            throw new PdfException('Directory does not exist: ' . $dir);
        }
        if (file_put_contents($path, $this->output(), LOCK_EX) === false) {
            throw new PdfException('Cannot write file: ' . $path);
        }
    }

    // ------------------------------------------------------------------ internos: estado

    private function ensurePage(): void
    {
        if ($this->page === 0) {
            $this->addPage();
        }
    }

    private function breakTrigger(): float
    {
        return $this->h - $this->bMargin;
    }

    private function out(string $operators): void
    {
        $this->pages[$this->page] .= $operators . "\n";
    }

    /** Pone al principio de la página el grosor y los colores de trazo y relleno vigentes. */
    private function emitState(): void
    {
        $this->out($this->num($this->lineWidth * self::K) . ' w');
        $this->out(self::colorOp($this->drawRgb, 'RG'));
        $this->out(self::colorOp($this->fillRgb, 'rg'));
    }

    /** @return array<string,mixed> */
    private function snapshot(): array
    {
        return [
            'family' => $this->fontFamily,
            'style' => $this->fontStyle,
            'size' => $this->fontSizePt,
            'draw' => $this->drawRgb,
            'fill' => $this->fillRgb,
            'text' => $this->textRgb,
            'line' => $this->lineWidth,
            'x' => $this->x,
            'y' => $this->y,
            'lastH' => $this->lastH,
            'page' => $this->page,
        ];
    }

    /**
     * Devuelve los valores de fuente, colores y grosor a lo que había (el flujo de la página ya se cierra
     * con «Q» y recupera el suyo; esto es para las propiedades).
     *
     * @param array<string,mixed> $s
     */
    private function restore(array $s): void
    {
        $this->setFont((string) $s['family'], (string) $s['style'], (float) $s['size']);
        $this->drawRgb = $s['draw'];
        $this->fillRgb = $s['fill'];
        $this->textRgb = $s['text'];
        $this->lineWidth = (float) $s['line'];
        $this->lastH = (float) $s['lastH'];
    }

    private function runFooters(int $total): void
    {
        if ($this->footerCallback === null) {
            return;
        }
        $saved = $this->snapshot();
        $this->nbTotal = $total;
        $this->inHeaderFooter = true;
        try {
            for ($p = 1; $p <= $total; ++$p) {
                $this->page = $p;
                $this->x = $this->lMargin;
                $this->y = $this->breakTrigger();
                $this->out('q');
                $this->emitState();
                try {
                    ($this->footerCallback)($this);
                } finally {
                    $this->out('Q');
                }
                $this->restore($saved);
            }
        } finally {
            $this->nbTotal = null;
            $this->inHeaderFooter = false;
            $this->page = (int) $saved['page'];
            $this->x = (float) $saved['x'];
            $this->y = (float) $saved['y'];
            $this->restore($saved);
        }
    }

    // ------------------------------------------------------------------ internos: texto

    /** Texto UTF-8 -> bytes WinAnsi, con «{nb}» resuelto cuando ya se conoce el total. */
    private function prepare(string $text): string
    {
        if ($this->nbTotal !== null && strpos($text, '{nb}') !== false) {
            $text = str_replace('{nb}', (string) $this->nbTotal, $text);
        }

        return PdfEncoding::encode($text);
    }

    private function bytesWidth(string $bytes): float
    {
        return PdfFontMetrics::width($this->baseFont, $bytes) * $this->fontSizePt / 1000 / self::K;
    }

    private function fontIndex(): int
    {
        if (!isset($this->fonts[$this->baseFont])) {
            $this->fonts[$this->baseFont] = count($this->fonts) + 1;
        }

        return $this->fonts[$this->baseFont];
    }

    private function putText(float $x, float $baseline, string $bytes): void
    {
        if ($bytes === '') {
            return;
        }
        $this->out(sprintf(
            'q %s BT /F%d %s Tf %s %s Td (%s) Tj ET Q',
            self::colorOp($this->textRgb, 'rg'),
            $this->fontIndex(),
            $this->num($this->fontSizePt),
            $this->num($x * self::K),
            $this->py($baseline),
            PdfEncoding::escape($bytes)
        ));
    }

    /** Celda sin tocar el cursor ni saltar de página. $bytes ya va en WinAnsi. */
    private function drawCell(float $x, float $y, float $w, float $h, string $bytes, string $align, bool $border, bool $fill): void
    {
        if ($fill || $border) {
            $this->rectOp($x, $y, $w, $h, $fill ? ($border ? 'B' : 'f') : 'S');
        }
        if ($bytes === '') {
            return;
        }
        $tw = $this->bytesWidth($bytes);
        $a = strtoupper($align);
        if ($a === 'R') {
            $dx = $w - $this->cMargin - $tw;
        } elseif ($a === 'C') {
            $dx = ($w - $tw) / 2;
        } else {
            $dx = $this->cMargin;
        }
        $baseline = $y + 0.5 * $h + 0.3 * ($this->fontSizePt / self::K);
        $this->putText($x + $dx, $baseline, $bytes);
    }

    private function rectOp(float $x, float $y, float $w, float $h, string $op): void
    {
        $this->out(sprintf('%s %s %s %s re %s', $this->num($x * self::K), $this->py($y + $h), $this->num($w * self::K), $this->num($h * self::K), $op));
    }

    /**
     * @param string[]  $chars
     * @param int[]     $table
     *
     * @return string[]
     */
    private function wrapParagraph(array $chars, float $max, array $table, float $scale): array
    {
        if ($chars === []) {
            return [''];
        }
        $words = [[]];
        foreach ($chars as $c) {
            if ($c === ' ') {
                $words[] = [];
            } else {
                $words[count($words) - 1][] = $c;
            }
        }
        $spaceWidth = $table[32] * $scale;
        $lines = [];
        $cur = [];
        $curWidth = 0.0;
        $eps = 0.0001;
        foreach ($words as $index => $word) {
            $wordWidth = 0.0;
            foreach ($word as $c) {
                $wordWidth += $table[PdfEncoding::byteFor($c)] * $scale;
            }
            $join = $index === 0 ? 0.0 : $spaceWidth;
            if ($curWidth + $join + $wordWidth <= $max + $eps) {
                if ($index > 0) {
                    $cur[] = ' ';
                }
                foreach ($word as $c) {
                    $cur[] = $c;
                }
                $curWidth += $join + $wordWidth;
                continue;
            }
            if ($cur !== []) {
                $lines[] = rtrim(implode('', $cur), ' ');
                $cur = [];
                $curWidth = 0.0;
            }
            if ($wordWidth <= $max + $eps) {
                $cur = $word;
                $curWidth = $wordWidth;
                continue;
            }
            // Una palabra que no cabe ni sola en una línea: se parte por caracteres.
            foreach ($word as $c) {
                $cw = $table[PdfEncoding::byteFor($c)] * $scale;
                if ($cur !== [] && $curWidth + $cw > $max + $eps) {
                    $lines[] = implode('', $cur);
                    $cur = [];
                    $curWidth = 0.0;
                }
                $cur[] = $c;
                $curWidth += $cw;
            }
        }
        if ($cur !== [] || $lines === []) {
            $lines[] = rtrim(implode('', $cur), ' ');
        }

        return $lines;
    }

    // ------------------------------------------------------------------ internos: tabla

    /**
     * @param array<string,mixed> $ctx
     */
    private function tableHeader(array &$ctx, float $firstRowHeight): void
    {
        $family = $this->fontFamily;
        $style = $this->fontStyle;
        $size = $this->fontSizePt;
        $this->setFont($family, 'B', $size);
        try {
            $lineSets = [];
            $total = 1;
            foreach ($ctx['cols'] as $i => $col) {
                $lineSets[$i] = $this->wrapText($col['label'], $col['width'] - 2 * $this->cMargin);
                $total = max($total, count($lineSets[$i]));
            }
            $height = $total * $ctx['lh'];
            $newPage = false;
            if ($this->autoBreak && !$this->inHeaderFooter && $this->y + $height + $firstRowHeight > $this->breakTrigger() + 0.0001) {
                $this->addPage();
                $this->x = $ctx['x0'];
                $newPage = true;
            }
            $previousFill = $this->fillRgb;
            $filled = is_array($ctx['headerFill']);
            if ($filled) {
                $this->setFillColor($ctx['headerFill'][0], $ctx['headerFill'][1], $ctx['headerFill'][2]);
            }
            $this->tableChunk($ctx, $lineSets, 0, $total, $filled);
            $this->y += $height;
            if ($newPage) {
                $ctx['topY'] = $this->y;
            }
            if ($filled) {
                $this->setFillColor($previousFill[0], $previousFill[1], $previousFill[2]);
            }
        } finally {
            $this->setFont($family, $style, $size);
        }
    }

    /**
     * Pinta una fila de la tabla; si no cabe en lo que queda de página, la pasa a la siguiente (con la cabecera
     * repetida), y si es más alta que una página entera la reparte por líneas.
     *
     * @param array<string,mixed> $ctx
     * @param array<int,string[]> $lineSets
     */
    private function tableRow(array &$ctx, array $lineSets, int $total): void
    {
        $lh = $ctx['lh'];
        $offset = 0;
        while ($offset < $total) {
            $remaining = $total - $offset;
            if ($this->autoBreak && !$this->inHeaderFooter) {
                $fit = (int) floor(($this->breakTrigger() - $this->y + 0.0001) / $lh);
            } else {
                $fit = $remaining;
            }
            $atTop = $ctx['topY'] !== null && abs($this->y - (float) $ctx['topY']) < 0.01;
            $room = $this->breakTrigger() - ($ctx['topY'] ?? $this->tMargin);
            $move = !$atTop && ($fit < 1 || ($offset === 0 && $remaining > $fit && $remaining * $lh <= $room));
            if ($move) {
                $this->tableNewPage($ctx);
                continue;
            }
            $n = max(1, min($fit, $remaining));
            $this->tableChunk($ctx, $lineSets, $offset, $n, false);
            $this->y += $n * $lh;
            $offset += $n;
            if ($offset < $total) {
                $this->tableNewPage($ctx);
            }
        }
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private function tableNewPage(array &$ctx): void
    {
        $this->addPage();
        $this->x = $ctx['x0'];
        $ctx['topY'] = $this->y;
        if ($ctx['header']) {
            $this->tableHeader($ctx, 0.0);
            $ctx['topY'] = $this->y;
        }
    }

    /**
     * Dibuja las líneas [$offset, $offset + $n) de cada columna, con el recuadro del tramo.
     *
     * @param array<string,mixed> $ctx
     * @param array<int,string[]> $lineSets
     */
    private function tableChunk(array $ctx, array $lineSets, int $offset, int $n, bool $fill): void
    {
        $lh = $ctx['lh'];
        $x = $ctx['x0'];
        foreach ($ctx['cols'] as $i => $col) {
            if ($ctx['border'] || $fill) {
                $this->rectOp($x, $this->y, $col['width'], $n * $lh, $fill ? ($ctx['border'] ? 'B' : 'f') : 'S');
            }
            for ($k = 0; $k < $n; ++$k) {
                $line = $lineSets[$i][$offset + $k] ?? '';
                if ($line !== '') {
                    $this->drawCell($x, $this->y + $k * $lh, $col['width'], $lh, $this->prepare($line), $col['align'], false, false);
                }
            }
            $x += $col['width'];
        }
    }

    // ------------------------------------------------------------------ internos: formato y objetos PDF

    /** Coordenada Y del PDF (hacia arriba, en puntos) para una Y de usuario (hacia abajo, en mm). */
    private function py(float $y): string
    {
        return $this->num($this->hPt - $y * self::K);
    }

    private function num(float $value, int $decimals = 2): string
    {
        $s = number_format($value, $decimals, '.', '');
        if (strpos($s, '.') !== false) {
            $s = rtrim(rtrim($s, '0'), '.');
        }

        return ($s === '' || $s === '-0') ? '0' : $s;
    }

    /** @return int[] */
    private static function rgb(int $r, int $g, int $b): array
    {
        foreach ([$r, $g, $b] as $v) {
            if ($v < 0 || $v > 255) {
                throw new PdfException('Color components must be between 0 and 255');
            }
        }

        return [$r, $g, $b];
    }

    /**
     * @param int[] $rgb
     */
    private static function colorOp(array $rgb, string $op): string
    {
        return sprintf('%.3F %.3F %.3F %s', $rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255, $op);
    }

    private function stream(string $dictionary, string $data): string
    {
        return '<< ' . $dictionary . ' /Length ' . strlen($data) . " >>\nstream\n" . $data . "\nendstream";
    }

    private function build(int $total): string
    {
        $fontIds = [];
        $nextId = 4;
        foreach ($this->fonts as $baseFont => $index) {
            $fontIds[$index] = [$baseFont, $nextId++];
        }
        $imageIds = [];
        foreach ($this->images as $entry) {
            $imageIds[$entry[1]] = [$entry[0], $nextId++];
        }
        $infoId = $nextId++;
        $pageIds = [];
        for ($p = 1; $p <= $total; ++$p) {
            $pageIds[$p] = [$nextId, $nextId + 1];
            $nextId += 2;
        }
        $lastId = $nextId - 1;

        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        $objects = [];

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = [];
        foreach ($pageIds as $ids) {
            $kids[] = $ids[0] . ' 0 R';
        }
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $total . ' >>';

        $fontRes = '';
        foreach ($fontIds as $index => $pair) {
            $fontRes .= '/F' . $index . ' ' . $pair[1] . ' 0 R ';
            $objects[$pair[1]] = '<< /Type /Font /Subtype /Type1 /BaseFont /' . $pair[0] . ' /Encoding /WinAnsiEncoding >>';
        }
        $res = '<< /ProcSet [/PDF /Text /ImageB /ImageC /ImageI]';
        if ($fontRes !== '') {
            $res .= ' /Font << ' . $fontRes . '>>';
        }
        if ($imageIds !== []) {
            $imageRes = '';
            foreach ($imageIds as $index => $pair) {
                $imageRes .= '/I' . $index . ' ' . $pair[1] . ' 0 R ';
            }
            $res .= ' /XObject << ' . $imageRes . '>>';
        }
        $objects[3] = $res . ' >>';

        foreach ($imageIds as $pair) {
            /** @var PdfImage $image */
            $image = $pair[0];
            $dict = '/Type /XObject /Subtype /Image /Width ' . $image->width . ' /Height ' . $image->height
                . ' /ColorSpace /' . $image->colorSpace . ' /BitsPerComponent 8 /Filter /' . $image->filter
                . ($image->decode !== '' ? ' /Decode ' . $image->decode : '');
            $objects[$pair[1]] = $this->stream($dict, $image->data);
        }

        $info = '<< /Producer (AIFEC)';
        if ($this->title !== '') {
            $info .= ' /Title ' . PdfEncoding::pdfString($this->title);
        }
        if ($this->author !== '') {
            $info .= ' /Author ' . PdfEncoding::pdfString($this->author);
        }
        if ($this->creationDate !== null) {
            $info .= ' /CreationDate (D:' . gmdate('YmdHis', $this->creationDate) . 'Z)';
        }
        $objects[$infoId] = $info . ' >>';

        $mediaBox = '[0 0 ' . $this->num($this->wPt) . ' ' . $this->num($this->hPt) . ']';
        foreach ($pageIds as $p => $ids) {
            $raw = str_replace('{nb}', (string) $total, $this->pages[$p]);
            if ($this->compress) {
                $data = gzcompress($raw, 6);
                if (!is_string($data)) {
                    throw new PdfException('Cannot compress page content');
                }
                $objects[$ids[1]] = $this->stream('/Filter /FlateDecode', $data);
            } else {
                $objects[$ids[1]] = $this->stream('', $raw);
            }
            $objects[$ids[0]] = '<< /Type /Page /Parent 2 0 R /MediaBox ' . $mediaBox . ' /Resources 3 0 R /Contents ' . $ids[1] . ' 0 R >>';
        }

        for ($id = 1; $id <= $lastId; ++$id) {
            $offsets[$id] = strlen($out);
            $out .= $id . " 0 obj\n" . $objects[$id] . "\nendobj\n";
        }

        $xref = strlen($out);
        $count = $lastId + 1;
        $out .= "xref\n0 " . $count . "\n0000000000 65535 f \n";
        for ($id = 1; $id <= $lastId; ++$id) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }
        $fileId = strtoupper(md5($out));
        $out .= "trailer\n<< /Size " . $count . ' /Root 1 0 R /Info ' . $infoId . ' 0 R /ID [<' . $fileId . '> <' . $fileId . ">] >>\nstartxref\n" . $xref . "\n%%EOF";

        return $out;
    }
}
