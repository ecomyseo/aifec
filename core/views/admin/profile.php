<?php
/**
 * AIFEC — «Mi cuenta»: datos, contraseña, verificación en dos pasos y sesiones. Un apartado por pestaña.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed>  $user
 * @var array<string,string> $input       nombre, correo e idioma que se enseñan en el formulario
 * @var array<string,string> $errors
 * @var string               $tab         pestaña abierta
 * @var array<string,string> $tabs        clave => etiqueta
 * @var array<string,string> $locales
 * @var bool                 $twoStepOn
 * @var string|null          $pending     secreto pendiente de confirmar (solo mientras se activa)
 * @var string               $pendingUri
 * @var string[]             $newCodes    códigos de recuperación recién hechos (solo en la respuesta que los crea)
 * @var int                  $codesLeft   códigos de recuperación sin gastar
 * @var array<int,array<string,mixed>> $sessions
 * @var int                  $minPassword
 */

use Aifec\Admin\Admin;

$app = app();
$af_url = static function (string $path) use ($app): string {
    return $app->url()->plain('/admin/profile' . $path);
};
$af_error = static function (string $name) use ($errors): string {
    return isset($errors[$name]) ? '<div class="af-error" role="alert">' . e($errors[$name]) . '</div>' : '';
};
$af_invalid = static function (string $name) use ($errors): string {
    return isset($errors[$name]) ? ' af-field-invalid' : '';
};
$af_when = static function ($sql) use ($app): string {
    $time = \Aifec\Kernel\Clock::parse((string) $sql);

    return $time > 0 ? $app->i18n()->date($time, 'medium', $app->site()->timezone()) : '';
};
?>
<div class="af-tabs" role="tablist" data-af-tabs data-af-active="<?= e($tab) ?>">
    <?php foreach ($tabs as $af_key => $af_label) : ?>
        <button type="button" class="af-tab" role="tab" data-af-tab="<?= e($af_key) ?>" aria-selected="<?= $af_key === $tab ? 'true' : 'false' ?>"><?= e(t($af_label)) ?></button>
    <?php endforeach; ?>
</div>

<div class="af-card"><div class="af-card-body">
<?php if ($publicIdentityReadOnly) : ?>
    <p class="af-alert af-alert-info" role="status"><?= e(t('Public demo accounts have fixed credentials so other visitors can keep signing in.')) ?></p>
    <fieldset disabled style="border: 0; padding: 0; margin: 0; min-width: 0">
<?php endif; ?>

    <div class="af-tabpanel" role="tabpanel" data-af-panel="prdata"<?= $tab === 'prdata' ? '' : ' hidden' ?>>
        <form method="post" action="<?= e($af_url('/data')) ?>" class="af-form" novalidate>
            <?= Admin::tokenField($app) ?>
            <div class="af-field<?= $af_invalid('name') ?>">
                <label class="af-label" for="pr-name"><?= e(t('Name')) ?></label>
                <div class="af-control"><input class="af-input af-input-md" id="pr-name" type="text" name="name" value="<?= e($input['name']) ?>" maxlength="120" autocomplete="name" required><?= $af_error('name') ?></div>
            </div>
            <div class="af-field<?= $af_invalid('email') ?>">
                <label class="af-label" for="pr-email"><?= e(t('Email')) ?></label>
                <div class="af-control"><input class="af-input af-input-md" id="pr-email" type="email" name="email" value="<?= e($input['email']) ?>" maxlength="190" autocomplete="username" required>
                    <div class="af-hint"><?= e(t('It is the one you sign in with.')) ?></div><?= $af_error('email') ?></div>
            </div>
            <div class="af-field<?= $af_invalid('current_password') ?>">
                <label class="af-label" for="pr-current"><?= e(t('Current password')) ?></label>
                <div class="af-control"><input class="af-input af-input-md" id="pr-current" type="password" name="current_password" autocomplete="current-password">
                    <div class="af-hint"><?= e(t('Only needed to change the email.')) ?></div><?= $af_error('current_password') ?></div>
            </div>
            <div class="af-field">
                <label class="af-label" for="pr-locale"><?= e(t('Panel language')) ?></label>
                <div class="af-control"><select class="af-select af-input-md" id="pr-locale" name="locale">
                    <?php foreach ($locales as $af_code => $af_name) : ?>
                        <option value="<?= e($af_code) ?>"<?= $af_code === $input['locale'] ? ' selected' : '' ?>><?= e($af_name) ?></option>
                    <?php endforeach; ?>
                </select></div>
            </div>
            <div class="af-form-actions"><button type="submit" class="af-btn af-btn-primary"><?= Admin::icon('check') ?><?= e(t('Save')) ?></button></div>
        </form>
    </div>

    <div class="af-tabpanel" role="tabpanel" data-af-panel="prpassword"<?= $tab === 'prpassword' ? '' : ' hidden' ?>>
        <form method="post" action="<?= e($af_url('/password')) ?>" class="af-form" novalidate>
            <?= Admin::tokenField($app) ?>
            <input type="text" name="username" value="<?= e((string) $user['email']) ?>" autocomplete="username" hidden>
            <div class="af-field<?= $af_invalid('current_password') ?>">
                <label class="af-label" for="pw-current"><?= e(t('Current password')) ?></label>
                <div class="af-control"><input class="af-input af-input-md" id="pw-current" type="password" name="current_password" autocomplete="current-password" required><?= $af_error('current_password') ?></div>
            </div>
            <div class="af-field<?= $af_invalid('password') ?>">
                <label class="af-label" for="pw-new"><?= e(t('New password')) ?></label>
                <div class="af-control"><input class="af-input af-input-md" id="pw-new" type="password" name="password" autocomplete="new-password" minlength="<?= (int) $minPassword ?>" required>
                    <div class="af-hint"><?= e(t('At least {n} characters. A long phrase is better than a short tangle.', ['n' => $minPassword])) ?></div><?= $af_error('password') ?></div>
            </div>
            <div class="af-field<?= $af_invalid('password2') ?>">
                <label class="af-label" for="pw-repeat"><?= e(t('Repeat it')) ?></label>
                <div class="af-control"><input class="af-input af-input-md" id="pw-repeat" type="password" name="password2" autocomplete="new-password" required><?= $af_error('password2') ?></div>
            </div>
            <p class="af-muted af-small"><?= e(t('When you change it, your other sessions are closed.')) ?></p>
            <div class="af-form-actions"><button type="submit" class="af-btn af-btn-primary"><?= Admin::icon('check') ?><?= e(t('Change password')) ?></button></div>
        </form>
    </div>

    <div class="af-tabpanel" role="tabpanel" data-af-panel="prtwostep"<?= $tab === 'prtwostep' ? '' : ' hidden' ?>>
        <?php if ($twoStepOn) : ?>
            <p><?= Admin::badge(t('On'), 'ok') ?> <?= e(t('When you sign in you are asked for a code from your authenticator app.')) ?></p>
            <?php if ($newCodes !== []) : ?>
                <?php $af_codesText = implode("\n", $newCodes) . "\n"; ?>
                <div class="af-alert af-alert-warn" role="alert"><?= e(t('Save these recovery codes now. They will not be shown again. If you lose your phone, each one lets you sign in once.')) ?></div>
                <div class="af-card"><div class="af-card-body">
                    <ul class="af-code-list" style="list-style:none;margin: 0 0 var(--s4);padding:0;columns:2;font-size: var(--f3);letter-spacing:.06em">
                        <?php foreach ($newCodes as $af_code) : ?>
                            <li><code class="af-code"><?= e($af_code) ?></code></li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="af-form-actions">
                        <button type="button" class="af-btn" data-af-copy="<?= e($af_codesText) ?>" data-af-copied="<?= e(t('Codes copied')) ?>"><?= e(t('Copy')) ?></button>
                        <a class="af-btn" download="aifec-recovery-codes.txt" href="data:text/plain;charset=utf-8,<?= e(rawurlencode($af_codesText)) ?>"><?= Admin::icon('download') ?><?= e(t('Download .txt')) ?></a>
                    </div>
                </div></div>
            <?php else : ?>
                <p><?= e(tn('You have {n} recovery code left.', 'You have {n} recovery codes left.', $codesLeft)) ?></p>
            <?php endif; ?>
            <form method="post" action="<?= e($af_url('/two-step/codes')) ?>" class="af-form" data-af-confirm="<?= e(t('Make new recovery codes? The old ones will stop working.')) ?>" novalidate>
                <?= Admin::tokenField($app) ?>
                <div class="af-field<?= $af_invalid('codes_password') ?>">
                    <label class="af-label" for="ts-codes-password"><?= e(t('Current password')) ?></label>
                    <div class="af-control"><input class="af-input af-input-md" id="ts-codes-password" type="password" name="current_password" autocomplete="current-password" required><?= $af_error('codes_password') ?></div>
                </div>
                <div class="af-form-actions"><button type="submit" class="af-btn"><?= e(t('Make new recovery codes')) ?></button></div>
            </form>
            <hr>
            <form method="post" action="<?= e($af_url('/two-step/disable')) ?>" class="af-form" novalidate>
                <?= Admin::tokenField($app) ?>
                <div class="af-field<?= $af_invalid('off_password') ?>">
                    <label class="af-label" for="ts-password"><?= e(t('Current password')) ?></label>
                    <div class="af-control"><input class="af-input af-input-md" id="ts-password" type="password" name="current_password" autocomplete="current-password" required><?= $af_error('off_password') ?></div>
                </div>
                <div class="af-form-actions"><button type="submit" class="af-btn af-btn-danger"><?= e(t('Turn off two-step verification')) ?></button></div>
            </form>
        <?php elseif ($pending !== null) : ?>
            <ol class="af-steps">
                <li><?= e(t('Open your authenticator app (Google Authenticator, Authy, 1Password…) and add an account.')) ?></li>
                <li><?= e(t('Type this key by hand, or open the link from the phone that has the app:')) ?>
                    <p><code class="af-code" style="display:inline-block;font-size: var(--f2);letter-spacing:.08em"><?= e(trim(chunk_split($pending, 4, ' '))) ?></code></p>
                    <p class="af-small"><a href="<?= e($pendingUri) ?>"><?= e(t('Open in the authenticator app')) ?></a></p>
                </li>
                <li><?= e(t('Type the six-digit code the app shows:')) ?></li>
            </ol>
            <form method="post" action="<?= e($af_url('/two-step/confirm')) ?>" class="af-form" novalidate>
                <?= Admin::tokenField($app) ?>
                <div class="af-field<?= $af_invalid('code') ?>">
                    <label class="af-label" for="ts-code"><?= e(t('Code')) ?></label>
                    <div class="af-control"><input class="af-input" id="ts-code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" style="max-width:9rem" required><?= $af_error('code') ?></div>
                </div>
                <div class="af-form-actions">
                    <button type="submit" class="af-btn af-btn-primary"><?= Admin::icon('check') ?><?= e(t('Turn on')) ?></button>
                    <button type="submit" class="af-btn" formaction="<?= e($af_url('/two-step/cancel')) ?>" formnovalidate><?= e(t('Cancel')) ?></button>
                </div>
            </form>
        <?php else : ?>
            <p><?= Admin::badge(t('Off'), 'warn') ?> <?= e(t('With two-step verification, your password alone is not enough to get in: a code from your phone is also needed.')) ?></p>
            <form method="post" action="<?= e($af_url('/two-step/start')) ?>">
                <?= Admin::tokenField($app) ?>
                <div class="af-form-actions"><button type="submit" class="af-btn af-btn-primary"><?= e(t('Set up two-step verification')) ?></button></div>
            </form>
        <?php endif; ?>
    </div>

    <div class="af-tabpanel" role="tabpanel" data-af-panel="prsessions"<?= $tab === 'prsessions' ? '' : ' hidden' ?>>
        <p class="af-muted"><?= e(t('Each browser where you have signed in keeps a session open for two weeks.')) ?></p>
        <div class="af-table-wrap"><table class="af-table">
            <thead><tr><th><?= e(t('Signed in')) ?></th><th><?= e(t('Last activity')) ?></th></tr></thead>
            <tbody>
            <?php foreach ($sessions as $af_session) : ?>
                <tr>
                    <td><?= e($af_when($af_session['created_at'])) ?></td>
                    <td><?= e($af_when($af_session['last_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <form method="post" action="<?= e($af_url('/sessions/close')) ?>" style="margin-top: var(--s4)">
            <?= Admin::tokenField($app) ?>
            <div class="af-form-actions"><button type="submit" class="af-btn"><?= e(t('Close my other sessions')) ?></button></div>
        </form>
    </div>

<?php if ($publicIdentityReadOnly) : ?>
    </fieldset>
<?php endif; ?>
</div></div>
