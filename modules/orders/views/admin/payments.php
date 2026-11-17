<?php
/**
 * AIFEC — «Ajustes › Formas de pago»: las formas registradas en el orden de la caja, con su estado, y los módulos de pago
 * apagados aparte. Sin JavaScript: las flechas son botones de un formulario.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array<string,mixed>> $rows
 * @var array<int,array{id:string,name:string,can_enable:bool}> $off
 * @var int $offered formas que se ofrecerían hoy a un pedido normal
 * @var string $normal el pedido normal, en una frase
 */

declare(strict_types=1);

use Aifec\Admin\Admin;

$app = app();
$states = [
    'offered' => [t('Offered'), 'ok'],
    'unconfigured' => [t('Not set up: the keys are missing'), 'warn'],
    'not_offered' => [t('On, but a normal order would not see it'), 'info'],
];
$dash = '—';
$countries = static function ($list) use ($dash): string {
    if ($list === null) {
        return $dash;
    }

    return $list === [] ? t('Anyone') : implode(', ', $list);
};
$last = count($rows) - 1;
?>
<?php if ($offered === 0) : ?>
    <div class="af-alert af-alert-warn" role="status"><?= e(t('No payment method would be offered today to a normal order: customers could not pay.')) ?></div>
<?php endif; ?>
<p class="af-muted af-small"><?= e($normal) ?></p>
<?php if ($rows === []) : ?>
    <div class="af-card"><div class="af-empty"><h3><?= e(t('No payment method is registered')) ?></h3></div></div>
<?php else : ?>
    <form method="post" action="<?= e($app->url()->plain('/admin/payments/order')) ?>" class="af-card" data-af-payments>
        <?= Admin::tokenField($app) ?>
        <div class="af-table-wrap">
            <table class="af-table">
                <caption class="af-sr"><?= e(t('Payment methods in the order the customer sees them')) ?></caption>
                <thead>
                    <tr>
                        <th scope="col"><?= e(t('Order in the checkout')) ?></th>
                        <th scope="col"><?= e(t('Payment method')) ?></th>
                        <th scope="col" class="af-hide-sm"><?= e(t('Module')) ?></th>
                        <th scope="col"><?= e(t('Status')) ?></th>
                        <th scope="col" class="af-hide-sm"><?= e(t('Environment')) ?></th>
                        <th scope="col" class="af-hide-sm"><?= e(t('Offered to')) ?></th>
                        <th scope="col"><span class="af-sr"><?= e(t('Actions')) ?></span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $i => $row) : ?>
                        <?php $state = $states[$row['state']]; ?>
                        <tr data-af-payment="<?= e($row['key']) ?>">
                            <td class="af-nowrap">
                                <button type="submit" class="af-btn af-btn-sm af-btn-ghost" name="move" value="up:<?= e($row['key']) ?>" aria-label="<?= e(t('Move «{name}» up', ['name' => $row['name']])) ?>"<?= $i === 0 ? ' disabled' : '' ?>>&uarr;</button>
                                <button type="submit" class="af-btn af-btn-sm af-btn-ghost" name="move" value="down:<?= e($row['key']) ?>" aria-label="<?= e(t('Move «{name}» down', ['name' => $row['name']])) ?>"<?= $i === $last ? ' disabled' : '' ?>>&darr;</button>
                            </td>
                            <td>
                                <strong><?= e($row['name']) ?></strong>
                                <?php if ($row['group']) : ?>
                                    <br><span class="af-small af-muted"><?= e(t('{n} of {total} are offered', ['n' => $row['offered'], 'total' => $row['total']])) ?></span>
                                    <details class="af-help"><summary class="af-small"><?= e(t('See them')) ?></summary>
                                        <ul class="af-small">
                                            <?php foreach ($row['forms'] as $form) : ?>
                                                <li><?= e($form['title']) ?> · <?= e($states[$form['state']][0]) ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </details>
                                <?php endif; ?>
                            </td>
                            <td class="af-hide-sm"><?= e($row['module_name'] !== '' ? $row['module_name'] : $dash) ?></td>
                            <td><?= Admin::badge($state[0], $state[1]) ?></td>
                            <td class="af-hide-sm"><?= e($row['environment'] === 'test' ? t('In test mode') : ($row['environment'] === 'live' ? t('Live mode') : $dash)) ?></td>
                            <td class="af-hide-sm"><?= e($countries($row['countries'])) ?></td>
                            <td class="af-cell-actions">
                                <?php if ($row['settings_url'] !== '') : ?>
                                    <a class="af-btn af-btn-sm" href="<?= e($row['settings_url']) ?>"><?= Admin::icon('settings') ?><?= e(t('Configure')) ?></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </form>
<?php endif; ?>
<?php if ($off !== []) : ?>
    <div class="af-card">
        <div class="af-card-head"><h2><?= e(t('Payment modules that are off')) ?></h2></div>
        <div class="af-table-wrap">
            <table class="af-table">
                <tbody>
                    <?php foreach ($off as $module) : ?>
                        <tr class="af-muted" data-af-payment-off="<?= e($module['id']) ?>">
                            <td><?= e($module['name']) ?></td>
                            <td class="af-hide-sm af-mono"><?= e($module['id']) ?></td>
                            <td><?= Admin::badge(t('Turned off')) ?></td>
                            <td class="af-cell-actions">
                                <?php if ($module['can_enable']) : ?>
                                    <form method="post" action="<?= e($app->url()->plain('/admin/payments/enable/' . $module['id'])) ?>">
                                        <?= Admin::tokenField($app) ?>
                                        <button type="submit" class="af-btn af-btn-sm"><?= Admin::icon('plug') ?><?= e(t('Turn on')) ?></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
