<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Cms\Front;

use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Modules\Cms\Cms;
use Aifec\Util\Url;

/**
 * Recibe lo que escriben las personas en los formularios del contenido.
 *
 * Una persona o un robot: el campo trampa y la marca de tiempo se descartan EN SILENCIO (se contesta «gracias» y no se guarda
 * nada, para que un robot no aprenda qué le ha delatado); el límite por hora y los enlaces de más se rechazan a la vista,
 * porque una persona de verdad puede toparse con ellos y tiene que saber por qué.
 *
 * La página del formulario está en la caché de página y se sirve a todo el mundo, así que la marca de tiempo (que es de cada
 * persona y de cada momento) no puede ir en su HTML: el guion la pide con un GET a /formulario/{código}/marca al enfocar el
 * primer campo y rellena el campo oculto. Sin JavaScript el formulario se envía sin marca y solo cuentan la trampa, el límite
 * y los enlaces.
 */
final class FormController
{
    /** Lo que se contesta cuando el formulario no tiene un texto de gracias propio. */
    private const THANKS = 'Thank you. We have received your message and we will answer soon.';

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    private function cms(): Cms
    {
        /** @var Cms $cms */
        $cms = $this->app->service('cms');

        return $cms;
    }

    /**
     * La marca de tiempo firmada para el formulario. Es estado (cambia en cada petición): sin caché.
     *
     * @param array<string,string> $params code
     */
    public function stamp(Request $request, array $params): Response
    {
        $form = $this->form($params);

        return Response::json(['stamp' => (new FormGuard($this->app))->issue((string) $form['code'])]);
    }

    /**
     * @param array<string,string> $params code
     */
    public function submit(Request $request, array $params): Response
    {
        $form = $this->form($params);
        $code = (string) $form['code'];
        $guard = new FormGuard($this->app);
        $back = $this->back($request);
        $json = $request->wantsJson();

        // Robots: se contesta «gracias» y no se guarda nada.
        $stamp = $request->input(Cms::FORM_STAMP);
        if ($guard->trapFilled($request) || !$guard->stampAcceptable(is_scalar($stamp) ? (string) $stamp : '', $code)) {
            return $this->thanks($form, $back, $json);
        }

        $input = $request->arr('f');
        list($clean, $errors) = $this->cms()->formValidate($form, $input);

        // Demasiados enlaces: se señalan los campos de texto que los llevan.
        $links = 0;
        foreach ($clean as $value) {
            $links += FormGuard::countLinks($value);
        }
        if ($links > FormGuard::MAX_LINKS) {
            foreach ($form['fields'] as $field) {
                $key = (string) $field['key'];
                if (isset($clean[$key]) && FormGuard::countLinks($clean[$key]) > 0 && !isset($errors[$key])) {
                    $errors[$key] = t('Please remove some links: a message can have at most {n}.', ['n' => FormGuard::MAX_LINKS]);
                }
            }
        }

        if ($errors !== []) {
            return $this->invalid($form, $clean, $errors, $back, $json);
        }

        if (!$guard->allow($request)) {
            $message = t('Too many messages in a short time. Please try again later.');
            if ($json) {
                $response = Response::json(['ok' => false, 'message' => $message, 'errors' => new \stdClass()], 429);
                $response->headers['Retry-After'] = '3600';

                return $response;
            }
            throw HttpError::tooMany($message);
        }

        $entryId = $this->cms()->formStore((int) $form['id'], $clean, $request->ip);
        $this->notify($form, $clean, $entryId);
        // Un envío bueno no deja nada de un intento anterior fallido.
        $this->app->session('customer')->forget(FormState::key($code));

        return $this->thanks($form, $back, $json);
    }

    // ------------------------------------------------------------------ respuestas

    /**
     * @param array<string,mixed> $form
     */
    private function thanks(array $form, string $back, bool $json): Response
    {
        if ($json) {
            return Response::json(['ok' => true, 'message' => $this->thanksText($form)]);
        }

        return Response::redirect($back . (strpos($back, '?') !== false ? '&' : '?') . 'enviado=1#form-' . $form['code']);
    }

    /**
     * @param array<string,mixed>  $form
     * @param array<string,string> $clean
     * @param array<string,string> $errors
     */
    private function invalid(array $form, array $clean, array $errors, string $back, bool $json): Response
    {
        if ($json) {
            return Response::json(['ok' => false, 'message' => t('Check the highlighted fields.'), 'errors' => $errors], 422);
        }
        // Sin JavaScript: se vuelve a la página y el bloque pinta los errores y lo que había escrito (solo en este caso se usa la sesión).
        $this->app->session('customer')->set(FormState::key((string) $form['code']), ['errors' => $errors, 'old' => $clean]);
        // La página siguiente pinta esos errores en el servidor: hasta que los lea, este visitante no recibe la copia de la caché.
        $this->app->pendingForNextPage(FormState::key((string) $form['code']));

        return Response::redirect($back . '#form-' . $form['code']);
    }

    /**
     * @param array<string,mixed> $form
     */
    private function thanksText(array $form): string
    {
        $text = isset($form['settings']['thanks']) && is_string($form['settings']['thanks']) ? trim($form['settings']['thanks']) : '';

        return t($text !== '' ? $text : self::THANKS);
    }

    // ------------------------------------------------------------------ interno

    /**
     * @param array<string,string> $params
     *
     * @return array<string,mixed>
     */
    private function form(array $params): array
    {
        $form = $this->cms()->formByCode(isset($params['code']) ? (string) $params['code'] : '');
        if ($form === null || $form['fields'] === []) {
            throw HttpError::notFound();
        }

        return $form;
    }

    /**
     * Dónde volver: la página desde la que se envió, si la manda el navegador y es de esta tienda; si no, la portada. Nunca un
     * destino de otra web ni uno que empiece por «//».
     */
    private function back(Request $request): string
    {
        $fallback = $this->app->url()->to('/');
        $referer = $request->header('referer');
        if ($referer === null || $referer === '') {
            return $fallback;
        }
        $host = strtolower((string) preg_replace('/:\d+$/', '', $request->host));
        if (!Url::isSafeRedirect($referer, $host)) {
            return $fallback;
        }
        $parts = parse_url($referer);
        if (!is_array($parts) || !isset($parts['path']) || strpos($parts['path'], '/formulario/') !== false) {
            return $fallback;
        }
        $query = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        unset($query['enviado']);

        return '/' . ltrim($parts['path'], '/') . ($query !== [] ? '?' . http_build_query($query) : '');
    }

    /**
     * Avisa a la tienda de un mensaje nuevo. El mensaje ya está guardado: si el correo falla, no se pierde nada y la persona no se entera.
     *
     * @param array<string,mixed>  $form
     * @param array<string,string> $clean
     */
    private function notify(array $form, array $clean, int $entryId): void
    {
        try {
            if (!$this->app->has('mailer') || $this->app->silenced()) {
                return;
            }
            $to = trim((string) $form['settings']['notify_to']);
            if ($to === '') {
                $to = trim($this->app->settings()->str('store.email', ''));
            }
            if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
                $this->app->log()->warning('A form message was saved but there is no address to notify', ['form' => (string) $form['code']]);

                return;
            }
            $replyTo = '';
            foreach ($form['fields'] as $field) {
                $key = (string) $field['key'];
                if ($field['type'] === 'email' && isset($clean[$key]) && filter_var($clean[$key], FILTER_VALIDATE_EMAIL) !== false) {
                    $replyTo = $clean[$key];
                    break;
                }
            }
            $entry = ['form_id' => (int) $form['id'], 'data' => json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
            $data = [
                'form' => (string) $form['name'],
                'fields' => $this->cms()->forms()->describe($entry),
                'entry_id' => $entryId,
                'entry_url' => $this->app->url()->abs('/admin/r/form_entries/' . $entryId, [], ''),
            ];
            $options = ['related' => 'form_entry:' . $entryId];
            if ($replyTo !== '') {
                $options['reply_to'] = $replyTo;
            }
            $this->app->service('mailer')->queue('form_entry', $to, $data, $options);
        } catch (\Throwable $e) {
            $this->app->log()->exception($e, 'cms.form');
        }
    }
}
