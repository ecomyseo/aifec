<?php
/**
 * AIFEC — pantallas propias del correo en el panel: plantillas, vista previa, prueba, liberar y reenviar.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Mail;

use Aifec\Admin\Admin;
use Aifec\Ai\AiException;
use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

final class MailController
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /** @param array<string,string> $params */
    public function templates(Request $request, array $params): Response
    {
        $app = $this->app;
        $app->auth()->requireCap('mail.view');
        $mailer = $this->mailer();
        $rows = [];
        foreach ($mailer->templates() as $key => $template) {
            $rows[] = [
                'module' => (string) $template['module'],
                'key' => (string) $key,
                'description' => (string) $template['description'] !== '' ? t((string) $template['description']) : '',
                'system' => (bool) $template['system'],
                'url' => $app->url()->plain('/admin/mail/templates/' . $key),
            ];
        }
        $admin = $app->auth()->admin();

        return Admin::page($app, 'mail::admin/templates', [
            'rows' => $rows,
            'canEdit' => $app->auth()->can('mail.edit'),
            'testUrl' => $app->url()->plain('/admin/mail/test'),
            'email' => $admin !== null ? (string) $admin['email'] : '',
            'mode' => $mailer->mode(),
        ], ['title' => t('Email templates'), 'active' => '/admin/mail/templates']);
    }

    /** @param array<string,string> $params */
    public function preview(Request $request, array $params): Response
    {
        $app = $this->app;
        $app->auth()->requireCap('mail.view');
        $mailer = $this->mailer();
        $key = (string) $params['key'];
        $templates = $mailer->templates();
        if (!isset($templates[$key])) {
            throw HttpError::notFound();
        }
        $lang = $this->lang($request);

        return Admin::page($app, 'mail::admin/preview', [
            'key' => $key,
            'mail' => $mailer->render($key, $templates[$key]['sample'], $lang),
            'links' => $this->langLinks('/admin/mail/templates/' . $key, $lang),
            'description' => (string) $templates[$key]['description'] !== '' ? t((string) $templates[$key]['description']) : '',
            'editUrl' => $app->auth()->can('mail.edit') ? $app->url()->plain('/admin/mail/templates/' . $key . '/edit', ['lang' => $lang]) : '',
        ], [
            'title' => $key,
            'crumbs' => [[t('Email templates'), $app->url()->plain('/admin/mail/templates')]],
            'active' => '/admin/mail/templates',
        ]);
    }

    /**
     * Los textos de una plantilla (asunto, saludo y despedida) en un idioma: se editan, se previsualizan y se prueban.
     *
     * @param array<string,string> $params
     */
    public function edit(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('mail.edit');
        $key = $this->templateKey($params);
        $lang = $this->lang($request);

        return $this->editScreen($key, $lang, $this->mailer()->texts($key, $lang));
    }

    /**
     * Un solo envío con varios botones (campo «action»): guardar, vista previa, enviarme una prueba o «volver al texto original»
     * de un campo («reset:subject»). Se mira el botón pulsado, no el de guardar: el formulario siempre llega entero.
     *
     * @param array<string,string> $params
     */
    public function save(Request $request, array $params): Response
    {
        $app = $this->app;
        $app->auth()->requireCap('mail.edit');
        $mailer = $this->mailer();
        $key = $this->templateKey($params);
        $lang = $this->lang($request, 'lang');
        $draft = ['subject' => $request->str('subject'), 'greeting' => $request->str('greeting'), 'farewell' => $request->str('farewell')];
        $action = $request->str('action');
        $back = Response::redirect($app->url()->plain('/admin/mail/templates/' . $key . '/edit', ['lang' => $lang]), 303);

        if (strpos($action, 'reset:') === 0) {
            $field = substr($action, 6);
            if (isset($draft[$field])) {
                $mailer->resetText($key, $lang, $field);
                Admin::audit($app, 'mail.texts', 'mail', '', $key . ' [' . $lang . '] ' . $field . ' reset');
                Admin::flash($app, 'ok', t('Back to the original text.'));
            }

            return $back;
        }
        if ($action === 'preview') {
            return $this->editScreen($key, $lang, $draft, true);
        }
        if ($action === 'test') {
            $admin = $app->auth()->admin();
            $email = $admin !== null ? (string) $admin['email'] : '';
            $error = $mailer->sendTemplateTest($key, $email, $lang, $draft);
            Admin::audit($app, 'mail.test', 'mail', '', $key . ' -> ' . $email);
            if ($error === null) {
                Admin::flash($app, 'ok', t('Test email sent to {email}', ['email' => $email]));
            } else {
                Admin::flash($app, 'bad', t('The test email was not sent: {reason}', ['reason' => $error]));
            }

            return $this->editScreen($key, $lang, $draft);
        }

        $unknown = $mailer->saveTexts($key, $lang, $draft);
        Admin::audit($app, 'mail.texts', 'mail', '', $key . ' [' . $lang . ']');
        Admin::flash($app, 'ok', t('Saved'));
        if ($unknown !== []) {
            Admin::flash($app, 'warn', t('These variables do not exist in this template and stay as you wrote them: {names}', ['names' => '{' . implode('}, {', $unknown) . '}']));
        }

        return $back;
    }

    /**
     * «Proponer con IA»: redacta o reescribe el asunto, el saludo y la despedida con un tono y rellena el formulario SIN guardar.
     * El envío trae lo que hay escrito en el formulario (es el mismo formulario, con otro destino en el botón).
     *
     * @param array<string,string> $params
     */
    public function aiPropose(Request $request, array $params): Response
    {
        $app = $this->app;
        $app->auth()->requireCap('mail.edit');
        $this->requireAi();
        $mailer = $this->mailer();
        $key = $this->templateKey($params);
        $lang = $this->lang($request);
        $draft = ['subject' => $request->str('subject'), 'greeting' => $request->str('greeting'), 'farewell' => $request->str('farewell')];
        $tone = mb_substr($request->str('tone'), 0, MailAi::TONE_MAX);

        try {
            $out = (new MailAi($app, $mailer))->propose($key, $lang, $tone, $draft);
        } catch (AiException $e) {
            Admin::flash($app, 'bad', $this->aiProblem($e));

            return $this->editScreen($key, $lang, $draft, false, false, $tone);
        }
        foreach ($out['warnings'] as $warning) {
            Admin::flash($app, 'warn', $warning);
        }
        $values = $draft;
        $filled = 0;
        foreach ($out['texts'] as $field => $text) {
            if ($text !== '') {
                $values[$field] = $text;
                ++$filled;
            }
        }
        if ($filled === 0) {
            Admin::flash($app, 'warn', t('The AI gave nothing that can be used. The form stays as it was.'));

            return $this->editScreen($key, $lang, $draft, false, false, $tone);
        }
        Admin::audit($app, 'mail.ai', 'mail', '', $key . ' [' . $lang . '] propose');

        return $this->editScreen($key, $lang, $values, true, true, $tone);
    }

    /**
     * «Traducir a los demás idiomas»: con los textos guardados de un idioma, propone los de cada otro idioma de la tienda y los enseña
     * lado a lado. No guarda nada: eso lo hace aiTranslateSave() con lo que la persona marque.
     *
     * @param array<string,string> $params
     */
    public function aiTranslate(Request $request, array $params): Response
    {
        $app = $this->app;
        $app->auth()->requireCap('mail.edit');
        $this->requireAi();
        $mailer = $this->mailer();
        $key = $this->templateKey($params);
        $from = $this->lang($request);
        $back = Response::redirect($app->url()->plain('/admin/mail/templates/' . $key . '/edit', ['lang' => $from]), 303);
        $source = $mailer->texts($key, $from);
        $others = array_values(array_filter($app->languages(), static function (string $code) use ($from): bool {
            return $code !== $from;
        }));
        if ($others === []) {
            Admin::flash($app, 'warn', t('This shop has only one language.'));

            return $back;
        }
        if ($source['subject'] === '' && $source['greeting'] === '' && $source['farewell'] === '') {
            Admin::flash($app, 'warn', t('There are no saved texts in this language to translate. Write them and save them first.'));

            return $back;
        }

        $mailAi = new MailAi($app, $mailer);
        $rows = [];
        $usable = 0;
        $problem = '';
        $stopped = false;
        foreach ($others as $code) {
            $saved = $mailer->texts($key, $code);
            $row = [
                'code' => $code, 'texts' => ['subject' => '', 'greeting' => '', 'farewell' => ''], 'saved' => $saved,
                'own' => $saved['subject'] !== '' || $saved['greeting'] !== '' || $saved['farewell'] !== '', 'warnings' => [], 'error' => '',
            ];
            if ($stopped) {
                $row['error'] = $problem;
                $rows[] = $row;
                continue;
            }
            try {
                $out = $mailAi->translate($key, $from, $code, $source);
                $row['texts'] = $out['texts'];
                $row['warnings'] = $out['warnings'];
                if ($out['texts']['subject'] !== '' || $out['texts']['greeting'] !== '' || $out['texts']['farewell'] !== '') {
                    ++$usable;
                }
            } catch (AiException $e) {
                $problem = $this->aiProblem($e);
                $row['error'] = $problem;
                // Sin cupo (o con la IA apagada) no se insiste con los demás idiomas.
                $stopped = !$this->aiRetryable($e);
            }
            $rows[] = $row;
        }
        if ($usable === 0) {
            Admin::flash($app, 'bad', $problem !== '' ? $problem : t('The AI gave nothing that can be used. The form stays as it was.'));
            foreach ($rows as $row) {
                foreach ($row['warnings'] as $warning) {
                    Admin::flash($app, 'warn', $warning);
                }
            }

            return $back;
        }
        Admin::audit($app, 'mail.ai', 'mail', '', $key . ' [' . $from . '] translate');

        return Admin::page($app, 'mail::admin/translate', [
            'key' => $key,
            'from' => $from,
            'source' => $source,
            'rows' => $rows,
            'action' => $app->url()->plain('/admin/mail/templates/' . $key . '/ai/translate/save'),
            'backUrl' => $app->url()->plain('/admin/mail/templates/' . $key . '/edit', ['lang' => $from]),
        ], [
            'title' => $key,
            'crumbs' => [[t('Email templates'), $app->url()->plain('/admin/mail/templates')]],
            'active' => '/admin/mail/templates',
        ]);
    }

    /**
     * Guarda las traducciones que la persona ha marcado (y solo esas). De un campo que la propuesta dejó vacío se queda lo que ya había.
     *
     * @param array<string,string> $params
     */
    public function aiTranslateSave(Request $request, array $params): Response
    {
        $app = $this->app;
        $app->auth()->requireCap('mail.edit');
        $this->requireAi();
        $mailer = $this->mailer();
        $key = $this->templateKey($params);
        $from = $this->lang($request);
        $apply = $request->arr('apply');
        $given = $request->arr('t');
        $saved = [];
        $unknown = [];
        foreach ($app->languages() as $code) {
            if ($code === $from || !isset($apply[$code])) {
                continue;
            }
            $row = isset($given[$code]) && is_array($given[$code]) ? $mailer->normalizeTexts($given[$code]) : $mailer->normalizeTexts([]);
            $current = $mailer->texts($key, $code);
            foreach ($row as $field => $text) {
                if ($text === '') {
                    $row[$field] = $current[$field];
                }
            }
            if ($row['subject'] === '' && $row['greeting'] === '' && $row['farewell'] === '') {
                continue;
            }
            $unknown = array_merge($unknown, $mailer->saveTexts($key, $code, $row));
            Admin::audit($app, 'mail.texts', 'mail', '', $key . ' [' . $code . '] translated with AI');
            $saved[] = strtoupper($code);
        }
        if ($saved === []) {
            Admin::flash($app, 'warn', t('Nothing was selected, so nothing was saved.'));
        } else {
            Admin::flash($app, 'ok', t('Saved the texts of: {languages}', ['languages' => implode(', ', $saved)]));
            if ($unknown !== []) {
                Admin::flash($app, 'warn', t('These variables do not exist in this template and stay as you wrote them: {names}', ['names' => '{' . implode('}, {', array_values(array_unique($unknown))) . '}']));
            }
        }

        return Response::redirect($app->url()->plain('/admin/mail/templates/' . $key . '/edit', ['lang' => $from]), 303);
    }

    /** @param array<string,string> $params */
    public function test(Request $request, array $params): Response
    {
        $app = $this->app;
        $app->auth()->requireCap('mail.edit');
        $admin = $app->auth()->admin();
        $email = $admin !== null ? (string) $admin['email'] : '';
        $error = $this->mailer()->sendTest($email);
        Admin::audit($app, 'mail.test', 'mail', '', $email);
        if ($error === null) {
            Admin::flash($app, 'ok', t('Test email sent to {email}', ['email' => $email]));
        } else {
            Admin::flash($app, 'bad', t('The test email was not sent: {reason}', ['reason' => $error]));
        }

        return Response::redirect($app->url()->plain('/admin/mail/templates'));
    }

    /** @param array<string,string> $params */
    public function release(Request $request, array $params): Response
    {
        $app = $this->app;
        $app->auth()->requireCap('mail.edit');
        $id = (int) $params['id'];
        $done = $this->mailer()->release($id);
        if ($done) {
            Admin::audit($app, 'mail.release', 'mail', (string) $id);
            Admin::flash($app, 'ok', t('Released. It will go out within a minute.'));
        } else {
            Admin::flash($app, 'bad', t('This email cannot be released.'));
        }

        return Response::redirect(Admin::resourceUrl($app, 'mail', $id));
    }

    /** @param array<string,string> $params */
    public function resend(Request $request, array $params): Response
    {
        $app = $this->app;
        $app->auth()->requireCap('mail.edit');
        $id = (int) $params['id'];
        $newId = $this->mailer()->resend($id);
        if ($newId > 0) {
            Admin::audit($app, 'mail.resend', 'mail', (string) $id, '#' . $newId);
            Admin::flash($app, 'ok', t('Queued again. It will go out within a minute.'));

            return Response::redirect(Admin::resourceUrl($app, 'mail', $newId));
        }
        Admin::flash($app, 'bad', t('This email cannot be sent again.'));

        return Response::redirect(Admin::resourceUrl($app, 'mail', $id));
    }

    /**
     * La pantalla de edición de los textos.
     *
     * @param array<string,string> $values   lo que se enseña en los campos (los guardados o lo que se estaba escribiendo)
     * @param bool                 $preview  también se compone el correo con esos textos, sin guardarlos
     */
    private function editScreen(string $key, string $lang, array $values, bool $preview = false, bool $proposal = false, string $tone = ''): Response
    {
        $app = $this->app;
        $mailer = $this->mailer();
        $templates = $mailer->templates();
        $template = $templates[$key];
        $values += ['subject' => '', 'greeting' => '', 'farewell' => ''];
        $admin = $app->auth()->admin();

        // La ayuda de la IA solo sale con el módulo «ai» encendido y configurado, y a quien puede usarla.
        $ai = null;
        if (MailAi::available($app)) {
            $ai = [
                'proposeUrl' => $app->url()->plain('/admin/mail/templates/' . $key . '/ai/propose'),
                'translateUrl' => $app->url()->plain('/admin/mail/templates/' . $key . '/ai/translate'),
                'tone' => $tone,
                'toneMax' => MailAi::TONE_MAX,
                'canTranslate' => count($app->languages()) > 1,
                'proposal' => $proposal,
            ];
        }

        return Admin::page($app, 'mail::admin/edit', [
            'ai' => $ai,
            'key' => $key,
            'description' => (string) $template['description'] !== '' ? t((string) $template['description']) : '',
            'lang' => $lang,
            'links' => $this->langLinks('/admin/mail/templates/' . $key . '/edit', $lang),
            'action' => $app->url()->plain('/admin/mail/templates/' . $key . '/edit'),
            'previewUrl' => $app->url()->plain('/admin/mail/templates/' . $key, ['lang' => $lang]),
            'values' => $values,
            'saved' => $mailer->texts($key, $lang),
            'defaults' => $mailer->defaults($key, $lang),
            'variables' => $mailer->variables($key),
            'mail' => $preview ? $mailer->render($key, $template['sample'], $lang, $values) : null,
            'email' => $admin !== null ? (string) $admin['email'] : '',
        ], [
            'title' => $key,
            'crumbs' => [[t('Email templates'), $app->url()->plain('/admin/mail/templates')]],
            'active' => '/admin/mail/templates',
        ]);
    }

    /**
     * Las rutas de la IA no existen sin el módulo de IA (404), piden «ai.use» (403) y tampoco sirven con la IA apagada o sin proveedor (404).
     */
    private function requireAi(): void
    {
        if (!MailAi::installed($this->app)) {
            throw HttpError::notFound();
        }
        $this->app->auth()->requireCap('ai.use');
        if (!MailAi::ready($this->app)) {
            throw HttpError::notFound();
        }
    }

    /** El aviso para quien pulsa cuando la IA no ha podido contestar. */
    private function aiProblem(AiException $e): string
    {
        if ($e instanceof \Aifec\Modules\Ai\AiLimitReached) {
            return t('The daily AI limit is reached. Try again tomorrow or raise the limit in the AI settings.');
        }
        if ($e instanceof \Aifec\Modules\Ai\AiDisabled) {
            return t('The AI is off.');
        }

        return t('The AI could not answer: {reason}', ['reason' => $e->getMessage()]);
    }

    /** ¿Vale la pena pedir el siguiente idioma tras este fallo? (No con el tope del día agotado ni con la IA apagada.) */
    private function aiRetryable(AiException $e): bool
    {
        return !($e instanceof \Aifec\Modules\Ai\AiLimitReached) && !($e instanceof \Aifec\Modules\Ai\AiDisabled);
    }

    /** @param array<string,string> $params */
    private function templateKey(array $params): string
    {
        $key = (string) $params['key'];
        if (!isset($this->mailer()->templates()[$key])) {
            throw HttpError::notFound();
        }

        return $key;
    }

    /** El idioma pedido (?lang= o el campo «lang» de un envío) si el sitio lo tiene; si no, el principal. */
    private function lang(Request $request, string $field = 'lang'): string
    {
        $lang = strtolower($request->str($field));

        return in_array($lang, $this->app->languages(), true) ? $lang : $this->app->defaultLang();
    }

    /**
     * Un enlace por idioma del sitio.
     *
     * @return array<int,array{code:string,url:string,current:bool}>
     */
    private function langLinks(string $path, string $current): array
    {
        $links = [];
        foreach ($this->app->languages() as $code) {
            $links[] = ['code' => $code, 'url' => $this->app->url()->plain($path, ['lang' => $code]), 'current' => $code === $current];
        }

        return $links;
    }

    private function mailer(): Mailer
    {
        $mailer = $this->app->service('mailer');
        if (!$mailer instanceof Mailer) {
            throw new \LogicException('The mailer service is not the mail module one');
        }

        return $mailer;
    }
}
