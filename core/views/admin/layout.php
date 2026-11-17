<?php
/**
 * AIFEC — marco del panel de administración: menú lateral, barra superior, avisos y contenido.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string $content
 * @var string $title
 * @var array<int,array{0:string,1:?string}> $crumbs
 * @var string $actions
 * @var string $help
 * @var bool $bare
 * @var array<int,array<string,mixed>> $menu
 * @var array{label?:string,items?:array<int,array{label:string,url:string,active:bool}>} $pagetabs pestañas entre pantallas hermanas
 * @var array<string,mixed>|null $user
 * @var array<string,mixed> $flashes
 * @var string $token
 * @var string $siteName
 */

use Aifec\Admin\Admin;

$app = app();
$messages = isset($flashes['messages']) && is_array($flashes['messages']) ? $flashes['messages'] : [];
?>
<!doctype html>
<html lang="<?= e($app->i18n()->lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="af-token" content="<?= e($token) ?>">
<meta name="af-admin" content="<?= e($app->url()->plain('/admin')) ?>">
<title><?= e($title !== '' ? $title . ' · ' . $siteName : $siteName) ?></title>
<script><?= \Aifec\Kernel\Csp::ADMIN_THEME_JS ?></script>
<link rel="stylesheet" href="<?= e($app->url()->asset('c', 'core', 'admin/admin.css')) ?>">
<link rel="stylesheet" href="<?= e($app->url()->asset('c', 'core', 'admin/widgets.css')) ?>">
<?= $bare ? '' : $this->stack('admin.head') . hook('admin.head') ?>
</head>
<?php if ($bare) : ?>
<body>
<main class="af-auth">
    <div class="af-auth-card">
        <div class="af-auth-brand">AIFEC</div>
        <?php foreach ($messages as $message) : ?>
            <div class="af-alert af-alert-<?= e($message[0]) ?>" role="status"><?= e($message[1]) ?></div>
        <?php endforeach; ?>
        <div class="af-card"><div class="af-card-body">
            <h1><?= e($title) ?></h1>
            <?php if ($app->site()->isDemo() && $app->site()->get('public_admin_demo', false)) : ?>
                <div class="af-alert af-alert-info" role="status">
                    <strong><?= e(t('Administration demo')) ?></strong>
                    <?= e(t('This is an isolated test shop. Changes are reset every night. Payments are simulated and email is retained.')) ?>
                </div>
            <?php endif; ?>
            <?= $content ?>
        </div></div>
        <p class="af-muted af-small af-auth-site"><?= e($siteName) ?></p>
    </div>
</main>
<script src="<?= e($app->url()->asset('c', 'core', 'admin/admin.js')) ?>" defer></script>
</body>
<?php else : ?>
<body>
<a class="af-sr" href="#af-main"><?= e(t('Skip to content')) ?></a>
<div class="af-shell" data-af-shell>
    <aside class="af-side" id="af-side" aria-label="<?= e(t('Main menu')) ?>">
        <a class="af-side-brand" href="<?= e($app->url()->plain('/admin')) ?>">AIFEC <span><?= e($siteName) ?></span></a>
        <nav class="af-nav">
            <?php foreach ($menu as $group) : ?>
                <?php if ($group['items'] === []) : ?>
                    <a class="af-nav-link" href="<?= e($group['url']) ?>"<?= $group['active'] ? ' aria-current="page"' : '' ?>><?= Admin::icon((string) $group['icon']) ?><span><?= e($group['label']) ?></span></a>
                <?php else : ?>
                    <details class="af-nav-group"<?= $group['open'] ? ' open' : '' ?>>
                        <summary><?= Admin::icon((string) $group['icon']) ?><span><?= e($group['label']) ?></span></summary>
                        <div class="af-nav-sub">
                            <?php foreach ($group['items'] as $item) : ?>
                                <a href="<?= e($item['url']) ?>"<?= $item['active'] ? ' aria-current="page"' : '' ?>><?= e($item['label']) ?></a>
                            <?php endforeach; ?>
                        </div>
                    </details>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
    </aside>
    <div class="af-main">
        <header class="af-top">
            <button type="button" class="af-icon-btn af-menu-btn" data-af-toggle="menu" aria-controls="af-side" aria-label="<?= e(t('Menu')) ?>"><?= Admin::icon('menu') ?></button>
            <button type="button" class="af-top-search" data-af-palette-open><?= Admin::icon('search') ?><span><?= e(t('Search or jump to…')) ?></span><kbd>Ctrl K</kbd></button>
            <span class="af-top-space"></span>
            <a class="af-icon-btn" href="<?= e($app->url()->to('/')) ?>" target="_blank" rel="noopener" title="<?= e(t('View the shop')) ?>" aria-label="<?= e(t('View the shop')) ?>"><?= Admin::icon('external') ?></a>
            <button type="button" class="af-icon-btn" data-af-theme title="<?= e(t('Light or dark')) ?>" aria-label="<?= e(t('Light or dark')) ?>"><?= Admin::icon('moon') ?></button>
            <div class="af-dropdown">
                <button type="button" class="af-btn af-btn-ghost" data-af-dropdown aria-expanded="false"><?= Admin::icon('user') ?><span class="af-hide-sm"><?= e($user !== null ? (string) $user['name'] : '') ?></span><?= Admin::icon('chevron') ?></button>
                <div class="af-dropdown-menu" hidden>
                    <a href="<?= e($app->url()->plain('/admin/profile')) ?>"><?= Admin::icon('user') ?><?= e(t('My account')) ?></a>
                    <form method="post" action="<?= e($app->url()->plain('/admin/logout')) ?>">
                        <input type="hidden" name="_token" value="<?= e($token) ?>">
                        <button type="submit"><?= Admin::icon('logout') ?><?= e(t('Sign out')) ?></button>
                    </form>
                </div>
            </div>
        </header>
        <main class="af-content" id="af-main">
            <?php foreach ($messages as $message) : ?>
                <div class="af-alert af-alert-<?= e($message[0]) ?>" role="status" data-af-flash><?= e($message[1]) ?></div>
            <?php endforeach; ?>
            <div class="af-head">
                <div class="af-head-titles">
                    <?php if ($crumbs !== []) : ?>
                        <nav class="af-crumbs" aria-label="<?= e(t('You are here')) ?>">
                            <?php foreach ($crumbs as $crumb) : ?>
                                <?php if (isset($crumb[1]) && $crumb[1] !== null && $crumb[1] !== '') : ?>
                                    <a href="<?= e($crumb[1]) ?>"><?= e($crumb[0]) ?></a> ›
                                <?php else : ?>
                                    <span><?= e($crumb[0]) ?></span> ›
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </nav>
                    <?php endif; ?>
                    <h1><?= e($title) ?></h1>
                </div>
                <?php if ($actions !== '') : ?>
                    <div class="af-head-actions"><?= $actions ?></div>
                <?php endif; ?>
            </div>
            <?php if (isset($pagetabs['items'])) : ?>
                <div class="af-tabs af-pagetabs" role="tablist" aria-label="<?= e((string) $pagetabs['label']) ?>" data-af-pagetabs>
                    <?php foreach ($pagetabs['items'] as $tab) : ?>
                        <a class="af-tab" role="tab" href="<?= e($tab['url']) ?>" aria-selected="<?= $tab['active'] ? 'true' : 'false' ?>"<?= $tab['active'] ? ' aria-current="page"' : '' ?>><?= e($tab['label']) ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if ($help !== '') : ?>
                <details class="af-help af-card"><summary><?= e(t('Help: how this works')) ?></summary><div><?= $help ?></div></details>
            <?php endif; ?>
            <?php if ($app->site()->isDemo() && $app->site()->get('public_admin_demo', false)) : ?>
                <div class="af-alert af-alert-info" role="status">
                    <strong><?= e(t('Administration demo')) ?></strong>
                    <?= e(t('This is an isolated test shop. Changes are reset every night. Payments are simulated and email is retained.')) ?>
                </div>
            <?php endif; ?>
            <?= $content ?>
        </main>
    </div>
</div>
<dialog class="af-modal" id="af-palette" data-url="<?= e($app->url()->plain('/admin/palette.json')) ?>" aria-label="<?= e(t('Search or jump to…')) ?>">
    <input class="af-palette-input" type="search" placeholder="<?= e(t('Search or jump to…')) ?>" autocomplete="off" data-af-palette-input>
    <ul class="af-palette-list" data-af-palette-list></ul>
</dialog>
<dialog class="af-modal" id="af-confirm">
    <div class="af-modal-body"><p data-af-confirm-text></p></div>
    <div class="af-modal-foot">
        <button type="button" class="af-btn" data-af-confirm-no><?= e(t('Cancel')) ?></button>
        <button type="button" class="af-btn af-btn-primary" data-af-confirm-yes><?= e(t('Yes, continue')) ?></button>
    </div>
</dialog>
<div class="af-toasts" id="af-toasts" aria-live="polite"></div>
<script src="<?= e($app->url()->asset('c', 'core', 'admin/admin.js')) ?>" defer></script>
<?= $this->stack('admin.foot') . hook('admin.foot') ?>
</body>
<?php endif; ?>
</html>
