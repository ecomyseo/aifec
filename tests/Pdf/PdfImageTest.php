<?php
/**
 * AIFEC — pruebas de las imágenes del generador de PDF.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Pdf\PdfException;
use Aifec\Pdf\PdfWriter;

require_once __DIR__ . '/helpers.php';

/** Crea un JPEG de $w × $h píxeles y devuelve su ruta. */
function pdfTestJpeg(string $dir, int $w, int $h): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, (int) imagecolorallocate($im, 200, 30, 30));
    $path = $dir . '/foto-' . $w . 'x' . $h . '.jpg';
    imagejpeg($im, $path, 85);

    return $path;
}

test('un JPEG se incrusta tal cual con su ancho, su alto y DCTDecode', function (): void {
    $dir = tmpDir();
    $path = pdfTestJpeg($dir, 20, 10);
    $pdf = new PdfWriter();
    $pdf->addPage();
    $pdf->image($path, 10, 10, 40);
    $data = $pdf->output();
    contains('/Subtype /Image /Width 20 /Height 10 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode', $data);
    check(strpos($data, (string) file_get_contents($path)) !== false, 'los bytes del JPEG van sin tocar');
    contains('/XObject << /I1 ', $data);
    pdfTestCheckXref($data);
});

test('si falta una dimensión se conserva la proporción, y sin ninguna se usan 96 ppp', function (): void {
    $k = 72 / 25.4;
    $dir = tmpDir();
    $path = pdfTestJpeg($dir, 20, 10);
    $pdf = new PdfWriter();
    $pdf->setCompression(false);
    $pdf->addPage();
    $pdf->image($path, 10, 10, 40);        // alto = 20
    $pdf->image($path, 10, 50, 0, 30);     // ancho = 60
    $pdf->image($path, 10, 100);           // 20 px y 10 px a 96 ppp
    $c = pdfTestContent($pdf->output());
    contains(sprintf('q %.2F 0 0 %.2F %.2F %.2F cm /I1 Do Q', 40 * $k, 20 * $k, 10 * $k, 841.89 - 30 * $k), $c);
    contains(sprintf('q %.2F 0 0 %.2F %.2F %.2F cm /I1 Do Q', 60 * $k, 30 * $k, 10 * $k, 841.89 - 80 * $k), $c);
    // 20 px a 96 ppp son 15 pt y 10 px son 7,5 pt
    contains('q 15 0 0 7.5 ', $c);
});

test('la misma imagen usada dos veces se incrusta una sola vez', function (): void {
    $dir = tmpDir();
    $path = pdfTestJpeg($dir, 8, 8);
    $pdf = new PdfWriter();
    $pdf->addPage();
    $pdf->image($path, 10, 10, 20);
    $pdf->image($path, 40, 10, 20);
    $pdf->addPage();
    $pdf->image($path, 10, 10, 20);
    $data = $pdf->output();
    same(1, substr_count($data, '/Subtype /Image'));
    same(3, substr_count(pdfTestContent($data), '/I1 Do'));
});

test('un PNG se convierte a RGB con la transparencia aplanada sobre blanco', function (): void {
    $dir = tmpDir();
    $im = imagecreatetruecolor(4, 4);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    imagefilledrectangle($im, 0, 0, 3, 3, (int) imagecolorallocatealpha($im, 0, 0, 0, 127));
    imagesetpixel($im, 1, 1, (int) imagecolorallocatealpha($im, 255, 0, 0, 0));
    imagesetpixel($im, 2, 2, (int) imagecolorallocatealpha($im, 0, 0, 0, 64));
    $path = $dir . '/logo.png';
    imagepng($im, $path);

    $pdf = new PdfWriter();
    $pdf->addPage();
    $pdf->image($path, 10, 10, 20);
    $data = $pdf->output();
    check(preg_match('/\/Subtype \/Image \/Width 4 \/Height 4 \/ColorSpace \/DeviceRGB \/BitsPerComponent 8 \/Filter \/FlateDecode \/Length (\d+) >>\nstream\n/', $data, $m, PREG_OFFSET_CAPTURE) === 1, 'el objeto imagen del PNG');
    $raw = gzuncompress(substr($data, $m[0][1] + strlen($m[0][0]), (int) $m[1][0]));
    check(is_string($raw));
    same(4 * 4 * 3, strlen($raw));
    same("\xFF\xFF\xFF", substr($raw, 0, 3), 'lo transparente sale blanco');
    same("\xFF\x00\x00", substr($raw, (1 * 4 + 1) * 3, 3), 'lo opaco conserva su color');
    $half = ord($raw[(2 * 4 + 2) * 3]);
    check($half > 100 && $half < 160, "negro al 50 % sobre blanco da un gris medio, no {$half}");
    pdfTestCheckXref($data);
});

test('un PNG de paleta con color transparente también se incrusta', function (): void {
    $dir = tmpDir();
    $im = imagecreate(6, 3);
    $bg = (int) imagecolorallocate($im, 255, 255, 255);
    imagecolorallocate($im, 0, 0, 255);
    imagecolortransparent($im, $bg);
    $path = $dir . '/paleta.png';
    imagepng($im, $path);
    $pdf = new PdfWriter();
    $pdf->addPage();
    $pdf->image($path, 10, 10);
    contains('/Width 6 /Height 3', $pdf->output());
});

test('las imágenes que no se pueden usar lanzan PdfException', function (): void {
    $dir = tmpDir();
    $pdf = new PdfWriter();
    throws(function () use ($pdf, $dir): void {
        $pdf->image($dir . '/no-existe.png', 10, 10);
    }, PdfException::class);

    file_put_contents($dir . '/texto.png', 'esto no es una imagen');
    throws(function () use ($pdf, $dir): void {
        $pdf->image($dir . '/texto.png', 10, 10);
    }, PdfException::class);

    $gif = imagecreate(2, 2);
    imagecolorallocate($gif, 1, 2, 3);
    imagegif($gif, $dir . '/dibujo.gif');
    throws(function () use ($pdf, $dir): void {
        $pdf->image($dir . '/dibujo.gif', 10, 10);
    }, PdfException::class, 'solo JPEG y PNG');

    $good = imagecreatetruecolor(8, 8);
    imagepng($good, $dir . '/roto.png');
    $bytes = (string) file_get_contents($dir . '/roto.png');
    file_put_contents($dir . '/roto.png', substr($bytes, 0, (int) (strlen($bytes) * 0.6)));
    throws(function () use ($pdf, $dir): void {
        $pdf->image($dir . '/roto.png', 10, 10);
    }, PdfException::class, 'un PNG cortado no pasa');
});
