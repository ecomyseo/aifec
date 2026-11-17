<?php
/**
 * AIFEC — esquema del consentimiento: la prueba de cada decisión y el inventario de cookies.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Db\Db;
use Aifec\Db\Schema;
use Aifec\Db\TableDef;

return static function (Schema $schema, Db $db): void {
    // La prueba de una decisión. Sin datos personales: cookie_id es un número al azar que está en la cookie de la decisión
    // (no dice quién es), categories son las letras concedidas (p preferencias, s estadísticas, m marketing; vacío = rechazó todo),
    // action: all, none o custom, gpc = el navegador mandaba la señal Global Privacy Control. Se guarda 5 años.
    $schema->create('consent_log', static function (TableDef $t): void {
        $t->id();
        $t->string('cookie_id', 32);
        $t->integer('policy_version')->default(1);
        $t->string('categories', 8)->default('');
        $t->string('action', 8)->default('custom');
        $t->boolean('gpc')->default(0);
        $t->string('lang', 8)->default('');
        $t->datetime('created_at');
        $t->index(['created_at']);
        $t->index(['cookie_id', 'id']);
    });

    // El inventario de cookies. category: necessary, preferences, statistics, marketing. Un nombre acabado en «*» es el de las
    // cookies del propio sitio, que llevan al final un sufijo propio de cada sitio (se sustituye al enseñarlo). builtin = viene de serie.
    $schema->create('consent_cookies', static function (TableDef $t): void {
        $t->id();
        $t->string('name', 120);
        $t->string('provider', 120);
        $t->string('purpose', 255);
        $t->string('duration', 60);
        $t->string('category', 16)->default('necessary');
        $t->boolean('builtin')->default(0);
        $t->integer('position')->default(0);
        $t->index(['category', 'position']);
    });

    // Las cookies que pone AIFEC por sí mismo (textos en inglés: se traducen al enseñarlos).
    $builtin = [
        ['af_consent_*', 'This website', 'Remembers your cookie choices', '6 months', 'necessary'],
        ['af_c_*', 'This website', 'Keeps your session open (notices, sign-in)', '14 days', 'necessary'],
        ['af_u_*', 'This website', 'Remembers that you have signed in', '30 days', 'necessary'],
        ['af_k_*', 'This website', 'Keeps your shopping cart', '30 days', 'necessary'],
        ['af_ck_*', 'This website', 'Tells the page that you have a cart', '30 days', 'necessary'],
        ['af_nc_*', 'This website', 'Shows you a message that is waiting for you', '10 minutes', 'necessary'],
        ['af_as_*', 'This website', 'Keeps your conversation with the shop assistant', '1 day', 'necessary'],
        ['af_a_*', 'This website', 'Keeps the staff session open (only for people who run the shop)', '14 days', 'necessary'],
    ];
    foreach ($builtin as $position => $cookie) {
        $db->insert('consent_cookies', [
            'name' => $cookie[0], 'provider' => $cookie[1], 'purpose' => $cookie[2], 'duration' => $cookie[3],
            'category' => $cookie[4], 'builtin' => 1, 'position' => $position,
        ]);
    }
};
