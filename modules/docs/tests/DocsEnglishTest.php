<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

/*
 * La documentación en inglés (17/11/2026, «todo debe ser multiidioma»): vive en «docs-en», junto a «docs», con las mismas
 * carpetas y nombres; se lee cuando se navega en inglés, y las capturas que no tenga salen de la de siempre.
 */

use Aifec\Modules\Docs\Docs;

require_once __DIR__ . '/helpers.php';

test('en inglés se lee «docs-en»; en castellano, «docs»; y una captura que la inglesa no tiene sale de la de siempre', function (): void {
    $root = tmpDir('aifec-docs-en');
    aifecDocsWrite($root . '/docs/01-guia/01-empezar.md', "# Empezar\n\nTexto en castellano.\n\n![Panel](../_media/panel.png)\n");
    aifecDocsWrite($root . '/docs-en/01-guia/01-empezar.md', "# Getting started\n\nText in English.\n\n![Panel](../_media/panel.png)\n");
    aifecDocsPng($root . '/docs/_media/panel.png');

    $app = testApp(['modules' => ['docs'], 'settings' => ['docs.dir' => $root . '/docs', 'modules.active' => ['docs'], 'i18n.languages' => ['es', 'en']]]);
    $app->boot();
    $docs = $app->service('docs');
    same($root . '/docs', str_replace('\\', '/', $docs->directory()), 'en castellano, la de siempre');
    same(null, $docs->fallbackLibrary());

    $app->setLang('en');
    $english = new Docs($app);
    same($root . '/docs' . Docs::ENGLISH_SUFFIX, str_replace('\\', '/', $english->directory()), 'en inglés, docs-en');
    $page = $english->library()->find('guia/empezar');
    check($page !== null, 'la página existe en inglés');
    contains('Text in English.', (string) file_get_contents((string) $page['file']));
    check($english->library()->media('panel.png') === null && $english->fallbackLibrary() !== null && $english->fallbackLibrary()->media('panel.png') !== null,
        'la captura no está en docs-en y sale de docs');
});
