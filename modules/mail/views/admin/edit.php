<?php
/**
 * AIFEC — textos editables de una plantilla de correo: asunto, saludo y despedida, por idioma.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string                                             $key        código de la plantilla
 * @var string                                             $description
 * @var string                                             $lang       idioma que se edita
 * @var array<int,array{code:string,url:string,current:bool}> $links   un enlace por idioma del sitio
 * @var string                                             $action     dirección del envío
 * @var string                                             $previewUrl vista previa con los textos guardados
 * @var array<string,string>                               $values     lo que se enseña en los campos
 * @var array<string,string>                               $saved      lo guardado (vacío = se usa el de serie)
 * @var array<string,string>                               $defaults   los textos de serie en este idioma
 * @var string[]                                           $variables  variables que se pueden escribir entre llaves
 * @var array{subject:string,html:string,text:string}|null $mail       vista previa de lo escrito, sin guardar
 * @var string                                             $email      dónde llega la prueba
 * @var array<string,mixed>|null                           $ai         la ayuda de la IA (null = sin módulo de IA, apagada o sin permiso)
 */

declare(strict_types=1);

use Aifec\Admin\Admin;

$af_fields = [
    'subject' => ['label' => t('Subject'), 'rows' => 0],
    'greeting' => ['label' => t('Greeting'), 'rows' => 2],
    'farewell' => ['label' => t('Sign-off'), 'rows' => 3],
];
?><div class="af-card"><div class="af-card-body">
    <?php if ($description !== '') : ?>
        <p class="af-muted"><?= e($description) ?></p>
    <?php endif; ?>
    <div class="af-toolbar">
        <?php foreach ($links as $link) : ?>
            <a class="af-btn af-btn-sm<?= $link['current'] ? ' af-btn-primary' : '' ?>" href="<?= e($link['url']) ?>"><?= e(strtoupper((string) $link['code'])) ?></a>
        <?php endforeach; ?>
    </div>
    <?php if ($ai !== null && $ai['proposal']) : ?>
        <div class="af-alert af-alert-info" role="status" data-mail-ai-proposal><?= e(t('AI proposal: check it and save.')) ?></div>
    <?php endif; ?>
    <p class="af-small"><?= e(t('Variables you can write between braces:')) ?>
        <?php foreach ($variables as $name) : ?>
            <code class="af-code">{<?= e($name) ?>}</code>
        <?php endforeach; ?>
    </p>
    <form method="post" action="<?= e($action) ?>" class="af-form" novalidate>
        <?= Admin::tokenField(app()) ?>
        <input type="hidden" name="lang" value="<?= e($lang) ?>">
        <?php // El botón que se pulsa al dar a Intro es el primero del formulario: aquí, guardar. ?>
        <button type="submit" name="action" value="save" tabindex="-1" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden"><?= e(t('Save')) ?></button>
        <?php foreach ($af_fields as $name => $field) : ?>
            <div class="af-field">
                <label class="af-label" for="mt-<?= e($name) ?>"><?= e($field['label']) ?></label>
                <div class="af-control">
                    <?php if ($field['rows'] === 0) : ?>
                        <input class="af-input" id="mt-<?= e($name) ?>" type="text" name="<?= e($name) ?>" value="<?= e($values[$name]) ?>" maxlength="191" autocomplete="off">
                    <?php else : ?>
                        <textarea class="af-textarea" id="mt-<?= e($name) ?>" name="<?= e($name) ?>" rows="<?= (int) $field['rows'] ?>" maxlength="2000"><?= e($values[$name]) ?></textarea>
                    <?php endif; ?>
                    <div class="af-hint">
                        <?= $defaults[$name] !== '' ? e(t('Original text:')) . ' <span class="af-mono">' . e($defaults[$name]) . '</span>' : e(t('The original has no text here. Leave it empty to keep it that way.')) ?>
                        <?php if ($saved[$name] !== '') : ?>
                            <button type="submit" name="action" value="reset:<?= e($name) ?>" class="af-btn af-btn-sm af-btn-ghost"><?= e(t('Back to the original text')) ?></button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if ($ai !== null) : ?>
            <?php // Es el mismo formulario: los botones de la IA mandan lo escrito a su propia dirección (formaction) y no guardan nada. ?>
            <div class="af-field" data-mail-ai>
                <label class="af-label" for="mt-tone"><?= e(t('Tone')) ?></label>
                <div class="af-control">
                    <input class="af-input" id="mt-tone" type="text" name="tone" value="<?= e($ai['tone']) ?>" maxlength="<?= (int) $ai['toneMax'] ?>" list="mt-tones" autocomplete="off" placeholder="<?= e(t('Friendly, formal, short… or describe it')) ?>">
                    <datalist id="mt-tones">
                        <option value="<?= e(t('Friendly')) ?>"></option>
                        <option value="<?= e(t('Formal')) ?>"></option>
                        <option value="<?= e(t('Short and direct')) ?>"></option>
                    </datalist>
                    <div class="af-form-actions">
                        <button type="submit" formaction="<?= e($ai['proposeUrl']) ?>" class="af-btn"><?= Admin::icon('sparkles') ?><?= e(t('Propose with AI')) ?></button>
                        <?php if ($ai['canTranslate']) : ?>
                            <button type="submit" formaction="<?= e($ai['translateUrl']) ?>" class="af-btn"><?= Admin::icon('globe') ?><?= e(t('Translate to the other languages')) ?></button>
                        <?php endif; ?>
                    </div>
                    <div class="af-hint"><?= e(t('The AI only proposes: nothing is saved until you save. Translating uses the saved texts of this language.')) ?></div>
                </div>
            </div>
        <?php endif; ?>
        <p class="af-muted af-small"><?= e(t('The order table, the buttons and the legal data are drawn by the template and are not edited here. What you write is always shown as plain text.')) ?></p>
        <div class="af-form-actions">
            <button type="submit" name="action" value="save" class="af-btn af-btn-primary"><?= Admin::icon('check') ?><?= e(t('Save')) ?></button>
            <button type="submit" name="action" value="preview" class="af-btn"><?= Admin::icon('eye') ?><?= e(t('Preview without saving')) ?></button>
            <button type="submit" name="action" value="test" class="af-btn"><?= Admin::icon('mail') ?><?= e(t('Send me a test')) ?></button>
            <span class="af-muted af-small"><?= e(t('It goes to {email}.', ['email' => $email])) ?></span>
        </div>
    </form>
</div></div>
<?php if ($mail !== null) : ?>
<div class="af-card">
    <div class="af-card-head"><h2><?= e(t('Preview with the example data')) ?></h2></div>
    <div class="af-card-body">
        <dl class="af-kv"><dt><?= e(t('Subject')) ?></dt><dd><?= e($mail['subject']) ?></dd></dl>
        <iframe sandbox srcdoc="<?= e($mail['html']) ?>" title="<?= e($key) ?>" class="af-frame" style="height:560px"></iframe>
    </div>
    <details class="af-help"><summary><?= e(t('Plain text version')) ?></summary><pre class="af-mono af-small"><?= e($mail['text']) ?></pre></details>
</div>
<?php endif; ?>
