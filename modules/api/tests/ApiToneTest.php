<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/*
 * Auditoría externa del 24/11/2026: la guía de /buy-with-links decía a los asistentes «así que no se lo pidas a la persona» y «No digas a
 * la persona que no puedes comprar aquí». Un asistente prudente lo lee como una orden de ocultar cosas y choca con la regla de que la
 * persona revisa el total y confirma. El tono de lo que la tienda publica para máquinas es informativo: dice qué basta con hacer, no qué
 * callar. Esta prueba falla si reaparece una frase de ese tipo.
 */

/** @return string[] expresiones que no pueden aparecer en lo que la tienda publica para los asistentes */
function toneForbidden(): array
{
    return [
        '/\b(?:do not|don\'t|never) (?:tell|say to|reveal to|mention to) (?:the|them|your|a) ?(?:person|user|buyer|customer|human)\b/i',
        '/\bdo not ask the (?:person|user|buyer|customer)\b/i',
        '/\bdo not say (?:that )?you (?:can ?not|can\'t|cannot)\b/i',
        '/\bwithout (?:telling|asking) the (?:person|user|buyer|customer)\b/i',
        '/\bno (?:se lo|le) pidas\b/iu',
        '/\bno (?:le )?(?:digas|cuentes|menciones) (?:a la persona|al usuario|al comprador|al cliente)\b/iu',
        '/\bsin (?:decir|avisar|preguntar)(?:le)? (?:a la persona|al usuario|al comprador|al cliente)\b/iu',
    ];
}

test('lo que la tienda publica para los asistentes (guía, reglas, llms.txt, llms-full.txt, API, .md de producto) no manda ocultar nada a la persona', function (): void {
    $app = apiApp([], ['semantic']);
    $ids = assistantShop($app);
    $client = new TestClient($app);
    $texts = [];
    foreach (['/buy-with-links', '/buy-with-links/rules', '/buy-with-links/start', '/llms.txt', '/llms-full.txt', '/index.md', '/api', '/api/openapi.json'] as $path) {
        $texts[$path] = $client->get($path, ['user-agent' => 'ChatGPT-User/1.0'])->body;
    }
    $texts['product.md'] = $client->get('/p/' . $ids['silla_roja'] . '.md', ['user-agent' => 'ChatGPT-User/1.0'])->body;
    $texts['buy-with-links/new'] = $client->get('/buy-with-links/new/' . $ids['silla_roja'], ['user-agent' => 'ChatGPT-User/1.0'])->body;
    $texts['search'] = apiCall($app, 'GET', '/api/v1/search?q=silla')->body;
    $checked = 0;
    foreach ($texts as $where => $body) {
        $text = (string) preg_replace('/\\\\u([0-9a-f]{4})/i', '', $body);
        foreach (toneForbidden() as $pattern) {
            same(0, preg_match($pattern, $text), $where . ' no debe casar ' . $pattern);
        }
        $checked += $text !== '' ? 1 : 0;
    }
    check($checked >= 4, 'se han leído al menos cuatro de los textos: ' . $checked);
});

test('el código y los catálogos de idioma que escriben esos textos tampoco llevan esas frases', function (): void {
    $root = dirname(__DIR__, 3);
    $files = [
        $root . '/modules/api/src/BrowseController.php', $root . '/modules/api/lang/links-es.php', $root . '/modules/api/lang/es.php',
        $root . '/modules/semantic/src/Llms.php', $root . '/modules/semantic/src/TextVersion.php',
    ];
    foreach ($files as $file) {
        check(is_file($file), $file . ' existe');
        $code = (string) @file_get_contents($file);
        foreach (toneForbidden() as $pattern) {
            same(0, preg_match($pattern, $code), basename($file) . ' no debe casar ' . $pattern);
        }
    }
    $guide = (string) file_get_contents($root . '/modules/api/src/BrowseController.php');
    contains('The person reviews the total and confirms the order themselves.', $guide, 'y dice que la persona revisa el total y confirma');
});
