<?php
/**
 * AIFEC — pruebas de las propuestas: crear, sustituir la pendiente, aprobar (solo ese campo en ese idioma, con marca y caché
 * invalidada), rechazar, editar antes de aprobar, aprobar sin nadie que aplique, listas de datos y de preguntas, imágenes y la
 * comparación por palabras.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Ai\Diff;
use Aifec\Modules\Ai\Proposals;

require_once __DIR__ . '/helpers.php';

/**
 * Producto con dos idiomas para las pruebas de propuestas.
 *
 * @return int
 */
function aiTwoLanguageProduct(\Aifec\Kernel\App $app): int
{
    return aiProduct($app, [
        'name' => 'Silla de roble', 'summary' => '<p>Resumen viejo</p>', 'description' => '<p>Descripción vieja</p>',
        'meta_title' => 'Meta vieja', 'meta_description' => 'Descripción meta vieja',
        'i18n' => ['en' => ['name' => 'Oak chair', 'description' => '<p>Old description</p>', 'summary' => '<p>Old summary</p>']],
    ]);
}

test('propose() crea una pendiente con el texto actual leído de la tienda, el modelo y la tarea', function (): void {
    $app = aiApp([], ['es', 'en']);
    $id = aiTwoLanguageProduct($app);
    $proposal = $app->service('ai')->propose('product', $id, 'description', 'en', '<p>New description</p>', ['model' => 'fake-model', 'task' => 'product.description']);
    $row = aiRows($app, 'ai_proposals')[0];
    same($proposal, (int) $row['id']);
    same('pending', $row['status']);
    same('product', $row['entity']);
    same($id, (int) $row['entity_id']);
    same('description', $row['field']);
    same('en', $row['lang']);
    same('<p>Old description</p>', $row['current_text'], 'el texto de ESE idioma, no el del principal');
    same('<p>New description</p>', $row['proposed_text']);
    same('fake-model', $row['model']);
    same('product.description', $row['task']);
    same(null, $row['resolved_at']);
});

test('una sola pendiente por entidad + campo + idioma: la nueva sustituye a la anterior y no toca otras', function (): void {
    $app = aiApp([], ['es', 'en']);
    $id = aiTwoLanguageProduct($app);
    $ai = $app->service('ai');
    $first = $ai->propose('product', $id, 'description', 'es', '<p>Primera</p>');
    $other = $ai->propose('product', $id, 'description', 'en', '<p>First</p>');
    $name = $ai->propose('product', $id, 'meta_title', 'es', 'Otro campo');
    $second = $ai->propose('product', $id, 'description', 'es', '<p>Segunda</p>');

    $pending = aiRows($app, 'ai_proposals', "status = 'pending'");
    same(3, count($pending));
    same([$other, $name, $second], array_map('intval', array_column($pending, 'id')));
    same('<p>Segunda</p>', aiRows($app, 'ai_proposals', 'id = ?', [$second])[0]['proposed_text']);
    same([], aiRows($app, 'ai_proposals', 'id = ?', [$first]), 'la primera ya no está');
});

test('aprobar aplica SOLO ese campo en ese idioma, deja la marca, avisa con «ai.approved» e invalida la caché del producto', function (): void {
    $app = aiApp([], ['es', 'en']);
    $id = aiTwoLanguageProduct($app);
    $catalog = $app->service('catalog');
    same('<p>Old description</p>', $catalog->product($id, 'en')['description'], 'calienta la caché');
    $before = $app->db()->row('SELECT * FROM {product_i18n} WHERE product_id = ? AND lang = ?', [$id, 'es']);
    $approved = [];
    $app->hooks()->on('ai.approved', static function (array $proposal) use (&$approved): void {
        $approved[] = $proposal;
    });

    $proposal = $app->service('ai')->propose('product', $id, 'description', 'en', '<p>New <b>description</b></p>', ['model' => 'fake-model']);
    same(Proposals::OK, $app->service('ai')->proposals()->approve($proposal));

    $en = $app->db()->row('SELECT * FROM {product_i18n} WHERE product_id = ? AND lang = ?', [$id, 'en']);
    same('<p>New <b>description</b></p>', $en['description']);
    same('Oak chair', $en['name'], 'el nombre no se toca');
    same('<p>Old summary</p>', $en['summary'], 'el resumen tampoco');
    same($before, $app->db()->row('SELECT * FROM {product_i18n} WHERE product_id = ? AND lang = ?', [$id, 'es']), 'el otro idioma queda igual, byte a byte');
    same('<p>New <b>description</b></p>', $catalog->product($id, 'en')['description'], 'la caché se invalidó');

    $marks = aiRows($app, 'ai_marks');
    same(1, count($marks));
    same('product', $marks[0]['entity']);
    same($id, (int) $marks[0]['entity_id']);
    same('description', $marks[0]['field']);
    same('en', $marks[0]['lang']);
    same('fake-model', $marks[0]['model']);
    $row = aiRows($app, 'ai_proposals')[0];
    same('approved', $row['status']);
    check($row['resolved_at'] !== null);
    same(1, count($approved));
    same('approved', $approved[0]['status']);
    same(Proposals::NOT_PENDING, $app->service('ai')->proposals()->approve($proposal), 'aprobar dos veces no escribe dos veces');
    same(1, count(aiRows($app, 'ai_marks')));
});

test('el HTML que se aprueba se sanea al escribir: un script en la propuesta no llega a la tienda', function (): void {
    $app = aiApp();
    $id = aiProduct($app, ['name' => 'Mesa', 'description' => '<p>Vieja</p>']);
    $proposal = $app->service('ai')->propose('product', $id, 'description', 'es', '<p>Buena</p><script>alert(1)</script>');
    $app->service('ai')->proposals()->approve($proposal);
    $description = (string) $app->db()->value('SELECT description FROM {product_i18n} WHERE product_id = ?', [$id]);
    contains('<p>Buena</p>', $description);
    notContains('<script', $description);
});

test('rechazar no toca nada de la tienda ni deja marca', function (): void {
    $app = aiApp();
    $id = aiProduct($app, ['name' => 'Mesa', 'description' => '<p>Vieja</p>']);
    $before = $app->db()->row('SELECT * FROM {product_i18n} WHERE product_id = ?', [$id]);
    $proposal = $app->service('ai')->propose('product', $id, 'description', 'es', '<p>Nueva</p>');
    check($app->service('ai')->proposals()->reject($proposal));
    same($before, $app->db()->row('SELECT * FROM {product_i18n} WHERE product_id = ?', [$id]));
    same('rejected', aiRows($app, 'ai_proposals')[0]['status']);
    same([], aiRows($app, 'ai_marks'));
    check(!$app->service('ai')->proposals()->reject($proposal), 'rechazar otra vez no hace nada');
    same(Proposals::NOT_PENDING, $app->service('ai')->proposals()->approve($proposal), 'una rechazada no se aprueba');
});

test('editar antes de aprobar guarda lo editado, y se aprueba lo editado', function (): void {
    $app = aiApp();
    $id = aiProduct($app, ['name' => 'Mesa', 'meta_title' => 'Vieja']);
    $ai = $app->service('ai');
    $proposal = $ai->propose('product', $id, 'meta_title', 'es', 'Propuesta de la IA');
    $ai->proposals()->edit($proposal, 'Mesa de roble · Tienda & Co');
    same('Mesa de roble · Tienda & Co', aiRows($app, 'ai_proposals')[0]['proposed_text']);
    same(Proposals::OK, $ai->proposals()->approve($proposal, 'Mesa de roble macizo & Co'));
    same('Mesa de roble macizo & Co', (string) $app->db()->value('SELECT meta_title FROM {product_i18n} WHERE product_id = ?', [$id]), 'sin &amp;');
    same('Mesa de roble macizo & Co', aiRows($app, 'ai_proposals')[0]['proposed_text']);
    throws(function () use ($ai, $proposal): void {
        $ai->proposals()->edit($proposal, 'ya resuelta');
    }, \InvalidArgumentException::class);
});

test('una propuesta vacía o con la forma equivocada se rechaza al crearla', function (): void {
    $app = aiApp();
    $id = aiProduct($app, ['name' => 'Mesa']);
    $ai = $app->service('ai');
    throws(function () use ($ai, $id): void {
        $ai->propose('product', $id, 'description', 'es', "   \n ");
    }, \InvalidArgumentException::class);
    throws(function () use ($ai, $id): void {
        $ai->propose('product', $id, 'facts', 'es', 'esto no es una lista');
    }, \InvalidArgumentException::class);
    throws(function () use ($ai): void {
        $ai->propose('product', 0, 'description', 'es', 'x');
    }, \InvalidArgumentException::class);
    same([], aiRows($app, 'ai_proposals'));
});

test('aprobar sin nadie que aplique deja la propuesta pendiente (y sin marca)', function (): void {
    $app = aiApp();
    $ai = $app->service('ai');
    $proposal = $ai->propose('page', 77, 'html', 'es', '<p>Una página de un módulo que no está</p>');
    same(Proposals::NOBODY_APPLIES, $ai->proposals()->approve($proposal));
    $row = aiRows($app, 'ai_proposals')[0];
    same('pending', $row['status']);
    same(null, $row['resolved_at']);
    same(null, $row['resolved_by']);
    same([], aiRows($app, 'ai_marks'));
    same(Proposals::MISSING, $ai->proposals()->approve(99999));
});

test('otro módulo puede aplicar sus propias propuestas con el filtro «ai.apply»; el nuestro no se las come', function (): void {
    $app = aiApp();
    $applied = [];
    $app->hooks()->filter('ai.apply', static function ($done, array $proposal) use (&$applied): bool {
        if ($proposal['entity'] === 'page') {
            $applied[] = $proposal['proposed_text'];

            return true;
        }

        return $done === true;
    });
    $ai = $app->service('ai');
    $proposal = $ai->propose('page', 5, 'html', 'es', '<p>Texto para el cms</p>');
    same(Proposals::OK, $ai->proposals()->approve($proposal));
    same(['<p>Texto para el cms</p>'], $applied);
    same('approved', aiRows($app, 'ai_proposals')[0]['status']);
    same('page', aiRows($app, 'ai_marks')[0]['entity']);
});

test('los datos clave y las preguntas frecuentes se aplican a su idioma sin tocar los de otro', function (): void {
    $app = aiApp([], ['es', 'en']);
    $id = aiProduct($app, [
        'name' => 'Silla', 'facts' => [['label' => 'Material', 'value' => 'Roble']],
        'faqs' => [['question' => '¿Pesa mucho?', 'answer' => 'No.']],
    ]);
    $ai = $app->service('ai');
    $facts = (string) json_encode([['label' => 'Material', 'value' => 'Solid oak'], ['label' => 'Weight', 'value' => '4 kg']], JSON_UNESCAPED_UNICODE);
    $faqs = (string) json_encode([['question' => 'Is it heavy?', 'answer' => 'No, 4 kg.'], ['question' => 'Is it solid?', 'answer' => 'Yes.']], JSON_UNESCAPED_UNICODE);
    $ai->proposals()->approve($ai->propose('product', $id, 'facts', 'en', $facts));
    $ai->proposals()->approve($ai->propose('product', $id, 'faqs', 'en', $faqs));

    $en = $app->service('catalog')->product($id, 'en');
    same([['label' => 'Material', 'value' => 'Solid oak'], ['label' => 'Weight', 'value' => '4 kg']], $en['facts']);
    same([['question' => 'Is it heavy?', 'answer' => 'No, 4 kg.'], ['question' => 'Is it solid?', 'answer' => 'Yes.']], $en['faqs']);
    $es = $app->service('catalog')->product($id, 'es');
    same([['label' => 'Material', 'value' => 'Roble']], $es['facts'], 'el castellano queda igual');
    same([['question' => '¿Pesa mucho?', 'answer' => 'No.']], $es['faqs']);
    same(2, (int) $app->db()->value('SELECT COUNT(*) FROM {product_faqs} WHERE product_id = ? AND lang = ?', [$id, 'en']));
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {product_faqs} WHERE product_id = ? AND lang = ?', [$id, 'es']));
});

test('el texto alternativo de una imagen se aplica a la biblioteca de medios', function (): void {
    $app = aiApp();
    $media = aifecMedia($app, 'viejo');
    $product = aiProduct($app, ['name' => 'Silla', 'images' => [$media]]);
    $ai = $app->service('ai');
    same('viejo', $app->service('catalog')->product($product)['images'][0]['alt'], 'calienta la caché');
    $proposal = $ai->propose('media', $media, 'alt', null, 'Silla de roble, vista frontal');
    same('viejo', aiRows($app, 'ai_proposals')[0]['current_text']);
    same(Proposals::OK, $ai->proposals()->approve($proposal));
    same('Silla de roble, vista frontal', (string) $app->db()->value('SELECT alt FROM {media} WHERE id = ?', [$media]));
    same('Silla de roble, vista frontal', $app->service('catalog')->product($product)['images'][0]['alt'], 'la ficha del producto con esa imagen se invalidó');
});

test('aplicar a un producto que ya no existe no inventa nada y la propuesta se queda pendiente', function (): void {
    $app = aiApp();
    $ai = $app->service('ai');
    $proposal = $ai->propose('product', 9999, 'description', 'es', '<p>Para nadie</p>');
    same(Proposals::NOBODY_APPLIES, $ai->proposals()->approve($proposal));
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {product_i18n}'));
});

test('la comparación por palabras marca lo quitado a la izquierda y lo añadido a la derecha, y escapa todo', function (): void {
    $d = Diff::render('La silla es de roble macizo', 'La silla es de pino & roble macizo');
    contains('<ins>pino &amp; </ins>', $d['new']);
    notContains('<del>', $d['old']);
    same(false, $d['same']);

    $d = Diff::render('uno <b>dos</b> tres', 'uno <i>dos</i> tres');
    contains('<del>&lt;b&gt;dos&lt;/b&gt;</del>', $d['old']);
    contains('<ins>&lt;i&gt;dos&lt;/i&gt;</ins>', $d['new']);
    notContains('<b>', $d['old']);

    $d = Diff::render('igual', 'igual');
    same(true, $d['same']);
    notContains('<ins>', $d['new']);

    $d = Diff::render('', 'Todo nuevo');
    contains('<ins>Todo nuevo</ins>', $d['new']);
    same('', $d['old']);
});
