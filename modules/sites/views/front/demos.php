<?php
/**
 * AIFEC — la página pública de demostraciones: una tarjeta por demo con sus enlaces y sus datos de acceso.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed>            $page  datos de cabecera (ver Aifec\Front\Page)
 * @var array<int,array<string,mixed>> $demos una por demostración: name, sector, description, preview, shop_url, panel, admin_url, email, password
 *                                           (los tres últimos, vacíos si el maestro no enseña el acceso al panel)
 * @var bool $showPanel el maestro enseña el acceso al panel de las demostraciones (ajuste sites.demos_show_panel)
 */

$this->layout('layout', ['page' => $page]);
?>
<div class="af-container af-section">
    <h1 class="af-title"><?= e(t('Demo shops')) ?></h1>
    <p class="af-subtitle"><?= e(!empty($showPanel) ? t('Try complete shops built with AIFEC. Browse them, buy with the test payment and open their panel.') : t('Try complete shops built with AIFEC. Browse them and buy with the test payment.')) ?></p>
    <div class="af-alert af-alert-info">
        <?= e(t('The demos are reset every night. Nothing is charged and no email or order leaves them: everything you do here is for testing.')) ?>
    </div>
    <?php if ($demos === []) : ?>
        <div class="af-empty"><h2><?= e(t('There are no demos yet')) ?></h2><p><?= e(t('Come back soon.')) ?></p></div>
    <?php else : ?>
        <div class="af-grid af-cols-3 af-demos-grid">
            <?php foreach ($demos as $demo) : ?>
                <article class="af-card" data-demo="<?= e((string) $demo['id']) ?>">
                    <?php if ($demo['preview'] !== '') : ?>
                        <img src="<?= e((string) $demo['preview']) ?>" alt="<?= e(t('Home page of {name}', ['name' => (string) $demo['name']])) ?>" width="600" height="450" loading="lazy">
                    <?php endif; ?>
                    <div class="af-stack">
                        <h2><?= e((string) $demo['name']) ?></h2>
                        <?php if ($demo['sector'] !== '') : ?>
                            <p class="af-small af-muted"><?= e(t('Sector')) ?>: <?= e((string) $demo['sector']) ?></p>
                        <?php endif; ?>
                        <?php if ($demo['description'] !== '') : ?>
                            <p><?= e((string) $demo['description']) ?></p>
                        <?php endif; ?>
                        <div class="af-row">
                            <?php // Siete «Ver la tienda» seguidos no le dicen nada a quien navega por la lista de enlaces: el nombre va en la etiqueta. ?>
                            <a class="af-btn af-btn-primary" href="<?= e((string) $demo['shop_url']) ?>" aria-label="<?= e(t('See the shop: {name}', ['name' => (string) $demo['name']])) ?>"><?= e(t('See the shop')) ?></a>
                            <?php if (!empty($demo['panel'])) : ?>
                                <?php if (!empty($demo['administration'])) : ?>
                                    <a class="af-btn" href="<?= e((string) $demo['admin_url'] . '/login?email=' . rawurlencode((string) $demo['administrator_email'])) ?>"><?= e(t('Superadministrator demo')) ?></a>
                                    <a class="af-btn" href="<?= e((string) $demo['admin_url'] . '/login?email=' . rawurlencode((string) $demo['email'])) ?>"><?= e(t('Test user demo')) ?></a>
                                <?php else : ?>
                                    <a class="af-btn" href="<?= e((string) $demo['admin_url']) ?>" aria-label="<?= e(t('Enter the panel: {name}', ['name' => (string) $demo['name']])) ?>"><?= e(t('Enter the panel')) ?></a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($demo['panel'])) : ?>
                            <dl class="af-small">
                                <?php if (!empty($demo['administration'])) : ?>
                                    <dt><?= e(t('Superadministrator demo')) ?></dt>
                                    <dd class="af-mono"><?= e((string) $demo['administrator_email']) ?></dd>
                                    <dt><?= e(t('Test user demo')) ?></dt>
                                    <dd class="af-mono"><?= e((string) $demo['email']) ?></dd>
                                <?php else : ?>
                                <dt><?= e(t('Panel email')) ?></dt>
                                <dd class="af-mono"><?= e((string) $demo['email']) ?></dd>
                                <?php endif; ?>
                                <dt><?= e(t('Panel password')) ?></dt>
                                <dd class="af-mono"><?= e((string) $demo['password']) ?></dd>
                            </dl>
                            <?php if (!empty($demo['administration'])) : ?>
                                <p class="af-small"><?= e(t('The administrator can configure the test shop and its AI. The test user has limited permissions. Server access, code installation and external payments remain closed.')) ?></p>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
