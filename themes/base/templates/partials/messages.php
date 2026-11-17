<?php
/**
 * AIFEC — avisos de la página: [['ok'|'bad'|'info'|'warn', texto], …].
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array{0:string,1:string}> $messages
 */

foreach (isset($messages) && is_array($messages) ? $messages : [] as $af_msg) {
    if (!is_array($af_msg) || !isset($af_msg[1]) || trim((string) $af_msg[1]) === '') {
        continue;
    }
    $af_type = isset($af_msg[0]) && in_array($af_msg[0], ['ok', 'bad', 'info', 'warn'], true) ? (string) $af_msg[0] : 'info';
    ?>
<div class="af-alert af-alert-<?= e($af_type) ?>" role="<?= $af_type === 'bad' || $af_type === 'warn' ? 'alert' : 'status' ?>"><?= e($af_msg[1]) ?></div>
    <?php
}
