<?php
/**
 * AIFEC — servicio de correo: compone, guarda, retiene y envía los mensajes del sitio.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Mail;

use Aifec\Kernel\App;
use Aifec\Kernel\Clock;
use Aifec\Mail\MailException;
use Aifec\Mail\MailFunctionTransport;
use Aifec\Mail\MimeMessage;
use Aifec\Mail\SmtpTransport;
use Aifec\Mail\Transport;
use Aifec\Text\HtmlToMarkdown;

/**
 * Publicado como el servicio «mailer»:  $app->service('mailer')->queue('plantilla', $correo, $datos);
 *
 * Seguridad primero (ajuste mail.mode):
 *   hold      (por defecto) todo se guarda como «held» y no sale nada, salvo las plantillas del sistema
 *             (restablecer contraseña, prueba) si mail.system_always está encendido, y lo que una persona libera.
 *   live      se guarda como «pending» y se envía.
 *   redirect  se envía, pero todo va a mail.redirect_to con el destinatario real entre corchetes en el asunto.
 *
 * El modo se mira DOS veces: al guardar (estado inicial) y al enviar (lo que manda es el modo de ese momento).
 * Regla de las 24 horas: un «pending» de más de 24 h no se envía jamás, pasa a «held» con el error «expired».
 *
 * Reintentos: se cuenta el intento ANTES de enviar con una actualización condicional (si dos procesos coinciden,
 * solo uno lo envía) y, si falla, se guarda la hora del intento en el sobre de «data». Entre intentos espera
 * 5 min × intentos; al quinto fallo pasa a «failed».
 *
 * La columna «data» guarda un sobre JSON: {"v":1,"data":{…},"reply_to":"","attachments":[…],"approved":false,"last_attempt":null}.
 * «approved» lo pone una persona al liberar o reenviar: deja salir ese mensaje aunque el correo esté retenido.
 * El mensaje no lleva ninguna cabecera propia: todo el estado va en la base de datos.
 *
 * Textos editables: de cada plantilla se pueden cambiar, por idioma, el asunto, la entrada («saludo») y la despedida. Se guardan
 * en el ajuste «mail.texts» (plantilla → idioma → campo) y lo guardado gana al texto de serie; vacío, o igual al de serie, borra
 * lo guardado. Lo escrito por la tienda se escapa siempre, las {variables} desconocidas se dejan tal cual y el asunto se queda
 * en una línea. El cuerpo estructurado (tabla del pedido, botones, datos legales) lo pinta la plantilla y no se edita.
 * Una plantilla puede declarar en emails.php 'greeting' y 'farewell' (textos de serie, en inglés y con {variables}) para que
 * esos párrafos de su vista se sustituyan, y no se repitan, cuando la tienda escribe los suyos.
 */
final class Mailer
{
    /** Lo único que una demostración puede enviar, y solo por el buzón de la instalación (ver demoRelay). */
    private const DEMO_TEMPLATES = ['buyer_code'];

    public const MODE_HOLD = 'hold';
    public const MODE_LIVE = 'live';
    public const MODE_REDIRECT = 'redirect';

    public const MAX_ATTEMPTS = 5;
    public const RETRY_SECONDS = 300;
    public const EXPIRES_AFTER = 86400;

    /** Como mucho, cuántos reintentos inmediatos admite la opción «retries» de queue(). */
    public const MAX_QUICK_RETRIES = 3;

    /** Milisegundos de espera antes de cada reintento inmediato (se multiplica por el número de reintento). Las pruebas lo ponen a 0. */
    public static int $quickRetryPauseMs = 400;

    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    // ------------------------------------------------------------------ ajustes

    public function mode(): string
    {
        $mode = $this->app->settings()->str('mail.mode', self::MODE_HOLD);

        return in_array($mode, [self::MODE_HOLD, self::MODE_LIVE, self::MODE_REDIRECT], true) ? $mode : self::MODE_HOLD;
    }

    /**
     * Plantillas declaradas por los módulos cargados (emails.php): clave => definición normalizada.
     * El filtro «mail.templates» (array $definiciones, App $app) deja añadir o retocar plantillas por código.
     *
     * @return array<string,array<string,mixed>>
     */
    public function templates(): array
    {
        $raw = [];
        foreach ($this->app->modules()->definitions('emails') as $module => $definitions) {
            foreach ($definitions as $key => $def) {
                if (is_array($def)) {
                    $raw[(string) $key] = $def + ['module' => (string) $module];
                }
            }
        }
        $filtered = $this->app->hooks()->apply('mail.templates', $raw, $this->app);
        $out = [];
        foreach (is_array($filtered) ? $filtered : $raw as $key => $def) {
            if (!is_array($def) || !isset($def['subject']) || !isset($def['view'])) {
                continue;
            }
            $out[(string) $key] = [
                'key' => (string) $key,
                'module' => isset($def['module']) ? (string) $def['module'] : '',
                'subject' => (string) $def['subject'],
                'view' => (string) $def['view'],
                'system' => !empty($def['system']),
                // Lleva una credencial (enlace de un solo uso, código, clave): tras enviarse no se guarda su contenido en el registro.
                'sensitive' => !empty($def['sensitive']),
                'description' => isset($def['description']) ? (string) $def['description'] : '',
                'sample' => isset($def['sample']) && is_array($def['sample']) ? $def['sample'] : [],
                // Texto de serie de la entrada y de la despedida, en inglés y con {variables}, si la plantilla los declara.
                'greeting' => isset($def['greeting']) && is_string($def['greeting']) ? $def['greeting'] : '',
                'farewell' => isset($def['farewell']) && is_string($def['farewell']) ? $def['farewell'] : '',
            ];
        }
        ksort($out);

        return $out;
    }

    // ------------------------------------------------------------------ componer

    /**
     * Compone sin guardar ni enviar (vista previa y pruebas). El idioma de la aplicación se cambia al del
     * destinatario mientras se pinta y se restaura al terminar, también si la plantilla lanza.
     *
     * Los textos que la tienda ha editado (asunto, entrada y despedida de esa plantilla en ese idioma) mandan sobre los de serie.
     * Con $texts se componen con otros (la vista previa de lo que se está escribiendo, sin guardarlo).
     *
     * @param array<string,mixed>       $data
     * @param array<string,string>|null $texts subject, greeting y farewell; null = los guardados
     *
     * @return array{subject:string,html:string,text:string}
     */
    public function render(string $template, array $data, string $locale, ?array $texts = null): array
    {
        $def = $this->template($template);
        $locale = $this->cleanLocale($locale);
        $texts = $texts !== null ? $this->cleanTexts($texts) : $this->texts($template, $locale);
        $i18n = $this->app->i18n();
        $previous = $i18n->locale();
        $i18n->setLocale($locale);
        try {
            $settings = $this->app->settings();
            $shop = $settings->str('store.name', 'AIFEC');
            $vars = [];
            foreach ($data as $key => $value) {
                if (is_scalar($value)) {
                    $vars[(string) $key] = $value;
                }
            }
            $vars['shop'] = $shop;
            $subject = $this->oneLine($texts['subject'] !== '' ? $this->fill($texts['subject'], $vars) : $i18n->t((string) $def['subject'], $vars, $locale));

            $viewData = ['shop' => $shop, 'shopUrl' => $this->app->url()->abs('/'), 'locale' => $locale] + $data;
            $view = $this->app->view();
            $body = $this->withTexts($view->render((string) $def['view'], $viewData), $texts, $def, $vars, $locale);
            $legalName = $settings->str('store.legal_name');
            $address = $settings->str('store.address');
            $html = $view->render('mail::emails/layout', $viewData + [
                'content' => $body, 'subject' => $subject, 'legalName' => $legalName, 'address' => $address,
            ]);

            $textView = (string) $def['view'] . '_text';
            if ($view->exists($textView)) {
                // La versión de texto propia de la plantilla no pasa por el cuerpo en HTML: la entrada y la despedida se ponen aquí.
                $text = trim($view->render($textView, $viewData));
                if ($texts['greeting'] !== '') {
                    $text = $this->fill($texts['greeting'], $vars) . "\n\n" . $text;
                }
                if ($texts['farewell'] !== '') {
                    $text .= "\n\n" . $this->fill($texts['farewell'], $vars);
                }
            } else {
                $text = HtmlToMarkdown::convert($body);
            }
            $footer = trim($legalName . "\n" . $address);
            if ($footer !== '') {
                $text .= "\n\n-- \n" . $footer;
            }
        } finally {
            $i18n->setLocale($previous);
        }

        return ['subject' => $subject, 'html' => $html, 'text' => $text];
    }

    // ------------------------------------------------------------------ cola

    /**
     * Compone el correo AHORA (con el idioma del destinatario), lo guarda y devuelve su id en mail_messages.
     *
     * $options: locale (por defecto el idioma principal del sitio), to_name, related ('order:15'), now (true = intentar
     * el envío en esta misma petición), reply_to, attachments ([['name' => 'factura.pdf', 'content' => $bytes, 'mime' => 'application/pdf']]),
     * approved (true = lo ha pedido una persona: sale aunque el correo esté retenido, como lo que se libera), texts (otros textos
     * editables en vez de los guardados: subject, greeting y farewell; lo usa «Enviarme una prueba»).
     *
     * @param array<string,mixed> $data
     * @param array<string,mixed> $options
     *
     * Con la aplicación en modo silencioso (App::silence(), lo usan las migraciones) no hace nada y devuelve 0.
     *
     * @throws \InvalidArgumentException plantilla desconocida o dirección inválida (no se guarda nada)
     */
    public function queue(string $template, string $toEmail, array $data = [], array $options = []): int
    {
        if ($this->app->silenced()) {
            // Modo silencioso (una migración en curso): no se compone, no se guarda y no se envía. Devuelve 0.
            return 0;
        }
        $def = $this->template($template);
        $toEmail = trim($toEmail);
        if (filter_var($toEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Invalid recipient address');
        }
        $replyTo = isset($options['reply_to']) && is_string($options['reply_to']) ? trim($options['reply_to']) : '';
        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Invalid reply-to address');
        }
        $attachments = $this->cleanAttachments(isset($options['attachments']) && is_array($options['attachments']) ? $options['attachments'] : []);
        $locale = $this->cleanLocale(isset($options['locale']) && is_string($options['locale']) ? $options['locale'] : $this->app->defaultLang());

        $rendered = $this->render($template, $data, $locale, isset($options['texts']) && is_array($options['texts']) ? $options['texts'] : null);

        $approved = !empty($options['approved']);
        $reason = $this->blockReason((bool) $def['system'], $approved, $template);
        $envelope = ['v' => 1, 'data' => $data, 'reply_to' => $replyTo, 'attachments' => $attachments, 'approved' => $approved, 'last_attempt' => null];
        $row = [
            'to_email' => $toEmail,
            'subject' => mb_substr($rendered['subject'], 0, 191),
            'template' => substr($template, 0, 80),
            'locale' => $locale,
            'data' => $this->encode($envelope),
            'html' => $rendered['html'],
            'text_body' => $rendered['text'],
            'status' => $reason === null ? 'pending' : 'held',
            'attempts' => 0,
            'created_at' => $this->app->clock()->sql(),
        ];
        if ($reason !== null) {
            $row['error'] = $reason;
        }
        $name = isset($options['to_name']) && is_string($options['to_name']) ? trim($options['to_name']) : '';
        if ($name !== '') {
            $row['to_name'] = mb_substr($this->oneLine($name), 0, 120);
        }
        if (isset($options['related']) && is_string($options['related']) && $options['related'] !== '') {
            $row['related'] = mb_substr($options['related'], 0, 60);
        }
        $id = $this->app->db()->insert('mail_messages', $row);

        if ($reason === null && !empty($options['now'])) {
            $this->attempt($id, self::MAX_ATTEMPTS);
            $retries = isset($options['retries']) ? max(0, min(self::MAX_QUICK_RETRIES, (int) $options['retries'])) : 0;
            if ($retries > 0) {
                $this->quickRetries($id, $retries);
            }
        }

        return $id;
    }

    /**
     * Un mensaje pendiente que ya no tiene sentido enviar (un código que la tienda ha dicho que no pudo mandar): pasa a
     * «failed» y la cola no lo reintenta. Devuelve si lo ha retirado.
     */
    public function cancel(int $id): bool
    {
        return $this->app->db()->run("UPDATE {mail_messages} SET status = 'failed' WHERE id = ? AND status = 'pending'", [$id]) === 1;
    }

    /**
     * Reintentos inmediatos de un mensaje recién guardado cuyo primer intento ha fallado. Un fallo pasajero (un DNS que no
     * contesta, una conexión que se cae) dejaba cinco minutos en cola un correo que caduca, como el código del comprador,
     * mientras la tienda decía que lo había enviado (16/11/2026). No cuentan como intentos de la cola: si tampoco salen, el
     * mensaje sigue pendiente con su error y lo decide quien lo pidió. Nunca rompe a quien llama.
     */
    private function quickRetries(int $id, int $times): void
    {
        $db = $this->app->db();
        for ($i = 1; $i <= $times; ++$i) {
            try {
                $row = $db->row('SELECT * FROM {mail_messages} WHERE id = ?', [$id]);
                if ($row === null || (string) $row['status'] !== 'pending' || (int) $row['attempts'] < 1) {
                    return; // ya salió, o ni siquiera se intentó (no toca enviarlo)
                }
                if (self::$quickRetryPauseMs > 0) {
                    usleep(self::$quickRetryPauseMs * 1000 * $i);
                }
                $this->transport()->send($this->compose($row, $this->envelope($row), $this->app->clock()->now()));
            } catch (\Throwable $e) {
                $db->update('mail_messages', ['error' => $this->cleanError($e->getMessage())], ['id' => $id]);
                continue;
            }
            $db->run("UPDATE {mail_messages} SET status = 'sent', sent_at = ?, error = NULL WHERE id = ? AND status = 'pending'", [$this->app->clock()->sql(), $id]);
            $this->redactIfSensitive($id);

            return;
        }
    }

    /**
     * Envía lo pendiente, lo más antiguo primero. «failed» cuenta los intentos que han fallado en esta pasada.
     *
     * @return array{sent:int,failed:int,held:int}
     */
    public function sendPending(int $max = 20): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'held' => 0];
        if ($max < 1) {
            return $result;
        }
        $rows = $this->app->db()->select(
            "SELECT * FROM {mail_messages} WHERE status = 'pending' ORDER BY created_at ASC, id ASC LIMIT ?",
            [$max * 4 + 50]
        );
        $handled = 0;
        foreach ($rows as $row) {
            if ($handled >= $max) {
                break;
            }
            $outcome = $this->process($row, self::MAX_ATTEMPTS);
            if ($outcome === 'sent') {
                ++$result['sent'];
                ++$handled;
            } elseif ($outcome === 'failed') {
                ++$result['failed'];
                ++$handled;
            } elseif ($outcome === 'held') {
                ++$result['held'];
            }
        }

        return $result;
    }

    /** Retenido → pendiente. Lo pulsa una persona, de uno en uno; no vale para un mensaje de más de 24 horas. */
    public function release(int $id): bool
    {
        $row = $this->app->db()->row('SELECT * FROM {mail_messages} WHERE id = ?', [$id]);
        if ($row === null || (string) $row['status'] !== 'held' || $this->isExpired($row)) {
            return false;
        }
        $envelope = $this->envelope($row);
        $envelope['approved'] = true;
        $done = $this->app->db()->run(
            "UPDATE {mail_messages} SET status = 'pending', error = NULL, data = ? WHERE id = ? AND status = 'held'",
            [$this->encode($envelope), $id]
        );

        return $done === 1;
    }

    /** Copia un mensaje ya enviado o fallido como uno nuevo pendiente (aprobado por quien lo pide). 0 si no se puede. */
    public function resend(int $id): int
    {
        $row = $this->app->db()->row('SELECT * FROM {mail_messages} WHERE id = ?', [$id]);
        if ($row === null || !in_array((string) $row['status'], ['sent', 'failed'], true)) {
            return 0;
        }
        if ((string) $row['html'] === '' && (string) $row['text_body'] === '') {
            return 0; // el contenido ya se vació
        }
        $envelope = $this->envelope($row);
        $envelope['approved'] = true;
        $envelope['last_attempt'] = null;
        $copy = [
            'to_email' => (string) $row['to_email'],
            'subject' => (string) $row['subject'],
            'template' => (string) $row['template'],
            'locale' => (string) $row['locale'],
            'data' => $this->encode($envelope),
            'html' => (string) $row['html'],
            'text_body' => (string) $row['text_body'],
            'status' => 'pending',
            'attempts' => 0,
            'created_at' => $this->app->clock()->sql(),
        ];
        foreach (['to_name', 'related'] as $column) {
            if ($row[$column] !== null && $row[$column] !== '') {
                $copy[$column] = (string) $row[$column];
            }
        }

        return $this->app->db()->insert('mail_messages', $copy);
    }

    /** Manda la plantilla «mail_test» de inmediato. Devuelve null si salió, o el texto del problema. */
    public function sendTest(string $toEmail): ?string
    {
        try {
            $id = $this->queue('mail_test', $toEmail);
        } catch (\InvalidArgumentException $e) {
            return t('That email address is not valid');
        }

        return $this->deliverTest($id);
    }

    /**
     * «Enviarme una prueba» de una plantilla: sale con los datos de ejemplo, con los textos que se están escribiendo (o los
     * guardados si $texts es null) y aunque el correo esté retenido: lo pide una persona, como cuando libera un mensaje.
     * Devuelve null si salió, o el texto del problema.
     *
     * @param array<string,string>|null $texts
     */
    public function sendTemplateTest(string $template, string $toEmail, string $locale, ?array $texts = null): ?string
    {
        $def = $this->template($template);
        try {
            $id = $this->queue($template, $toEmail, $def['sample'], ['locale' => $locale, 'approved' => true, 'texts' => $texts]);
        } catch (\InvalidArgumentException $e) {
            return t('That email address is not valid');
        }

        return $this->deliverTest($id);
    }

    // ------------------------------------------------------------------ textos editables

    /**
     * Los textos que la tienda ha guardado para una plantilla en un idioma. Siempre trae las tres claves; vacío = el de serie.
     *
     * @return array{subject:string,greeting:string,farewell:string}
     */
    public function texts(string $template, string $locale): array
    {
        $saved = $this->app->settings()->arr('mail.texts');
        $row = isset($saved[$template]) && is_array($saved[$template]) && isset($saved[$template][$locale]) && is_array($saved[$template][$locale]) ? $saved[$template][$locale] : [];

        return $this->cleanTexts($row);
    }

    /**
     * Los textos de serie de una plantilla en un idioma, ya traducidos y con las {variables} sin rellenar. La entrada de serie es
     * la de la plantilla si la declara; si no, «Hola {name}» cuando la plantilla recibe un nombre; si no, ninguna. La despedida
     * de serie es la que declara la plantilla (y, si no declara, ninguna: se puede añadir una).
     *
     * @return array{subject:string,greeting:string,farewell:string}
     */
    public function defaults(string $template, string $locale): array
    {
        $def = $this->template($template);
        $locale = $this->cleanLocale($locale);
        $i18n = $this->app->i18n();
        $greeting = $this->serieGreeting($def, $this->variables($template));
        $farewell = (string) $def['farewell'];

        return [
            'subject' => $i18n->t((string) $def['subject'], [], $locale),
            'greeting' => $greeting !== '' ? $i18n->t($greeting, [], $locale) : '',
            'farewell' => $farewell !== '' ? $i18n->t($farewell, [], $locale) : '',
        ];
    }

    /**
     * Nombres de las variables que se pueden escribir entre llaves en los textos de una plantilla: «shop» y los datos sueltos
     * (texto o número) que la plantilla recibe. Los datos con estructura (el pedido, las líneas) no se pueden poner entre llaves.
     *
     * @return string[]
     */
    public function variables(string $template): array
    {
        $def = $this->template($template);
        $names = ['shop'];
        foreach ($def['sample'] as $key => $value) {
            if (is_scalar($value) && preg_match('/^[A-Za-z0-9_]+$/', (string) $key) === 1 && (string) $key !== 'shop') {
                $names[] = (string) $key;
            }
        }

        return $names;
    }

    /**
     * Guarda los textos de una plantilla en un idioma. Un campo vacío, o igual al de serie, borra lo guardado: vuelve el de serie.
     * Devuelve las variables que no existen en esa plantilla (se dejan escritas tal cual en el correo).
     *
     * @param array<string,string> $texts subject, greeting y farewell
     *
     * @return string[]
     */
    public function saveTexts(string $template, string $locale, array $texts): array
    {
        $locale = $this->cleanLocale($locale);
        $clean = $this->cleanTexts($texts);
        $defaults = $this->defaults($template, $locale);
        $keep = [];
        foreach ($clean as $field => $value) {
            if ($value !== '' && $value !== trim($defaults[$field])) {
                $keep[$field] = $value;
            }
        }
        $this->storeTexts($template, $locale, $keep);

        return $this->unknownVariables($clean, $this->variables($template));
    }

    /** «Volver al texto original» de un campo (subject, greeting o farewell): borra lo guardado de ese campo. */
    public function resetText(string $template, string $locale, string $field): void
    {
        $this->template($template);
        $locale = $this->cleanLocale($locale);
        $current = $this->texts($template, $locale);
        if (!array_key_exists($field, $current)) {
            return;
        }
        $current[$field] = '';
        $this->storeTexts($template, $locale, array_filter($current, static function (string $value): bool {
            return $value !== '';
        }));
    }

    /**
     * Las {variables} escritas en los textos que esa plantilla no tiene.
     *
     * @param array<string,string> $texts
     * @param string[]             $allowed
     *
     * @return string[]
     */
    public function unknownVariables(array $texts, array $allowed): array
    {
        $unknown = [];
        foreach ($texts as $text) {
            if (preg_match_all('/\{([^{}\s]+)\}/u', (string) $text, $found) > 0) {
                foreach ($found[1] as $name) {
                    if (!in_array($name, $allowed, true) && !in_array($name, $unknown, true)) {
                        $unknown[] = $name;
                    }
                }
            }
        }

        return $unknown;
    }

    /**
     * Los tres textos de una fila con la misma limpieza que al guardar (asunto en una línea y con tope, sin lo que no sea de los tres
     * campos). Lo usa la ayuda de la IA para dejar una propuesta con la forma de un texto guardado.
     *
     * @param array<string,mixed> $row
     *
     * @return array{subject:string,greeting:string,farewell:string}
     */
    public function normalizeTexts(array $row): array
    {
        return $this->cleanTexts($row);
    }

    /** @param array<string,string> $keep solo los campos que se quedan */
    private function storeTexts(string $template, string $locale, array $keep): void
    {
        $saved = $this->app->settings()->arr('mail.texts');
        if ($keep === []) {
            unset($saved[$template][$locale]);
            if (isset($saved[$template]) && $saved[$template] === []) {
                unset($saved[$template]);
            }
        } else {
            $saved[$template][$locale] = $keep;
        }
        $this->app->settings()->set('mail.texts', $saved);
    }

    /**
     * Un texto de entrada limpio: sin lo que no sea de los tres campos, sin caracteres de control (el asunto en una línea) y con un tope.
     *
     * @param array<string,mixed> $row
     *
     * @return array{subject:string,greeting:string,farewell:string}
     */
    private function cleanTexts(array $row): array
    {
        $out = ['subject' => '', 'greeting' => '', 'farewell' => ''];
        foreach ($out as $field => $unused) {
            $value = isset($row[$field]) && is_string($row[$field]) ? str_replace("\r\n", "\n", $row[$field]) : '';
            $value = $field === 'subject' ? mb_substr($this->oneLine($value), 0, 191) : mb_substr(trim($value), 0, 2000);
            $out[$field] = $value;
        }

        return $out;
    }

    /** Rellena las {variables} que existen y deja las demás escritas tal cual. */
    private function fill(string $text, array $vars): string
    {
        $replace = [];
        foreach ($vars as $key => $value) {
            $replace['{' . $key . '}'] = (string) $value;
        }

        return strtr($text, $replace);
    }

    /**
     * Pone la entrada y la despedida editadas alrededor del cuerpo que pinta la plantilla. Si el cuerpo ya empieza (o acaba) con el
     * párrafo de serie, ese párrafo se quita para que no salga dos veces. Todo lo escrito por la tienda se escapa.
     *
     * @param array{subject:string,greeting:string,farewell:string} $texts
     * @param array<string,mixed>                                   $def
     * @param array<string,mixed>                                   $vars
     */
    private function withTexts(string $body, array $texts, array $def, array $vars, string $locale): string
    {
        $i18n = $this->app->i18n();
        if ($texts['greeting'] !== '') {
            $serie = $this->serieGreeting($def, array_keys($vars));
            if ($serie !== '') {
                $body = (string) preg_replace('#^\s*<p\b[^>]*>' . preg_quote(e($i18n->t($serie, $vars, $locale)), '#') . '</p>\s*#u', '', $body, 1);
            }
            $body = '<p style="margin:0 0 16px 0;">' . nl2br(e($this->fill($texts['greeting'], $vars)), false) . "</p>\n" . ltrim($body);
        }
        if ($texts['farewell'] !== '') {
            $serie = (string) $def['farewell'];
            if ($serie !== '') {
                $body = (string) preg_replace('#\s*<p\b[^>]*>' . preg_quote(e($i18n->t($serie, $vars, $locale)), '#') . '</p>\s*$#u', '', $body, 1);
            }
            $body = rtrim($body) . "\n" . '<p style="margin:16px 0 0 0;">' . nl2br(e($this->fill($texts['farewell'], $vars)), false) . '</p>';
        }

        return $body;
    }

    /**
     * El texto de entrada de serie (en inglés, con {variables}): el que declara la plantilla o «Hello {name}» si recibe un nombre.
     *
     * @param array<string,mixed> $def
     * @param string[]            $names nombres de las variables que recibe
     */
    private function serieGreeting(array $def, array $names): string
    {
        if ((string) $def['greeting'] !== '') {
            return (string) $def['greeting'];
        }

        return in_array('name', $names, true) ? 'Hello {name}' : '';
    }

    /** Envía de una vez un mensaje de prueba recién guardado y dice si salió (null) o por qué no. */
    private function deliverTest(int $id): ?string
    {
        $row = $this->app->db()->row('SELECT * FROM {mail_messages} WHERE id = ?', [$id]);
        if ($row !== null && (string) $row['status'] === 'pending') {
            try {
                $this->process($row, 1); // una sola vez: un correo de prueba no se reintenta solo
            } catch (\Throwable $e) {
                $this->app->log()->exception($e, 'mail');

                return t('The test email could not be sent');
            }
            $row = $this->app->db()->row('SELECT * FROM {mail_messages} WHERE id = ?', [$id]);
        }
        if ($row === null) {
            return t('The test email could not be sent');
        }
        if ((string) $row['status'] === 'sent') {
            return null;
        }
        if ((string) $row['status'] === 'held') {
            return $this->reasonText((string) $row['error']);
        }

        return (string) $row['error'] !== '' ? (string) $row['error'] : t('The test email could not be sent');
    }

    // ------------------------------------------------------------------ ayudas públicas

    /** Texto (traducido) de por qué un mensaje está retenido. */
    public function reasonText(string $code): string
    {
        switch ($code) {
            case 'hold':
                return t('Email is on hold: nothing is being sent');
            case 'expired':
                return t('Too old to send: more than 24 hours passed');
            case 'redirect_missing':
                return t('Test mode needs an address to send everything to');
            default:
                return $code;
        }
    }

    /** ¿Tiene el mensaje más de 24 horas? */
    public function isExpired(array $row): bool
    {
        return $this->app->clock()->now() - Clock::parse((string) $row['created_at']) > self::EXPIRES_AFTER;
    }

    /**
     * El sobre guardado en «data» (siempre con todas las claves, también si el contenido ya se vació).
     *
     * @return array{v:int,data:array<string,mixed>,reply_to:string,attachments:array<int,array<string,string>>,approved:bool,last_attempt:?int}
     */
    public function envelope(array $row): array
    {
        $decoded = json_decode(isset($row['data']) ? (string) $row['data'] : '', true);
        $decoded = is_array($decoded) ? $decoded : [];
        if (!isset($decoded['v'])) {
            $decoded = ['data' => $decoded]; // formato sin sobre: solo los datos
        }

        return [
            'v' => 1,
            'data' => isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : [],
            'reply_to' => isset($decoded['reply_to']) && is_string($decoded['reply_to']) ? $decoded['reply_to'] : '',
            'attachments' => isset($decoded['attachments']) && is_array($decoded['attachments']) ? $decoded['attachments'] : [],
            'approved' => !empty($decoded['approved']),
            'last_attempt' => isset($decoded['last_attempt']) && is_int($decoded['last_attempt']) ? $decoded['last_attempt'] : null,
        ];
    }

    // ------------------------------------------------------------------ envío

    /**
     * Intenta enviar un mensaje pendiente.
     *
     * @param array<string,mixed> $row
     *
     * @return string sent | failed (el intento falló) | held (caducado) | skipped (todavía no toca o no se debe)
     */
    private function process(array $row, int $maxAttempts): string
    {
        $db = $this->app->db();
        $id = (int) $row['id'];
        $now = $this->app->clock()->now();

        if ($this->isExpired($row)) {
            $moved = $db->run("UPDATE {mail_messages} SET status = 'held', error = ? WHERE id = ? AND status = 'pending'", ['expired', $id]);

            return $moved === 1 ? 'held' : 'skipped';
        }

        $envelope = $this->envelope($row);
        $templates = $this->templates();
        $system = isset($templates[(string) $row['template']]) && !empty($templates[(string) $row['template']]['system']);
        if ($this->blockReason($system, $envelope['approved'], (string) $row['template']) !== null) {
            return 'skipped';
        }
        $attempts = (int) $row['attempts'];
        if ($attempts > 0 && $envelope['last_attempt'] !== null && $envelope['last_attempt'] + self::RETRY_SECONDS * $attempts > $now) {
            return 'skipped';
        }
        // Se reclama el intento: si otro proceso lo cogió antes, esta actualización no cambia ninguna fila.
        $claimed = $db->run(
            "UPDATE {mail_messages} SET attempts = attempts + 1 WHERE id = ? AND status = 'pending' AND attempts = ?",
            [$id, $attempts]
        );
        if ($claimed !== 1) {
            return 'skipped';
        }
        ++$attempts;

        try {
            $this->transport()->send($this->compose($row, $envelope, $now));
        } catch (\Throwable $e) {
            $final = $attempts >= $maxAttempts;
            $envelope['last_attempt'] = $now;
            $db->update('mail_messages', [
                'status' => $final ? 'failed' : 'pending',
                'error' => $this->cleanError($e->getMessage()),
                'data' => $this->encode($envelope),
            ], ['id' => $id]);
            if ($final) {
                $this->redactIfSensitive($id);
            }

            return 'failed';
        }
        $db->update('mail_messages', ['status' => 'sent', 'sent_at' => $this->app->clock()->sql(), 'error' => null], ['id' => $id]);
        $this->redactIfSensitive($id);

        return 'sent';
    }

    /** Si la plantilla del mensaje lleva una credencial. */
    public function isSensitive(string $template): bool
    {
        $templates = $this->templates();

        return isset($templates[$template]) && !empty($templates[$template]['sensitive']);
    }

    /**
     * Un mensaje con una credencial (enlace de un solo uso, código, clave) ya enviado, o fallado del todo, deja de guardar sus
     * datos y su contenido: el registro lo ve quien tiene «mail.view», y una copia de la base de datos también. Se conserva el
     * resto (destinatario, asunto, estado, fechas). Nunca rompe a quien llama.
     */
    private function redactIfSensitive(int $id): void
    {
        try {
            $row = $this->app->db()->row('SELECT template, data FROM {mail_messages} WHERE id = ?', [$id]);
            if ($row === null || !$this->isSensitive((string) $row['template'])) {
                return;
            }
            $envelope = $this->envelope($row);
            $envelope['data'] = [];
            $envelope['attachments'] = [];
            $this->app->db()->update('mail_messages', ['data' => $this->encode($envelope), 'html' => '', 'text_body' => ''], ['id' => $id]);
        } catch (\Throwable $e) {
            $this->app->log()->exception($e, 'mail');
        }
    }

    /**
     * Borra del registro los mensajes de una persona: los enviados a su correo y los ligados a su ficha («customer:<id>»).
     * Lo usa el anonimizado de clientes. Devuelve cuántos ha borrado. Un correo vacío no borra por correo.
     */
    public function forget(string $email, string $related = ''): int
    {
        $db = $this->app->db();
        $email = strtolower(trim($email));
        $deleted = 0;
        if ($email !== '') {
            $deleted += $db->run('DELETE FROM {mail_messages} WHERE LOWER(to_email) = ?', [$email]);
        }
        if ($related !== '') {
            $deleted += $db->run('DELETE FROM {mail_messages} WHERE related = ?', [$related]);
        }

        return $deleted;
    }

    /** Envío inmediato de un mensaje recién guardado. Un fallo se anota; nunca rompe a quien llama. */
    private function attempt(int $id, int $maxAttempts): void
    {
        try {
            $row = $this->app->db()->row('SELECT * FROM {mail_messages} WHERE id = ?', [$id]);
            if ($row !== null && (string) $row['status'] === 'pending') {
                $this->process($row, $maxAttempts);
            }
        } catch (\Throwable $e) {
            $this->app->log()->exception($e, 'mail');
        }
    }

    /**
     * Qué impide enviar ahora: null si puede salir, o el motivo («hold», «redirect_missing»).
     *
     * @param bool $system   plantilla del sistema (sale aunque el correo esté retenido, si mail.system_always)
     * @param bool $approved una persona lo ha liberado o reenviado
     */
    private function blockReason(bool $system, bool $approved, string $template = ''): ?string
    {
        // Una demostración no envía correo externo, aunque alguien cambie los ajustes o libere una prueba. La única salvedad es
        // el código con el que el comprador confirma un pedido hecho por un asistente, y solo si la instalación le ha puesto
        // un buzón para eso (demoRelay): sin él, la compra por asistentes no se podría probar en una demo.
        if ($this->app->site()->isDemo()) {
            return in_array($template, self::DEMO_TEMPLATES, true) && $this->demoRelay() !== null ? null : 'hold';
        }
        $mode = $this->mode();
        if ($mode === self::MODE_REDIRECT && $this->redirectTo() === '') {
            return 'redirect_missing';
        }
        if ($mode === self::MODE_HOLD && !$approved && !($system && $this->app->settings()->bool('mail.system_always', true))) {
            return 'hold';
        }

        return null;
    }

    /**
     * El buzón por el que una demostración manda lo poco que puede mandar. No sale de los ajustes (que en una demo pública
     * los puede tocar cualquiera) sino del config.php del sitio, que solo escribe quien administra la instalación:
     * 'code_mail' => ['host' => …, 'port' => 465, 'encryption' => 'ssl', 'user' => …, 'password' => …, 'from' => …].
     *
     * @return array<string,mixed>|null
     */
    private function demoRelay(): ?array
    {
        $relay = $this->app->site()->get('code_mail');
        if (!is_array($relay) || empty($relay['host']) || !isset($relay['from']) || filter_var((string) $relay['from'], FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $relay;
    }

    private function redirectTo(): string
    {
        $to = trim($this->app->settings()->str('mail.redirect_to'));

        return $to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL) !== false ? $to : '';
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $envelope
     */
    private function compose(array $row, array $envelope, int $now): MimeMessage
    {
        $settings = $this->app->settings();
        $to = (string) $row['to_email'];
        $toName = isset($row['to_name']) ? (string) $row['to_name'] : '';
        $subject = (string) $row['subject'];
        if ($this->mode() === self::MODE_REDIRECT) {
            $subject = '[' . $to . '] ' . $subject;
            $to = $this->redirectTo();
            $toName = '';
        }
        list($fromEmail, $fromName) = $this->sender();
        $message = (new MimeMessage())->from($fromEmail, $fromName)->to($to, $this->oneLine($toName))->subject($this->oneLine($subject))->date($now);
        if ((string) $row['html'] !== '') {
            $message->html((string) $row['html']);
        }
        $message->text(isset($row['text_body']) ? (string) $row['text_body'] : '');
        $replyTo = (string) $envelope['reply_to'];
        if ($replyTo === '') {
            $replyTo = trim($settings->str('mail.reply_to'));
        }
        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL) !== false) {
            $message->replyTo($replyTo);
        }
        foreach ($envelope['attachments'] as $file) {
            $content = is_array($file) && isset($file['content']) ? base64_decode((string) $file['content'], true) : false;
            if (!is_array($file) || !isset($file['name']) || $content === false) {
                throw new MailException('An attachment is not readable');
            }
            $message->attach((string) $file['name'], $content, isset($file['mime']) ? (string) $file['mime'] : 'application/octet-stream');
        }

        return $message;
    }

    /** @return array{0:string,1:string} correo y nombre del remitente */
    private function sender(): array
    {
        $settings = $this->app->settings();
        $relay = $this->app->site()->isDemo() ? $this->demoRelay() : null;
        $email = $relay !== null ? (string) $relay['from'] : trim($settings->str('mail.from_email'));
        if ($email === '') {
            $email = trim($settings->str('store.email'));
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $host = parse_url($this->app->url()->origin(), PHP_URL_HOST);
            $email = 'noreply@' . (is_string($host) && $host !== '' ? $host : 'localhost');
        }
        $name = trim($settings->str('mail.from_name'));
        if ($name === '') {
            $name = trim($settings->str('store.name'));
        }

        return [$email, $this->oneLine($name)];
    }

    private function transport(): Transport
    {
        $app = $this->app;
        if ($app->has('mail.transport')) {
            $service = $app->service('mail.transport');
            if (!$service instanceof Transport) {
                throw new MailException('The mail.transport service is not a mail transport');
            }

            return $service;
        }
        if ($app->site()->isDemo()) {
            // Una demo solo llega aquí con el código del comprador (blockReason): sale por el buzón de la instalación.
            $relay = $this->demoRelay();
            if ($relay === null) {
                throw new MailException('A demo does not send email');
            }
            $modes = ['tls' => 'starttls', 'ssl' => 'tls', 'none' => 'none'];
            $mode = isset($relay['encryption']) ? (string) $relay['encryption'] : 'ssl';

            return new SmtpTransport((string) $relay['host'], isset($relay['port']) ? (int) $relay['port'] : 465, isset($modes[$mode]) ? $modes[$mode] : 'tls',
                isset($relay['user']) ? (string) $relay['user'] : '', isset($relay['password']) ? (string) $relay['password'] : '', 15.0);
        }
        $settings = $app->settings();
        switch ($settings->str('mail.transport', 'mail')) {
            case 'log':
                return new LogTransport($app->site()->path('mail', true), $app->clock());
            case 'smtp':
                $host = trim($settings->str('mail.smtp_host'));
                if ($host === '') {
                    throw new MailException('The SMTP server is not set');
                }
                $encryption = ['tls' => 'starttls', 'ssl' => 'tls', 'none' => 'none'];
                $chosen = $settings->str('mail.smtp_encryption', 'tls');

                return new SmtpTransport(
                    $host,
                    $settings->int('mail.smtp_port', 587),
                    isset($encryption[$chosen]) ? $encryption[$chosen] : 'starttls',
                    $settings->str('mail.smtp_user'),
                    $app->secret('mail.smtp_password'),
                    (float) max(3, $settings->int('mail.smtp_timeout', 15))
                );
            default:
                return new MailFunctionTransport();
        }
    }

    // ------------------------------------------------------------------ piezas

    /** @return array<string,mixed> */
    private function template(string $key): array
    {
        $all = $this->templates();
        if (!isset($all[$key])) {
            throw new \InvalidArgumentException('Unknown email template: ' . $key);
        }

        return $all[$key];
    }

    private function cleanLocale(string $locale): string
    {
        $lang = strtolower(substr(trim($locale), 0, 2));

        return preg_match('/^[a-z]{2}$/', $lang) === 1 ? $lang : $this->app->defaultLang();
    }

    /** Una sola línea, sin caracteres de control: vale para un asunto o un nombre. */
    private function oneLine(string $text): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text);
        $clean = is_string($clean) ? $clean : $text;

        return trim($clean);
    }

    /**
     * @param array<int,mixed> $files
     *
     * @return array<int,array{name:string,mime:string,content:string}> con el contenido en base64
     */
    private function cleanAttachments(array $files): array
    {
        $out = [];
        foreach ($files as $file) {
            if (!is_array($file) || !isset($file['name']) || !is_string($file['name']) || trim($file['name']) === '' || !isset($file['content']) || !is_string($file['content'])) {
                throw new \InvalidArgumentException('Each attachment needs a name and its content');
            }
            $mime = isset($file['mime']) && is_string($file['mime']) && $file['mime'] !== '' ? $file['mime'] : 'application/octet-stream';
            $out[] = ['name' => $this->oneLine($file['name']), 'mime' => $mime, 'content' => base64_encode($file['content'])];
        }

        return $out;
    }

    /** @param array<string,mixed> $envelope */
    private function encode(array $envelope): string
    {
        $json = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);

        return is_string($json) ? $json : '{"v":1}';
    }

    /** El texto de un fallo, sin contraseñas y de tamaño razonable. */
    private function cleanError(string $message): string
    {
        $relay = $this->demoRelay();
        foreach ([$this->app->secret('mail.smtp_password'), $relay !== null && isset($relay['password']) ? (string) $relay['password'] : ''] as $password) {
            if ($password !== '') {
                $message = str_replace([$password, base64_encode($password)], '***', $message);
            }
        }
        $message = preg_replace('/\b(password|passwd|pass|pwd)(\s*[=:]\s*)\S+/i', '$1$2***', $message);
        $message = $this->oneLine(is_string($message) ? $message : '');

        return mb_substr($message !== '' ? $message : 'Unknown error', 0, 500);
    }
}
