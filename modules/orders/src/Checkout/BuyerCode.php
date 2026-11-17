<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Orders\Checkout;

use Aifec\Http\HttpRequest;
use Aifec\Kernel\App;
use Aifec\Kernel\RateLimiter;

/**
 * El código con el que la PERSONA confirma un pedido que le prepara un programa (un asistente de IA por la API o el MCP).
 *
 * Un programa puede declarar «la persona ha dicho que sí», pero la tienda no ve su pantalla. Con el ajuste «Pedidos hechos por
 * agentes» en «código» (api.agent_orders = code), la tienda lo comprueba por un canal suyo: al revisar el pedido manda al
 * comprador un código de seis cifras con el total, la persona se lo dice al asistente y el pedido solo se hace si llega ese
 * código. Así cualquier asistente puede comprar sin clave, y ninguno sin la persona.
 *
 *   - Canales: siempre el correo del comprador y, si la tienda lo enciende (api.code_whatsapp), también un WhatsApp a su
 *     teléfono, por uno de dos sistemas (api.whatsapp_provider): una pasarela de texto como «mensajero» (api.whatsapp_url y
 *     api.whatsapp_token) o la API oficial de Meta por 360dialog, con una plantilla aprobada. Basta con que salga por uno.
 *   - El código sale de la ficha de confirmación (confirm_token) y de la clave del sitio: si el pedido cambia, cambia la ficha y el
 *     código anterior deja de valer. No se guarda en ninguna tabla.
 *   - Vale WINDOW segundos como poco (se acepta también el del tramo anterior).
 *   - Topes: un envío por ficha y tramo, PER_EMAIL_HOUR por dirección de correo, PER_PHONE_HOUR por teléfono, PER_IP_HOUR por
 *     dirección de red y SITE_HOUR en total; MAX_TRIES intentos fallidos por ficha.
 *
 * Los tres valores del ajuste: off (ningún programa hace pedidos), key (solo con una clave con el ámbito orders:create) y code
 * (con esa clave, o sin ella con el código del comprador).
 *
 * La pasarela de texto recibe un POST con JSON {"to": "34999000000", "text": "…"} y la clave en Authorization: Bearer.
 * 360dialog recibe la plantilla en su formato (type = template) y la clave en la cabecera D360-API-KEY.
 */
final class BuyerCode
{
    public const SETTING = 'api.agent_orders';
    public const MODE_OFF = 'off';
    public const MODE_KEY = 'key';
    public const MODE_CODE = 'code';

    public const TEMPLATE = 'buyer_code';
    public const WINDOW = 900;
    public const MAX_TRIES = 5;
    public const PER_EMAIL_HOUR = 5;
    public const PER_PHONE_HOUR = 5;
    public const PER_IP_HOUR = 15;
    public const SITE_HOUR = 120;
    public const WA_GATEWAY = 'mensajero';
    public const WA_360 = '360dialog';
    public const URL_360 = 'https://waba-v2.360dialog.io/messages';

    /** Lo que se le dice al programa cuando el código ha salido ({how} = por dónde). */
    public const WAIT = 'For security, the shop has just sent a 6-digit verification code to the buyer {how}. Tell the person that, show them the total and the terms, ask them for the code and WAIT for it: do not guess it and do not place the order without it. Then call place with the confirm_token, buyer_confirmed true and buyer_code.';

    public static function mode(App $app): string
    {
        $mode = $app->settings()->str(self::SETTING, self::MODE_CODE);

        return in_array($mode, [self::MODE_OFF, self::MODE_KEY, self::MODE_CODE], true) ? $mode : self::MODE_CODE;
    }

    /**
     * ¿Puede esta clave hacer pedidos por sí sola (sin código)?
     *
     * @param array<string,mixed>|null $key
     */
    public static function trusted(?array $key, string $scope): bool
    {
        return $key !== null && isset($key['scopes']) && is_array($key['scopes']) && in_array($scope, $key['scopes'], true);
    }

    /** El código de una ficha en un tramo de tiempo. */
    public static function code(App $app, string $token, int $slot): string
    {
        $hash = hash_hmac('sha256', 'buyer-code:' . strtolower($token) . ':' . $slot, $app->site()->secret());

        return str_pad((string) (hexdec(substr($hash, 0, 12)) % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /** El sistema de WhatsApp de la tienda: «mensajero» (una pasarela de texto) o «360dialog» (la API oficial de Meta). */
    public static function provider(App $app): string
    {
        return $app->settings()->str('api.whatsapp_provider', self::WA_GATEWAY) === self::WA_360 ? self::WA_360 : self::WA_GATEWAY;
    }

    /** La dirección a la que se manda el WhatsApp. Con 360dialog, la suya si la tienda no pone otra. Vacío si no vale. */
    public static function whatsappUrl(App $app): string
    {
        $url = trim($app->settings()->str('api.whatsapp_url', ''));
        if ($url === '' && self::provider($app) === self::WA_360) {
            $url = self::URL_360;
        }

        return preg_match('#^https?://#i', $url) === 1 ? $url : '';
    }

    /**
     * ¿Manda la tienda el código también por WhatsApp? Hace falta el interruptor, la clave y, según el sistema, la dirección de
     * la pasarela (mensajero) o el nombre de la plantilla aprobada (360dialog: Meta no deja empezar una conversación sin ella).
     */
    public static function whatsappOn(App $app): bool
    {
        // Una demostración no manda WhatsApp: su panel puede ser público y la dirección de la pasarela es un ajuste.
        if ($app->site()->isDemo() || !$app->settings()->bool('api.code_whatsapp', false) || self::whatsappUrl($app) === '' || $app->secret('api.whatsapp_token') === '') {
            return false;
        }

        return self::provider($app) !== self::WA_360 || trim($app->settings()->str('api.whatsapp_template', '')) !== '';
    }

    /**
     * El teléfono como lo quiere la pasarela: solo cifras, con el prefijo del país. Vacío si no parece un teléfono.
     */
    public static function phone(App $app, string $phone): string
    {
        $digits = (string) preg_replace('/\D+/', '', $phone);
        if (strpos($digits, '00') === 0) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) === 9) {
            // Sin prefijo de país: el de la tienda.
            $prefix = (string) preg_replace('/\D+/', '', $app->settings()->str('api.whatsapp_prefix', '34'));
            $digits = ($prefix !== '' ? $prefix : '34') . $digits;
        }

        return strlen($digits) >= 10 && strlen($digits) <= 15 ? $digits : '';
    }

    /**
     * ¿Se le puede mandar un WhatsApp a este teléfono? Sin lista (api.whatsapp_only vacío), a cualquiera; con lista, solo a
     * los teléfonos que están en ella. Es el seguro de una tienda de pruebas: el teléfono lo escribe quien compra (o un
     * asistente, o una prueba con datos de relleno) y el mensaje sale del WhatsApp del comerciante hacia ese número.
     */
    public static function allowed(App $app, string $number): bool
    {
        $only = [];
        foreach (preg_split('/[,;\r\n]+/', $app->settings()->str('api.whatsapp_only', ''), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $entry) {
            $normal = self::phone($app, $entry);
            if ($normal !== '') {
                $only[] = $normal;
            }
        }

        return $only === [] || in_array($number, $only, true);
    }

    /**
     * Manda el código al comprador (una vez por ficha y tramo) y devuelve lo que hay que decirle al programa.
     *
     * @param string $token       la ficha de confirmación de la revisión
     * @param string $email       la dirección del comprador
     * @param string $total       el total, ya con su formato
     * @param string $maskedEmail la dirección tapada, para la respuesta
     * @param string $phone       el teléfono del comprador ('' si no dio ninguno)
     * @param string $maskedPhone el teléfono tapado, para la respuesta
     *
     * @return array<string,mixed> method, sent, channels (por dónde ha salido), sent_to (correo tapado), expires_in y message
     */
    public static function send(App $app, string $token, string $email, string $total, string $maskedEmail, string $phone = '', string $maskedPhone = ''): array
    {
        $out = ['method' => 'buyer_code', 'sent' => false, 'channels' => [], 'sent_to' => $maskedEmail, 'expires_in' => self::WINDOW];
        $mailOk = filter_var($email, FILTER_VALIDATE_EMAIL) !== false && $app->has('mailer');
        $number = self::whatsappOn($app) ? self::phone($app, $phone) : '';
        if ($number !== '' && !self::allowed($app, $number)) {
            // La tienda ha limitado los teléfonos a los que manda WhatsApp y este no está: a este comprador, solo correo.
            $number = '';
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1 || (!$mailOk && $number === '')) {
            $out['message'] = 'The verification code could not be sent: the buyer has no valid email address. Ask for it, save it and review again, or send the person to the checkout page.';

            return $out;
        }
        $limiter = new RateLimiter($app->db(), $app->clock());
        $slot = intdiv($app->clock()->now(), self::WINDOW);
        $once = 'buyer-code-sent:' . substr(hash('sha256', $token), 0, 40) . ':' . $slot;
        if ($limiter->remaining($once, 1, self::WINDOW) < 1) {
            // Ya salió para este mismo pedido hace un momento: no se manda otra vez lo mismo.
            $out['sent'] = true;
            $out['already_sent'] = true;
            $out['message'] = str_replace('{how}', 'a moment ago', self::WAIT);

            return $out;
        }
        $shared = [['buyer-code-site', self::SITE_HOUR]];
        $reader = \Aifec\Kernel\VisitLog::bot($app->request()->userAgent());
        if ($reader === null || $reader[2] !== 'ai_user') {
            // El tope por dirección de red, salvo para el lector de un asistente de chat: todas las compras que hace Claude (o
            // ChatGPT, o Gemini) para cualquier cliente salen de las mismas pocas direcciones de su empresa, y con 15 por hora
            // la tienda dejaba de mandar el código a todos (17/11/2026: «sent: false» a la decimosexta compra con Claude). A
            // esos los siguen limitando el tope por correo, por teléfono, por ficha y el del sitio.
            array_unshift($shared, ['buyer-code-ip:' . (string) $app->request()->ip, self::PER_IP_HOUR]);
        }
        foreach ($shared as $bucket) {
            if ($limiter->remaining($bucket[0], $bucket[1], 3600) < 1) {
                $out['message'] = 'Too many verification codes have been sent in the last hour. No code was sent now: wait, or send the person to the checkout page.';

                return $out;
            }
        }
        $code = self::code($app, $token, $slot);
        $minutes = intdiv(self::WINDOW, 60);
        $limited = false;

        $mailBucket = 'buyer-code-mail:' . hash('sha256', strtolower($email));
        // El tope por dirección se puede subir en los ajustes (una tienda de pruebas en la que todas las compras van al mismo
        // buzón); nunca queda sin tope ni por encima de uno por minuto.
        $perEmail = max(1, min(60, $app->settings()->int('api.codes_per_email_hour', self::PER_EMAIL_HOUR)));
        if ($mailOk && $limiter->remaining($mailBucket, $perEmail, 3600) < 1) {
            $limited = true;
        } elseif ($mailOk && self::email($app, $email, $code, $total, $minutes)) {
            $limiter->hit($mailBucket, $perEmail, 3600);
            $out['channels'][] = 'email';
        }
        $phoneBucket = 'buyer-code-phone:' . hash('sha256', $number);
        if ($number !== '' && $limiter->remaining($phoneBucket, self::PER_PHONE_HOUR, 3600) < 1) {
            $limited = true;
        } elseif ($number !== '' && self::whatsapp($app, $number, $code, $total, $minutes)) {
            $limiter->hit($phoneBucket, self::PER_PHONE_HOUR, 3600);
            $out['channels'][] = 'whatsapp';
            $out['sent_to_phone'] = $maskedPhone;
        }

        if ($out['channels'] === []) {
            $out['message'] = $limited
                ? 'Too many verification codes have been sent to this buyer in the last hour. No code was sent now: wait, or send the person to the checkout page.'
                : 'The shop could not send the verification code right now, so nothing was sent to the buyer. Tell the person that, wait a minute and open the review again to send it. If it fails again, send the person to the checkout page.';

            return $out;
        }
        $limiter->hit($once, 1, self::WINDOW);
        foreach ($shared as $bucket) {
            $limiter->hit($bucket[0], $bucket[1], 3600);
        }
        $how = ['email' => 'by email', 'whatsapp' => 'by WhatsApp'];
        $out['sent'] = true;
        $out['message'] = str_replace('{how}', implode(' and ', array_map(static function (string $channel) use ($how): string {
            return $how[$channel];
        }, $out['channels'])), self::WAIT);

        return $out;
    }

    /**
     * Comprueba el código que manda el programa. Devuelve null si vale; si no, [estado HTTP, motivo] y nada se hace.
     *
     * @param mixed $given lo que llegó en buyer_code
     *
     * @return array{0:int,1:string}|null
     */
    public static function refusal(App $app, string $token, $given): ?array
    {
        $code = is_string($given) || is_int($given) ? preg_replace('/[\s.-]+/', '', (string) $given) : '';
        if (!is_string($code) || preg_match('/^[0-9]{6}$/D', $code) !== 1) {
            return [400, 'buyer_code is required: for security, the shop sends a 6-digit verification code to the buyer when you review the order. Ask the person for it and wait. No order was placed'];
        }
        $limiter = new RateLimiter($app->db(), $app->clock());
        $tries = 'buyer-code-try:' . substr(hash('sha256', $token), 0, 40);
        if ($limiter->remaining($tries, self::MAX_TRIES, 3600) < 1) {
            return [429, 'Too many wrong codes for this order. No order was placed: send the person to the checkout page'];
        }
        $slot = intdiv($app->clock()->now(), self::WINDOW);
        if (hash_equals(self::code($app, $token, $slot), $code) || hash_equals(self::code($app, $token, $slot - 1), $code)) {
            return null;
        }
        $limiter->hit($tries, self::MAX_TRIES, 3600);

        return [403, 'buyer_code is not right or it is too old. Ask the person to read it again from the message, or review the order again to send a new one. No order was placed'];
    }

    /** El correo con el código. Devuelve si ha salido (o ha quedado en cola para salir). */
    private static function email(App $app, string $email, string $code, string $total, int $minutes): bool
    {
        try {
            // «retries»: el código caduca, así que un fallo pasajero se reintenta en el momento y no a los cinco minutos.
            $mailer = $app->service('mailer');
            $id = $mailer->queue(self::TEMPLATE, $email, ['code' => $code, 'total' => $total, 'minutes' => $minutes],
                ['locale' => $app->lang(), 'now' => true, 'retries' => 2, 'related' => 'buyer-code']);
            $status = $id > 0 ? (string) $app->db()->value('SELECT status FROM {mail_messages} WHERE id = ?', [$id]) : '';
            if ($status === 'pending') {
                // No ha salido. La tienda va a decir que no ha podido mandarlo: que no llegue después, cuando ya nadie lo espera.
                $mailer->cancel($id);
            }
        } catch (\Throwable $e) {
            $app->log()->exception($e, 'buyer-code.mail');
            $status = '';
        }

        // Enviado es enviado: «pending» era un intento fallido a la espera de la cola, y la tienda decía «te lo he mandado».
        return $status === 'sent';
    }

    /** El WhatsApp con el código, por la pasarela de la tienda. Devuelve si la pasarela lo ha aceptado. */
    private static function whatsapp(App $app, string $number, string $code, string $total, int $minutes): bool
    {
        try {
            $text = t('{code} is your code to confirm your order at {shop} (total {total}). Give it to the assistant only if you want to place the order. It works for {minutes} minutes.', [
                'code' => $code, 'shop' => $app->settings()->str('store.name', 'AIFEC'), 'total' => $total, 'minutes' => $minutes,
            ]);
            if (self::provider($app) === self::WA_360) {
                // Meta: lo inicia la tienda, así que va con una plantilla aprobada. Sus huecos {{1}}, {{2}}… se rellenan en el
                // orden que diga la tienda; las plantillas de autenticación llevan además el código en su botón de copiar.
                $values = ['code' => $code, 'total' => $total, 'minutes' => (string) $minutes, 'shop' => $app->settings()->str('store.name', 'AIFEC')];
                $parameters = [];
                foreach (preg_split('/[\s,;]+/', strtolower($app->settings()->str('api.whatsapp_template_vars', 'code')), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $name) {
                    if (isset($values[$name])) {
                        $parameters[] = ['type' => 'text', 'text' => $values[$name]];
                    }
                }
                $components = [['type' => 'body', 'parameters' => $parameters !== [] ? $parameters : [['type' => 'text', 'text' => $code]]]];
                if ($app->settings()->bool('api.whatsapp_template_button', false)) {
                    $components[] = ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $code]]];
                }
                $language = trim($app->settings()->str('api.whatsapp_template_lang', 'es'));
                $request = HttpRequest::postJson(self::whatsappUrl($app), [
                    'messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $number, 'type' => 'template',
                    'template' => ['name' => trim($app->settings()->str('api.whatsapp_template', '')), 'language' => ['code' => $language !== '' ? $language : 'es'], 'components' => $components],
                ], ['D360-API-KEY' => $app->secret('api.whatsapp_token')], 10.0);
            } else {
                $request = HttpRequest::postJson(self::whatsappUrl($app), ['to' => $number, 'text' => $text, 'event' => 'buyer_code'], [
                    'Authorization' => 'Bearer ' . $app->secret('api.whatsapp_token'),
                ], 10.0);
            }

            return $app->http()->send($request)->ok();
        } catch (\Throwable $e) {
            $app->log()->exception($e, 'buyer-code.whatsapp');

            return false;
        }
    }
}
