<?php
/**
 * AIFEC — galería de temas: una tarjeta por tema con su vista previa, de quién hereda, la nota del validador y los botones
 * Activar, Personalizar y Validar.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array<string,mixed>> $rows
 * @var bool                           $canEdit
 */

use Aifec\Admin\Admin;

$app = app();
$this->push('admin.head', '<link rel="stylesheet" href="' . e($app->url()->asset('m', 'themes', 'admin.css')) . '">');
?>
<?php if ($rows === []) : ?>
    <div class="af-empty"><h3><?= e(t('No themes installed')) ?></h3></div>
<?php else : ?>
    <?php
    $current = '';
    foreach ($rows as $candidate) {
        if ($candidate['active']) {
            $current = (string) $candidate['name'];
        }
    }
    ?>
    <p class="af-muted" data-af-themes-intro>
        <?= e($current !== '' ? t('Your shop looks like the theme «{theme}» now.', ['theme' => $current]) : t('No theme is active yet.')) ?>
        <?= e(t('A child theme is a copy that takes everything from another theme and only changes what you ask it to, such as colours or fonts. Warnings are suggestions and do not stop you from activating a theme; blocking errors do.')) ?>
    </p>
    <div class="af-grid th-cards">
        <?php foreach ($rows as $row) : ?>
            <?php $v = $row['validation']; ?>
            <div class="af-col-4">
                <div class="af-card th-card" data-theme="<?= e($row['slug']) ?>">
                    <?php if ($row['preview']) : ?>
                        <img class="th-preview" src="<?= e($app->url()->plain('/admin/themes/preview/' . $row['slug'] . '.png')) ?>" alt="" width="1200" height="900" loading="lazy">
                    <?php else : ?>
                        <div class="th-preview th-noimage"><?= e(t('No preview')) ?></div>
                    <?php endif; ?>
                    <div class="af-card-body af-stack">
                        <h3 class="th-name">
                            <?= e($row['name']) ?>
                            <?php if ($row['active']) : ?>
                                <?= Admin::badge(t('Active'), 'ok') ?>
                            <?php endif; ?>
                        </h3>
                        <p class="af-muted af-small">
                            <?= e($row['version'] !== '' ? t('Version {version}', ['version' => $row['version']]) : '') ?>
                            <?php if ($row['parent'] !== '') : ?>
                                · <?= e(t('Child theme of {theme}', ['theme' => $row['parent']])) ?>
                            <?php endif; ?>
                        </p>
                        <p class="th-note">
                            <?php if (!$row['valid']) : ?>
                                <?= Admin::badge(t('Invalid theme.json'), 'bad') ?>
                            <?php elseif ($v['errors'] > 0) : ?>
                                <?= Admin::badge(tn('{n} blocking error', '{n} blocking errors', (int) $v['errors']), 'bad') ?>
                            <?php elseif ($v['warnings'] > 0) : ?>
                                <?= Admin::badge(tn('{n} warning', '{n} warnings', (int) $v['warnings']), 'warn') ?>
                            <?php else : ?>
                                <?= Admin::badge(t('Passes the validator'), 'ok') ?>
                            <?php endif; ?>
                            <span class="af-muted af-small"><?= e(t('Quality {score} of 100', ['score' => (string) $v['score']])) ?></span>
                        </p>
                        <div class="af-row">
                            <?php if ($canEdit && $row['valid'] && !$row['active']) : ?>
                                <form method="post" action="<?= e($app->url()->plain('/admin/themes/' . $row['slug'] . '/activate')) ?>">
                                    <?= Admin::tokenField($app) ?>
                                    <button type="submit" class="af-btn af-btn-primary af-btn-sm"<?= attrs(['disabled' => $v['errors'] > 0, 'title' => $v['errors'] > 0 ? t('A theme with blocking errors cannot be activated') : null]) ?>><?= Admin::icon('check') ?><?= e(t('Activate')) ?></button>
                                </form>
                            <?php endif; ?>
                            <?php if ($row['valid']) : ?>
                                <a class="af-btn af-btn-sm" href="<?= e($app->url()->plain('/admin/themes/customise/' . $row['slug'])) ?>"><?= Admin::icon('palette') ?><?= e(t('Customise')) ?></a>
                            <?php endif; ?>
                            <a class="af-btn af-btn-sm" href="<?= e($app->url()->plain('/admin/themes/' . $row['slug'] . '/report')) ?>"><?= Admin::icon('shield') ?><?= e(t('Validate')) ?></a>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
