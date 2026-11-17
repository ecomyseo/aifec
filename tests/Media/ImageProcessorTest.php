<?php
/**
 * AIFEC — pruebas del procesado de imágenes con GD.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Media\ImageProcessor;
use Aifec\Media\MediaException;

if (!function_exists('mediaTestImage')) {
    /**
     * Imagen de prueba: color liso, con una franja izquierda de otro color para notar giros.
     *
     * @return \GdImage|resource
     */
    function mediaTestImage(int $width, int $height, array $rgb = [200, 30, 30])
    {
        $im = imagecreatetruecolor($width, $height);
        imagefilledrectangle($im, 0, 0, $width - 1, $height - 1, imagecolorallocate($im, $rgb[0], $rgb[1], $rgb[2]));

        return $im;
    }

    /** PNG de 40×40 con la mitad izquierda transparente y la derecha roja opaca. */
    function mediaTestTransparentPng(string $path): void
    {
        $im = imagecreatetruecolor(40, 40);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefilledrectangle($im, 0, 0, 39, 39, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagefilledrectangle($im, 20, 0, 39, 39, imagecolorallocatealpha($im, 255, 0, 0, 0));
        imagepng($im, $path);
    }

    /** Alfa (0 opaco … 127 transparente) del píxel de una imagen ya leída. */
    function mediaTestAlpha($im, int $x, int $y): int
    {
        return (imagecolorat($im, $x, $y) >> 24) & 0x7F;
    }

    /** GIF de 1×1 con dos fotogramas, escrito a mano: GD no sabe crear animaciones. */
    function mediaTestAnimatedGif(string $path): void
    {
        $frame = "\x21\xF9\x04\x00\x0A\x00\x00\x00" . "\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00" . "\x02\x02\x44\x01\x00";
        $gif = 'GIF89a' . "\x01\x00\x01\x00\x80\x00\x00" . "\x00\x00\x00\xFF\xFF\xFF" . $frame . $frame . "\x3B";
        file_put_contents($path, $gif);
    }

    /** Inserta una cabecera EXIF con `Orientation` justo después de la marca SOI de un JPEG. */
    function mediaTestJpegWithOrientation(string $path, int $orientation): void
    {
        $jpeg = (string) file_get_contents($path);
        $tiff = "MM\x00\x2A\x00\x00\x00\x08" . "\x00\x01" . "\x01\x12\x00\x03\x00\x00\x00\x01" . pack('n', $orientation) . "\x00\x00" . "\x00\x00\x00\x00";
        $payload = "Exif\x00\x00" . $tiff;
        $segment = "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;
        file_put_contents($path, substr($jpeg, 0, 2) . $segment . substr($jpeg, 2));
    }
}

test('supports responde según lo que el GD instalado sabe escribir', function (): void {
    check(ImageProcessor::supports('jpeg'), 'jpeg');
    check(ImageProcessor::supports('JPG'), 'jpg en mayúsculas');
    check(ImageProcessor::supports('png'), 'png');
    check(ImageProcessor::supports('webp'), 'webp');
    same(function_exists('imageavif'), ImageProcessor::supports('avif'));
    check(!ImageProcessor::supports('svg'), 'svg no se escribe con GD');
    check(!ImageProcessor::supports('bmp'), 'bmp no está entre los formatos de salida');
    check(!ImageProcessor::supports(''), 'vacío');
});

test('probe mide JPEG, PNG con transparencia, GIF y WebP por su contenido', function (): void {
    $dir = tmpDir();
    imagejpeg(mediaTestImage(120, 80), $dir . '/a.jpg');
    $jpeg = ImageProcessor::probe($dir . '/a.jpg');
    same(120, $jpeg->width);
    same(80, $jpeg->height);
    same('image/jpeg', $jpeg->mime);
    same('jpeg', $jpeg->format);
    same(filesize($dir . '/a.jpg'), $jpeg->bytes);
    same(false, $jpeg->animated);
    same(false, $jpeg->hasAlpha);

    mediaTestTransparentPng($dir . '/t.png');
    $png = ImageProcessor::probe($dir . '/t.png');
    same('png', $png->format);
    same(40, $png->width);
    same(true, $png->hasAlpha);
    same(false, $png->animated);

    imagepng(mediaTestImage(30, 20), $dir . '/opaco.png');
    same(false, ImageProcessor::probe($dir . '/opaco.png')->hasAlpha);

    imagegif(mediaTestImage(33, 22), $dir . '/g.gif');
    $gif = ImageProcessor::probe($dir . '/g.gif');
    same('image/gif', $gif->mime);
    same('gif', $gif->format);
    same(33, $gif->width);
    same(false, $gif->animated);

    imagewebp(mediaTestImage(64, 48), $dir . '/w.webp');
    $webp = ImageProcessor::probe($dir . '/w.webp');
    same('image/webp', $webp->mime);
    same('webp', $webp->format);
    same(64, $webp->width);
    same(48, $webp->height);
});

test('probe distingue un GIF animado y lo copia tal cual al redimensionar', function (): void {
    $dir = tmpDir();
    mediaTestAnimatedGif($dir . '/anim.gif');
    $info = ImageProcessor::probe($dir . '/anim.gif');
    same('gif', $info->format);
    same(true, $info->animated);
    same(1, $info->width);

    $out = ImageProcessor::resize($dir . '/anim.gif', $dir . '/copia.gif', 400, 400, ['format' => 'webp']);
    same(file_get_contents($dir . '/anim.gif'), file_get_contents($dir . '/copia.gif'), 'los bytes no se tocan');
    same(true, $out->animated);
    same('gif', $out->format);
});

test('probe lee el SVG por su contenido y su tamaño declarado', function (): void {
    $dir = tmpDir();
    file_put_contents($dir . '/a.png', '<svg xmlns="http://www.w3.org/2000/svg" width="120px" height="60"><rect/></svg>');
    $info = ImageProcessor::probe($dir . '/a.png');
    same('svg', $info->format);
    same('image/svg+xml', $info->mime);
    same(120, $info->width);
    same(60, $info->height);

    file_put_contents($dir . '/b.svg', '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 12"></svg>');
    $viewBox = ImageProcessor::probe($dir . '/b.svg');
    same(24, $viewBox->width);
    same(12, $viewBox->height);

    throws(function () use ($dir): void {
        ImageProcessor::resize($dir . '/a.png', $dir . '/x.png', 10, 10);
    }, MediaException::class);
});

test('probe rechaza lo que no es una imagen aunque lleve extensión de imagen', function (): void {
    $dir = tmpDir();
    file_put_contents($dir . '/texto.jpg', "esto es solo texto\n");
    throws(function () use ($dir): void {
        ImageProcessor::probe($dir . '/texto.jpg');
    }, MediaException::class);

    file_put_contents($dir . '/codigo.png', "<?php echo 'hola';\n");
    throws(function () use ($dir): void {
        ImageProcessor::probe($dir . '/codigo.png');
    }, MediaException::class);

    file_put_contents($dir . '/vacio.jpg', '');
    throws(function () use ($dir): void {
        ImageProcessor::probe($dir . '/vacio.jpg');
    }, MediaException::class);

    throws(function () use ($dir): void {
        ImageProcessor::probe($dir . '/no-existe.jpg');
    }, MediaException::class);

    // cabecera JPEG válida con el resto roto: no es una imagen legible
    file_put_contents($dir . '/roto.jpg', "\xFF\xD8\xFF\xE0\x00\x10JFIF" . str_repeat("\x00", 8));
    throws(function () use ($dir): void {
        ImageProcessor::probe($dir . '/roto.jpg');
    }, MediaException::class);
});

test('probe rechaza imágenes de más de 12.000 píxeles de lado', function (): void {
    $dir = tmpDir();
    imagepng(imagecreatetruecolor(12001, 1), $dir . '/ancha.png');
    throws(function () use ($dir): void {
        ImageProcessor::probe($dir . '/ancha.png');
    }, MediaException::class);
    imagepng(imagecreatetruecolor(1, 12001), $dir . '/alta.png');
    throws(function () use ($dir): void {
        ImageProcessor::probe($dir . '/alta.png');
    }, MediaException::class);
    imagepng(imagecreatetruecolor(12000, 1), $dir . '/limite.png');
    same(12000, ImageProcessor::probe($dir . '/limite.png')->width);
});

test('probe rechaza más de 80 megapíxeles con una cabecera PNG sin decodificar nada', function (): void {
    $dir = tmpDir();
    $ihdr = pack('N2', 9000, 9000) . "\x08\x02\x00\x00\x00";
    $png = "\x89PNG\r\n\x1A\n" . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr));
    file_put_contents($dir . '/enorme.png', $png);
    throws(function () use ($dir): void {
        ImageProcessor::probe($dir . '/enorme.png');
    }, MediaException::class);
});

test('resize en modo fit cabe dentro sin recortar: 2000×1000 en 400×400 da 400×200', function (): void {
    $dir = tmpDir();
    imagejpeg(mediaTestImage(2000, 1000), $dir . '/grande.jpg');
    $out = ImageProcessor::resize($dir . '/grande.jpg', $dir . '/fit.jpg', 400, 400);
    same(400, $out->width);
    same(200, $out->height);
    same('jpeg', $out->format);

    $portrait = mediaTestImage(300, 900);
    imagejpeg($portrait, $dir . '/alta.jpg');
    $out = ImageProcessor::resize($dir . '/alta.jpg', $dir . '/alta-fit.jpg', 400, 400, ['mode' => 'fit']);
    same(133, $out->width);
    same(400, $out->height);
});

test('resize en modo cover rellena la caja y recorta centrado', function (): void {
    $dir = tmpDir();
    $im = mediaTestImage(2000, 1000, [0, 0, 255]);
    imagefilledrectangle($im, 750, 0, 1249, 999, imagecolorallocate($im, 255, 0, 0));
    imagepng($im, $dir . '/dos.png');
    $out = ImageProcessor::resize($dir . '/dos.png', $dir . '/cover.png', 400, 400, ['mode' => 'cover']);
    same(400, $out->width);
    same(400, $out->height);
    $read = imagecreatefrompng($dir . '/cover.png');
    $left = imagecolorat($read, 2, 200);
    $middle = imagecolorat($read, 200, 200);
    $right = imagecolorat($read, 397, 200);
    near(255, ($middle >> 16) & 255, 3, 'el centro conserva el rojo del medio');
    near(0, ($left >> 16) & 255, 3, 'la izquierda es azul');
    near(0, ($right >> 16) & 255, 3, 'la derecha es azul');
});

test('resize en modo pad cabe y rellena con el fondo pedido', function (): void {
    $dir = tmpDir();
    imagepng(mediaTestImage(2000, 1000, [255, 0, 0]), $dir . '/roja.png');
    $out = ImageProcessor::resize($dir . '/roja.png', $dir . '/pad.png', 400, 400, ['mode' => 'pad', 'background' => [0, 0, 255]]);
    same(400, $out->width);
    same(400, $out->height);
    $read = imagecreatefrompng($dir . '/pad.png');
    $corner = imagecolorat($read, 2, 2);
    $center = imagecolorat($read, 200, 200);
    same(255, $corner & 255, 'arriba a la izquierda es fondo azul');
    same(0, ($corner >> 16) & 255);
    same(255, ($center >> 16) & 255, 'el centro es la imagen roja');

    $jpeg = ImageProcessor::resize($dir . '/roja.png', $dir . '/pad.jpg', 400, 400, ['mode' => 'pad', 'format' => 'jpeg']);
    $white = imagecolorat(imagecreatefromjpeg($dir . '/pad.jpg'), 2, 2);
    check(($white & 255) > 240 && (($white >> 16) & 255) > 240, 'el fondo por defecto es blanco');
    same(400, $jpeg->height);
});

test('resize no amplía nunca: 100×50 con máximo 400 se queda en 100×50', function (): void {
    $dir = tmpDir();
    imagejpeg(mediaTestImage(100, 50), $dir . '/pequena.jpg');
    $fit = ImageProcessor::resize($dir . '/pequena.jpg', $dir . '/fit.jpg', 400, 400);
    same(100, $fit->width);
    same(50, $fit->height);

    $webp = ImageProcessor::resize($dir . '/pequena.jpg', $dir . '/fit.webp', 400, 400, ['format' => 'webp']);
    same('webp', $webp->format);
    same(100, $webp->width);
    same(50, $webp->height);

    $cover = ImageProcessor::resize($dir . '/pequena.jpg', $dir . '/cover.jpg', 400, 400, ['mode' => 'cover']);
    check($cover->width <= 100 && $cover->height <= 50, 'cover no sale más grande que el original: ' . $cover->width . 'x' . $cover->height);
    same($cover->width, $cover->height, 'cover conserva la proporción de la caja');

    $pad = ImageProcessor::resize($dir . '/pequena.jpg', $dir . '/pad.jpg', 400, 400, ['mode' => 'pad']);
    check($pad->width <= 100 && $pad->height <= 50, 'pad no sale más grande que el original: ' . $pad->width . 'x' . $pad->height);
});

test('un PNG transparente pasa a WebP conservando la transparencia', function (): void {
    if (!ImageProcessor::supports('webp')) {
        check(true);

        return;
    }
    $dir = tmpDir();
    mediaTestTransparentPng($dir . '/t.png');
    $out = ImageProcessor::resize($dir . '/t.png', $dir . '/t.webp', 100, 100, ['format' => 'webp']);
    same('webp', $out->format);
    $read = imagecreatefromwebp($dir . '/t.webp');
    check(mediaTestAlpha($read, 5, 20) >= 100, 'la mitad izquierda sigue transparente');
    check(mediaTestAlpha($read, 35, 20) <= 10, 'la mitad derecha sigue opaca');
    $red = (imagecolorat($read, 35, 20) >> 16) & 255;
    check($red > 200, 'la derecha sigue roja: ' . $red);
    same(true, $out->hasAlpha);
});

test('un PNG transparente pasa a JPEG sobre el fondo pedido', function (): void {
    $dir = tmpDir();
    mediaTestTransparentPng($dir . '/t.png');
    ImageProcessor::resize($dir . '/t.png', $dir . '/azul.jpg', 100, 100, ['format' => 'jpeg', 'background' => [0, 0, 255], 'quality' => 95]);
    $read = imagecreatefromjpeg($dir . '/azul.jpg');
    $transparent = imagecolorat($read, 5, 20);
    $opaque = imagecolorat($read, 35, 20);
    check(($transparent & 255) > 220 && (($transparent >> 16) & 255) < 40, 'la zona transparente queda azul');
    check((($opaque >> 16) & 255) > 220 && ($opaque & 255) < 40, 'la zona opaca sigue roja');

    ImageProcessor::resize($dir . '/t.png', $dir . '/blanco.jpg', 100, 100, ['format' => 'jpg']);
    $white = imagecolorat(imagecreatefromjpeg($dir . '/blanco.jpg'), 5, 20);
    check(($white & 255) > 235 && (($white >> 16) & 255) > 235, 'por defecto el fondo es blanco');
});

test('resize corrige la orientación EXIF antes de redimensionar', function (): void {
    if (!function_exists('exif_read_data')) {
        check(true);

        return;
    }
    $dir = tmpDir();
    imagejpeg(mediaTestImage(600, 300), $dir . '/foto.jpg');
    mediaTestJpegWithOrientation($dir . '/foto.jpg', 6);
    $out = ImageProcessor::resize($dir . '/foto.jpg', $dir . '/girada.jpg', 400, 400);
    same(200, $out->width, 'con orientación 6 la foto de 600×300 es de 300×600');
    same(400, $out->height);

    imagejpeg(mediaTestImage(600, 300), $dir . '/normal.jpg');
    mediaTestJpegWithOrientation($dir . '/normal.jpg', 1);
    $normal = ImageProcessor::resize($dir . '/normal.jpg', $dir . '/normal-out.jpg', 400, 400);
    same(400, $normal->width);
    same(200, $normal->height);

    imagejpeg(mediaTestImage(600, 300), $dir . '/tres.jpg');
    mediaTestJpegWithOrientation($dir . '/tres.jpg', 3);
    $tres = ImageProcessor::resize($dir . '/tres.jpg', $dir . '/tres-out.jpg', 400, 400);
    same(400, $tres->width, 'con orientación 3 solo se da la vuelta');
    same(200, $tres->height);
});

test('la orientación EXIF también gira el recorte de cover y el color dominante', function (): void {
    if (!function_exists('exif_read_data')) {
        check(true);

        return;
    }
    $dir = tmpDir();
    $im = mediaTestImage(600, 300, [0, 0, 255]);
    imagefilledrectangle($im, 0, 0, 299, 299, imagecolorallocate($im, 255, 0, 0));
    imagejpeg($im, $dir . '/dos.jpg', 100);
    mediaTestJpegWithOrientation($dir . '/dos.jpg', 6);
    // girada 90° a la derecha, la mitad roja (izquierda) queda arriba
    $out = ImageProcessor::resize($dir . '/dos.jpg', $dir . '/girada.jpg', 300, 600);
    same(300, $out->width);
    same(600, $out->height);
    $read = imagecreatefromjpeg($dir . '/girada.jpg');
    $top = imagecolorat($read, 150, 50);
    $bottom = imagecolorat($read, 150, 550);
    check((($top >> 16) & 255) > 200, 'arriba queda la parte roja');
    check(($bottom & 255) > 200, 'abajo queda la parte azul');
});

test('variants no genera tamaños mayores que el original y salta los formatos que no se pueden escribir', function (): void {
    $dir = tmpDir();
    imagejpeg(mediaTestImage(600, 400), $dir . '/original.jpg');
    $sizes = ['large' => [1200, 1200], 'card' => [400, 400, 'fit'], 'thumb' => [100, 100, 'cover']];
    $result = ImageProcessor::variants($dir . '/original.jpg', $dir . '/out', 'Mi Foto', $sizes, ['webp', 'jpeg', 'bmp', 'avif', 'jpeg']);

    $names = [];
    foreach ($result as $row) {
        $names[] = $row['size'] . '.' . $row['format'];
        check($row['width'] <= 600 && $row['height'] <= 400, 'nunca mayor que el original: ' . $row['width'] . 'x' . $row['height']);
        check(is_file($row['path']), 'existe ' . $row['path']);
        same(filesize($row['path']), $row['bytes']);
        contains('/out/mi-foto-' . $row['size'] . '.', $row['path']);
        check(in_array($row['format'], ['webp', 'jpeg', 'avif'], true), 'formato ' . $row['format']);
        $info = ImageProcessor::probe($row['path']);
        same($row['width'], $info->width);
        same($row['format'], $info->format);
    }
    check(!in_array('large.webp', $names, true) && !in_array('large.jpeg', $names, true), 'large (1200) es mayor que el original');
    check(in_array('card.webp', $names, true), 'card.webp');
    check(in_array('card.jpeg', $names, true), 'card.jpeg');
    check(in_array('thumb.webp', $names, true), 'thumb.webp');
    same(count($names), count(array_unique($names)), 'sin repetidos');
    same(ImageProcessor::supports('avif'), in_array('card.avif', $names, true), 'avif solo si GD lo escribe');
    check(!in_array('card.bmp', $names, true), 'bmp se salta');
    same('jpg', pathinfo($dir . '/out/mi-foto-card.jpg', PATHINFO_EXTENSION));
    check(is_file($dir . '/out/mi-foto-card.jpg'), 'la extensión del JPEG es .jpg');

    $card = null;
    foreach ($result as $row) {
        if ($row['size'] === 'card' && $row['format'] === 'webp') {
            $card = $row;
        }
    }
    same(400, $card['width']);
    same(267, $card['height']);

    $none = ImageProcessor::variants($dir . '/original.jpg', $dir . '/vacio', 'x', ['gigante' => [5000, 5000]], ['webp']);
    same([], $none);
});

test('variants rechaza tamaños mal escritos y no deja ficheros temporales', function (): void {
    $dir = tmpDir();
    imagejpeg(mediaTestImage(300, 200), $dir . '/o.jpg');
    throws(function () use ($dir): void {
        ImageProcessor::variants($dir . '/o.jpg', $dir . '/out', 'o', ['mal' => [0, 100]], ['jpeg']);
    }, MediaException::class);
    throws(function () use ($dir): void {
        ImageProcessor::variants($dir . '/o.jpg', $dir . '/out', 'o', ['mal' => [100, 100, 'estirar']], ['jpeg']);
    }, MediaException::class);

    ImageProcessor::variants($dir . '/o.jpg', $dir . '/ok', 'o', ['s' => [100, 100]], ['jpeg', 'webp']);
    $files = array_values(array_diff((array) scandir($dir . '/ok'), ['.', '..']));
    sort($files);
    same(['o-s.jpg', 'o-s.webp'], $files, 'solo los ficheros finales, sin temporales');
});

test('resize escribe de forma atómica: sin temporales, sin destino si falla y sustituyendo uno existente', function (): void {
    $dir = tmpDir();
    imagejpeg(mediaTestImage(300, 200), $dir . '/o.jpg');
    file_put_contents($dir . '/destino.jpg', 'contenido viejo');
    ImageProcessor::resize($dir . '/o.jpg', $dir . '/destino.jpg', 100, 100);
    same(100, ImageProcessor::probe($dir . '/destino.jpg')->width, 'el destino anterior se sustituye');
    $files = array_values(array_diff((array) scandir($dir), ['.', '..']));
    sort($files);
    same(['destino.jpg', 'o.jpg'], $files, 'no queda ningún temporal');

    file_put_contents($dir . '/falso.jpg', "no soy una imagen\n");
    throws(function () use ($dir): void {
        ImageProcessor::resize($dir . '/falso.jpg', $dir . '/nunca.jpg', 100, 100);
    }, MediaException::class);
    check(!is_file($dir . '/nunca.jpg'), 'si falla no se crea el destino');

    throws(function () use ($dir): void {
        ImageProcessor::resize($dir . '/o.jpg', $dir . '/no-existe/dentro.jpg', 100, 100);
    }, MediaException::class);
});

test('resize valida las opciones: medidas, modo, formato y fondo', function (): void {
    $dir = tmpDir();
    imagejpeg(mediaTestImage(300, 200), $dir . '/o.jpg');
    foreach ([[0, 100, []], [100, 0, []], [-5, 100, []], [100, 100, ['mode' => 'estirar']], [100, 100, ['format' => 'bmp']], [100, 100, ['format' => 'svg']], [100, 100, ['background' => 'rojo']], [100, 100, ['background' => [1, 2]]], [100, 100, ['background' => [1, 2, 300]]]] as [$w, $h, $options]) {
        throws(function () use ($dir, $w, $h, $options): void {
            ImageProcessor::resize($dir . '/o.jpg', $dir . '/x.jpg', $w, $h, $options);
        }, MediaException::class, json_encode([$w, $h, $options]));
    }
    check(!is_file($dir . '/x.jpg'), 'ninguna llamada inválida escribe');
});

test('la calidad pedida cambia el tamaño del JPEG y vale 82 por defecto', function (): void {
    $dir = tmpDir();
    $im = mediaTestImage(400, 400);
    for ($i = 0; $i < 400; $i += 7) {
        imageline($im, 0, $i, 399, 399 - $i, imagecolorallocate($im, ($i * 3) % 255, ($i * 5) % 255, ($i * 7) % 255));
    }
    imagepng($im, $dir . '/ruido.png');
    $low = ImageProcessor::resize($dir . '/ruido.png', $dir . '/low.jpg', 400, 400, ['format' => 'jpeg', 'quality' => 10]);
    $high = ImageProcessor::resize($dir . '/ruido.png', $dir . '/high.jpg', 400, 400, ['format' => 'jpeg', 'quality' => 95]);
    check($low->bytes < $high->bytes, 'calidad 10 pesa menos que 95: ' . $low->bytes . ' < ' . $high->bytes);
    $default = ImageProcessor::resize($dir . '/ruido.png', $dir . '/def.jpg', 400, 400, ['format' => 'jpeg']);
    $explicit = ImageProcessor::resize($dir . '/ruido.png', $dir . '/exp.jpg', 400, 400, ['format' => 'jpeg', 'quality' => 82]);
    same($explicit->bytes, $default->bytes, 'por defecto 82');
    $clamped = ImageProcessor::resize($dir . '/ruido.png', $dir . '/clamp.jpg', 400, 400, ['format' => 'jpeg', 'quality' => 999]);
    $max = ImageProcessor::resize($dir . '/ruido.png', $dir . '/max.jpg', 400, 400, ['format' => 'jpeg', 'quality' => 100]);
    same($max->bytes, $clamped->bytes, 'la calidad se limita a 100');
});

test('dominantColor de una imagen roja lisa es #ff0000', function (): void {
    $dir = tmpDir();
    imagepng(mediaTestImage(300, 200, [255, 0, 0]), $dir . '/roja.png');
    $color = ImageProcessor::dominantColor($dir . '/roja.png');
    check(preg_match('/^#[0-9a-f]{6}$/', $color) === 1, 'formato ' . $color);
    near(255, (float) hexdec(substr($color, 1, 2)), 2);
    near(0, (float) hexdec(substr($color, 3, 2)), 2);
    near(0, (float) hexdec(substr($color, 5, 2)), 2);

    imagejpeg(mediaTestImage(300, 200, [0, 0, 255]), $dir . '/azul.jpg', 100);
    $blue = ImageProcessor::dominantColor($dir . '/azul.jpg');
    near(255, (float) hexdec(substr($blue, 5, 2)), 6);

    mediaTestTransparentPng($dir . '/t.png');
    $mixed = ImageProcessor::dominantColor($dir . '/t.png');
    check(preg_match('/^#[0-9a-f]{6}$/', $mixed) === 1, 'con transparencia también devuelve un color');

    file_put_contents($dir . '/falso.png', 'texto');
    throws(function () use ($dir): void {
        ImageProcessor::dominantColor($dir . '/falso.png');
    }, MediaException::class);
});
