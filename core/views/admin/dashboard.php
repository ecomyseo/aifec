<?php
/**
 * AIFEC — inicio del panel: avisos de estado y las piezas que aporta cada módulo.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array{order:int,size:int,html:string}> $widgets
 * @var array<int,array{kind:string,text:string,url:string,label:string,key:string}> $status avisos que se ven (los más graves primero)
 * @var array<int,array{kind:string,text:string,url:string,label:string,key:string}> $statusMore los que no caben: van plegados
 * @var array<string,mixed>|null $user
 */

/** Un aviso: una frase y, si hay a dónde ir, su botón. */
$notice = static function (array $item): string {
    $kind = in_array($item['kind'], ['ok', 'warn', 'bad', 'info'], true) ? $item['kind'] : 'info';
    $button = $item['url'] !== '' ? ' <a class="af-btn af-btn-sm" href="' . e($item['url']) . '">' . e($item['label'] !== '' ? $item['label'] : t('Review')) . '</a>' : '';

    return '<div class="af-alert af-alert-' . e($kind) . '" role="status"><span>' . e($item['text']) . $button . '</span></div>';
};

?>
<?php if ($status === []) : ?>
    <div class="af-alert af-alert-ok" role="status"><span><?= e(t('All in order')) ?></span></div>
<?php else : ?>
    <?php foreach ($status as $item) : ?>
        <?= $notice($item) ?>
    <?php endforeach; ?>
    <?php if ($statusMore !== []) : ?>
        <details class="af-help" data-af-status-more>
            <summary><?= e(tn('{n} more notice', '{n} more notices', count($statusMore))) ?></summary>
            <div>
                <?php foreach ($statusMore as $item) : ?>
                    <?= $notice($item) ?>
                <?php endforeach; ?>
            </div>
        </details>
    <?php endif; ?>
<?php endif; ?>

<?php if ($widgets === []) : ?>
    <div class="af-card"><div class="af-empty">
        <h3><?= e(t('Hello, {name}', ['name' => $user !== null ? (string) $user['name'] : ''])) ?></h3>
        <p><?= e(t('The panel is ready. Turn on the modules you need and their figures will show up here.')) ?></p>
    </div></div>
<?php else : ?>
    <div class="af-grid">
        <?php foreach ($widgets as $widget) : ?>
            <section class="af-col-<?= (int) $widget['size'] ?>"><?= $widget['html'] ?></section>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
