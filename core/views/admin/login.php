<?php
/**
 * AIFEC — pantallas de acceso al panel: entrar, código de verificación, pedir y poner contraseña nueva.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string $step login | code | forgot | reset
 * @var string $error
 * @var string $next
 * @var bool   $recovery en el paso «code»: se pide un código de recuperación en vez del de la aplicación
 */

$app = app();
?>
<?php if ($error !== '') : ?>
    <div class="af-alert af-alert-bad" role="alert"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($step === 'login') : ?>
    <form method="post" action="<?= e($app->url()->plain('/admin/login')) ?>" novalidate>
        <input type="hidden" name="next" value="<?= e($next) ?>">
        <div class="af-field">
            <label class="af-label" for="af-email"><?= e(t('Email')) ?></label>
            <div class="af-control"><input class="af-input" id="af-email" type="email" name="email" value="<?= e(isset($email) ? $email : '') ?>" autocomplete="username" autofocus required></div>
        </div>
        <div class="af-field">
            <label class="af-label" for="af-password"><?= e(t('Password')) ?></label>
            <div class="af-control"><input class="af-input" id="af-password" type="password" name="password" autocomplete="current-password" required></div>
        </div>
        <button type="submit" class="af-btn af-btn-primary"><?= e(t('Sign in')) ?></button>
        <?= $app->hooks()->render('admin.login.after') ?>
        <p class="af-small" style="text-align:center;margin: var(--s4) 0 0"><a href="<?= e($app->url()->plain('/admin/forgot')) ?>"><?= e(t('I forgot my password')) ?></a></p>
    </form>

<?php elseif ($step === 'code' && !empty($recovery)) : ?>
    <p class="af-muted"><?= e(t('Type one of the recovery codes you saved when you turned on two-step verification. Each one works only once.')) ?></p>
    <form method="post" action="<?= e($app->url()->plain('/admin/login/code')) ?>" novalidate>
        <input type="hidden" name="next" value="<?= e($next) ?>">
        <input type="hidden" name="mode" value="recovery">
        <div class="af-field">
            <label class="af-label" for="af-recovery"><?= e(t('Recovery code')) ?></label>
            <div class="af-control"><input class="af-input" id="af-recovery" type="text" name="recovery" placeholder="abcd-2345" autocomplete="off" autocapitalize="off" spellcheck="false" maxlength="12" autofocus required></div>
        </div>
        <button type="submit" class="af-btn af-btn-primary"><?= e(t('Continue')) ?></button>
        <p class="af-small" style="text-align:center;margin: var(--s4) 0 0"><a href="<?= e($app->url()->plain('/admin/login/code', ['next' => $next])) ?>"><?= e(t('Use the code from the app')) ?></a></p>
    </form>

<?php elseif ($step === 'code') : ?>
    <p class="af-muted"><?= e(t('Open your authenticator app and type the six-digit code.')) ?></p>
    <form method="post" action="<?= e($app->url()->plain('/admin/login/code')) ?>" novalidate>
        <input type="hidden" name="next" value="<?= e($next) ?>">
        <div class="af-field">
            <label class="af-label" for="af-code"><?= e(t('Code')) ?></label>
            <div class="af-control"><input class="af-input" id="af-code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" autofocus required></div>
        </div>
        <button type="submit" class="af-btn af-btn-primary"><?= e(t('Continue')) ?></button>
        <p class="af-small" style="text-align:center;margin: var(--s4) 0 0"><a href="<?= e($app->url()->plain('/admin/login/code', ['next' => $next, 'mode' => 'recovery'])) ?>"><?= e(t('Use a recovery code')) ?></a></p>
    </form>

<?php elseif ($step === 'forgot') : ?>
    <?php if (!empty($sent)) : ?>
        <div class="af-alert af-alert-ok" role="status"><?= e(t('If that email has access to this panel, a message with a link is on its way. The link lasts one hour.')) ?></div>
        <p class="af-small" style="text-align:center"><a href="<?= e($app->url()->plain('/admin/login')) ?>"><?= e(t('Back to sign in')) ?></a></p>
    <?php else : ?>
        <p class="af-muted"><?= e(t('Type your email and you will receive a link to choose a new password.')) ?></p>
        <form method="post" action="<?= e($app->url()->plain('/admin/forgot')) ?>" novalidate>
            <div class="af-field">
                <label class="af-label" for="af-email"><?= e(t('Email')) ?></label>
                <div class="af-control"><input class="af-input" id="af-email" type="email" name="email" autocomplete="username" autofocus required></div>
            </div>
            <button type="submit" class="af-btn af-btn-primary"><?= e(t('Send the link')) ?></button>
            <p class="af-small" style="text-align:center;margin: var(--s4) 0 0"><a href="<?= e($app->url()->plain('/admin/login')) ?>"><?= e(t('Back to sign in')) ?></a></p>
        </form>
    <?php endif; ?>

<?php else : ?>
    <?php if (!empty($invalid)) : ?>
        <div class="af-alert af-alert-bad" role="alert"><?= e(t('This link is no longer valid. Ask for a new one.')) ?></div>
        <p class="af-small" style="text-align:center"><a href="<?= e($app->url()->plain('/admin/forgot')) ?>"><?= e(t('Ask for a new link')) ?></a></p>
    <?php else : ?>
        <form method="post" action="<?= e($app->url()->plain('/admin/reset/' . (isset($token) ? $token : ''))) ?>" novalidate>
            <div class="af-field">
                <label class="af-label" for="af-password"><?= e(t('New password')) ?></label>
                <div class="af-control"><input class="af-input" id="af-password" type="password" name="password" autocomplete="new-password" minlength="10" autofocus required>
                    <div class="af-hint"><?= e(t('Use at least {n} characters', ['n' => 10])) ?></div></div>
            </div>
            <div class="af-field">
                <label class="af-label" for="af-password2"><?= e(t('Repeat it')) ?></label>
                <div class="af-control"><input class="af-input" id="af-password2" type="password" name="password2" autocomplete="new-password" required></div>
            </div>
            <button type="submit" class="af-btn af-btn-primary"><?= e(t('Save the password')) ?></button>
        </form>
    <?php endif; ?>
<?php endif; ?>
