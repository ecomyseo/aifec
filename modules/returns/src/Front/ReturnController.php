<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Returns\Front;

use Aifec\Kernel\App;
use Aifec\Kernel\Clock;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\RateLimiter;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Modules\Returns\ReturnLabels;
use Aifec\Modules\Returns\ReturnPolicy;
use Aifec\Modules\Returns\Returns;

/**
 * Las páginas de la tienda para pedir una devolución o el desistimiento, por tres caminos que acaban en el mismo formulario:
 *
 *  - la ficha secreta de la página del pedido (GET /devolucion/{pedido}/{ficha}): vale para un invitado;
 *  - «Mi cuenta» (GET /mi-cuenta/devoluciones/{pedido}): el pedido tiene que ser del cliente que ha entrado;
 *  - el formulario legal público (/desistimiento): número de pedido y correo, que se vuelven a comprobar en cada envío.
 *
 * Un pedido que no existe, una ficha que no es la suya y un pedido de otra persona dan el mismo 404. Los envíos pasan por «csrf» y
 * por un límite de 5 intentos por hora y pedido (y un tope por dirección IP). Ninguna página se guarda en caché ni se indexa.
 */
final class ReturnController
{
    /** Intentos por hora y pedido. */
    private const ATTEMPTS = 5;

    /** Intentos por hora y dirección IP, para que no se puedan probar números de pedido de uno en uno. */
    private const ATTEMPTS_IP = 40;

    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    // ------------------------------------------------------------------ por la ficha del pedido

    /** @param array<string,string> $params number y secret */
    public function show(Request $request, array $params): Response
    {
        $order = $this->bySecret($params);

        return $this->render($request, $order, $this->secretAccess($order, $params));
    }

    /** @param array<string,string> $params number y secret */
    public function submit(Request $request, array $params): Response
    {
        $order = $this->bySecret($params);

        return $this->handle($request, $order, $this->secretAccess($order, $params));
    }

    /** @param array<string,string> $params number y secret */
    public function cancel(Request $request, array $params): Response
    {
        $order = $this->bySecret($params);
        $access = $this->secretAccess($order, $params);
        $this->cancelOpen($request, $order);

        return Response::redirect($access['redirect'], 303);
    }

    // ------------------------------------------------------------------ desde la cuenta

    /** @param array<string,string> $params number */
    public function accountShow(Request $request, array $params): Response
    {
        $order = $this->byAccount($params);

        return $this->render($request, $order, $this->accountAccess($order));
    }

    /** @param array<string,string> $params number */
    public function accountSubmit(Request $request, array $params): Response
    {
        $order = $this->byAccount($params);

        return $this->handle($request, $order, $this->accountAccess($order));
    }

    /** @param array<string,string> $params number */
    public function accountCancel(Request $request, array $params): Response
    {
        $order = $this->byAccount($params);
        $access = $this->accountAccess($order);
        $this->cancelOpen($request, $order);

        return Response::redirect($access['redirect'], 303);
    }

    // ------------------------------------------------------------------ el formulario legal público

    /** @param array<string,string> $params */
    public function withdrawal(Request $request, array $params): Response
    {
        return $this->withdrawalPage(['number' => mb_substr($request->str('number'), 0, 32, 'UTF-8'), 'email' => ''], '', 200);
    }

    /**
     * POST: número de pedido y correo. Si coinciden con un pedido, enseña el formulario de la devolución de ese pedido; si no, el
     * mismo aviso sea cual sea la razón (no se confirma qué pedidos existen).
     *
     * @param array<string,string> $params
     */
    public function lookup(Request $request, array $params): Response
    {
        $number = mb_substr($request->str('number'), 0, 32, 'UTF-8');
        $email = mb_substr($request->str('email'), 0, 191, 'UTF-8');
        $this->limit('lookup', $number, $request);
        $order = $this->byLookup($number, $email);
        if ($order === null) {
            return $this->withdrawalPage(['number' => $number, 'email' => $email], t('We could not find an order with those details.'), 422);
        }

        return $this->render($request, $order, $this->lookupAccess($order, $email));
    }

    /** @param array<string,string> $params */
    public function lookupSubmit(Request $request, array $params): Response
    {
        $number = mb_substr($request->str('number'), 0, 32, 'UTF-8');
        $email = mb_substr($request->str('email'), 0, 191, 'UTF-8');
        $order = $this->byLookup($number, $email);
        if ($order === null) {
            $this->limit('submit', $number, $request);

            return $this->withdrawalPage(['number' => $number, 'email' => $email], t('We could not find an order with those details.'), 422);
        }

        return $this->handle($request, $order, $this->lookupAccess($order, $email));
    }

    // ------------------------------------------------------------------ el envío y el formulario

    /**
     * El envío del formulario: cuenta como un intento, pide la devolución y, si sale bien, vuelve a la página (o la enseña con el
     * aviso si el camino no tiene dirección propia).
     *
     * @param array<string,mixed> $order
     * @param array<string,mixed> $access
     */
    private function handle(Request $request, array $order, array $access): Response
    {
        $this->limit('submit', (string) $order['number'], $request);
        $lines = [];
        foreach ($request->arr('lines') as $itemId => $qty) {
            if (is_scalar($qty) && is_numeric($qty) && (int) $qty > 0) {
                $lines[(int) $itemId] = (int) $qty;
            }
        }
        $values = ['lines' => $lines, 'reason' => $request->str('reason'), 'comment' => mb_substr($request->str('comment'), 0, 2100, 'UTF-8')];
        $photos = [];
        foreach ([1, 2, 3] as $n) {
            $file = isset($request->files['photo_' . $n]) && is_array($request->files['photo_' . $n]) ? $request->files['photo_' . $n] : null;
            if ($file === null || !isset($file['error'])) {
                continue;
            }
            $error = (int) $file['error'];
            if ($error === 1 || $error === 2) {
                return $this->render($request, $order, $access, ['errors' => ['photos' => t('Each photo can be 5 MB at most.')], 'error' => t('Each photo can be 5 MB at most.'), 'values' => $values], 422);
            }
            if ($error === 0 && isset($file['tmp_name']) && is_string($file['tmp_name']) && $file['tmp_name'] !== '') {
                $photos[] = ['name' => isset($file['name']) ? (string) $file['name'] : 'photo', 'tmp_name' => $file['tmp_name'], 'size' => isset($file['size']) ? (int) $file['size'] : 0, 'type' => isset($file['type']) ? (string) $file['type'] : ''];
            }
        }
        $result = $this->returns()->request((int) $order['id'], $lines, $values['reason'], $values['comment'], [
            'photos' => $photos, 'customer_id' => $access['customer_id'], 'source' => 'web', 'actor' => 'customer',
        ]);
        if (!empty($result['ok'])) {
            if ((string) $access['redirect'] !== '') {
                return Response::redirect($access['redirect'] . (strpos((string) $access['redirect'], '?') === false ? '?' : '&') . 'done=' . rawurlencode((string) $result['number']), 303);
            }

            return $this->render($request, $order, $access, ['done' => (string) $result['number']]);
        }

        return $this->render($request, $order, $access, ['errors' => $result['errors'], 'error' => (string) $result['error'], 'values' => $values], 422);
    }

    /**
     * Cancela la devolución abierta del pedido (la pide el cliente mientras está pedida o aceptada).
     *
     * @param array<string,mixed> $order
     */
    private function cancelOpen(Request $request, array $order): void
    {
        $return = $this->returns()->findByNumber($request->str('return'));
        if ($return === null || (int) $return['order_id'] !== (int) $order['id']) {
            throw HttpError::notFound();
        }
        try {
            $this->returns()->cancel((int) $return['id'], '', false, null, 'customer');
        } catch (\InvalidArgumentException $e) {
            // Ya no estaba en un estado que se pueda cancelar: la página enseña el estado de ahora.
        }
    }

    /**
     * La página: lo que se puede devolver del pedido y el formulario, y debajo el estado de las devoluciones que ya tiene.
     *
     * @param array<string,mixed> $order
     * @param array<string,mixed> $access
     * @param array<string,mixed> $extra  done, errors, error, values
     *
     * @return Response
     */
    private function render(Request $request, array $order, array $access, array $extra = [], int $status = 200): Response
    {
        $app = $this->app;
        $returns = $this->returns();
        $policy = $returns->policy();
        $eligibility = $returns->eligibility((int) $order['id']);
        $timezone = $app->site()->timezone();
        $date = static function (?string $sql) use ($app, $timezone): string {
            $time = Clock::parse($sql);

            return $time > 0 ? $app->i18n()->date($time, 'medium', $timezone) : '';
        };

        $list = [];
        foreach ($returns->forOrder((int) $order['id']) as $return) {
            $code = (string) $return['status'];
            $list[] = [
                'number' => (string) $return['number'],
                'type' => ReturnLabels::type((string) $return['kind']),
                'status' => ReturnLabels::status($code),
                'class' => ReturnLabels::kind($code) === 'ok' ? 'af-badge af-badge-new' : (ReturnLabels::kind($code) === 'bad' ? 'af-badge af-badge-out' : 'af-badge'),
                'text' => ReturnLabels::customerText($code),
                'instructions' => $code === 'approved' ? (string) $return['instructions'] : '',
                'reject_reason' => $code === 'rejected' ? (string) $return['reject_reason'] : '',
                'refunded' => (int) $return['refunded_amount'] > 0 ? money((int) $return['refunded_amount'], (string) $return['currency']) : '',
                'date' => $date((string) $return['requested_at']),
                'can_cancel' => $access['cancel'] !== '' && in_array($code, ['requested', 'approved'], true),
            ];
        }

        // Los motivos que se ofrecen: el desistimiento si alguna línea lo admite, y los de la tienda mientras corra su plazo.
        $reasons = [];
        $anyWithdrawal = false;
        foreach ($eligibility['lines'] as $line) {
            $anyWithdrawal = $anyWithdrawal || !empty($line['withdrawal_ok']);
        }
        if ($anyWithdrawal) {
            $reasons[ReturnPolicy::WITHDRAWAL] = t('Withdrawal (no reason needed)');
        }
        if ($eligibility['return_open']) {
            foreach ($policy->reasons() as $code => $label) {
                $reasons[$code] = t($label);
            }
        }
        $excluded = false;
        foreach ($eligibility['lines'] as $line) {
            $excluded = $excluded || !empty($line['withdrawal_excluded']);
        }

        $done = '';
        $doneNumber = isset($extra['done']) ? (string) $extra['done'] : $request->str('done');
        if ($doneNumber !== '') {
            $found = $returns->findByNumber($doneNumber);
            $done = $found !== null && (int) $found['order_id'] === (int) $order['id'] ? (string) $found['number'] : '';
        }

        $response = $app->render('returns::front/request', [
            // El mismo título que la página pública /desistimiento cuando se llega desde ella (antes cambiaba al enviar).
            'page' => ['title' => !empty($access['withdrawal_page']) ? t('Right of withdrawal') : t('Return or withdrawal'), 'type' => 'returns'],
            'order' => ['number' => (string) $order['number'], 'placed' => $date((string) $order['placed_at'])],
            'eligibility' => $eligibility,
            'unshipped' => !empty($eligibility['unshipped']),
            'returns' => $list,
            'access' => $access,
            'reasons' => $reasons,
            'photos' => $returns->photos()->available(),
            'values' => isset($extra['values']) ? $extra['values'] : ['lines' => [], 'reason' => '', 'comment' => ''],
            'errors' => isset($extra['errors']) && is_array($extra['errors']) ? $extra['errors'] : [],
            'error' => isset($extra['error']) ? (string) $extra['error'] : '',
            'done' => $done,
            'dates' => ['return_until' => $date($eligibility['return_until']), 'withdrawal_until' => $date($eligibility['withdrawal_until'])],
            'days' => ['return' => $policy->days(), 'withdrawal' => $policy->withdrawalDays()],
            'exclusions' => $excluded ? $this->paragraphs('returns.exclusions_text', 'exclusions') : [],
            'maxLength' => 2000,
        ], $status);
        $response->headers['Cache-Control'] = 'no-store, private';
        $response->headers['X-Robots-Tag'] = 'noindex, nofollow';

        return $response;
    }

    /**
     * La página pública del formulario legal de desistimiento: el texto modelo (o el de la tienda) y el formulario de número y correo.
     *
     * @param array<string,string> $values number y email
     */
    private function withdrawalPage(array $values, string $error, int $status): Response
    {
        $policy = $this->returns()->policy();
        $response = $this->app->render('returns::front/withdrawal', [
            'page' => ['title' => t('Right of withdrawal'), 'type' => 'returns'],
            'values' => $values,
            'error' => $error,
            'paragraphs' => $this->paragraphs('returns.withdrawal_text', 'withdrawal'),
            'exclusions' => $this->paragraphs('returns.exclusions_text', 'exclusions'),
            'days' => $policy->withdrawalDays(),
        ], $status);
        $response->headers['Cache-Control'] = 'no-store, private';

        return $response;
    }

    /**
     * Los párrafos de un texto legal: el que escribió la tienda en los ajustes (un párrafo por línea) o el modelo de serie.
     *
     * @return string[]
     */
    private function paragraphs(string $setting, string $model): array
    {
        $out = [];
        foreach (preg_split('/\R+/u', $this->app->settings()->str($setting)) ?: [] as $line) {
            if (trim($line) !== '') {
                $out[] = trim($line);
            }
        }
        if ($out !== []) {
            return $out;
        }
        if ($model === 'exclusions') {
            return [
                t('The right of withdrawal does not apply to products made to the customer\'s specifications or clearly personalised, to goods that can deteriorate or expire rapidly, or to sealed goods that are not suitable for return for health protection or hygiene reasons and were unsealed after delivery.'),
            ];
        }
        $days = $this->returns()->policy()->withdrawalDays();
        $returnDays = $this->returns()->policy()->days();
        // Con un plazo de devolución más largo que el legal, se dice que los dos valen (14/11/2026: «la página de envíos dice 30 días
        // y esta 14: ¿cuál es?»).
        $longer = $returnDays > $days
            ? [t('This shop also accepts returns for {returns} days: the {days}-day withdrawal period is the legal minimum, and you can use whichever suits you.', ['returns' => $returnDays, 'days' => $days])]
            : [];

        return array_merge($longer, [
            t('You have the right to withdraw from this contract within {days} days without giving any reason.', ['days' => $days]),
            t('The withdrawal period expires {days} days after the day on which you, or a third party other than the carrier and indicated by you, acquire physical possession of the goods.', ['days' => $days]),
            t('To exercise the right of withdrawal you must inform us of your decision by a clear statement. You can use the form on this page or write to us by email.'),
            // Quién paga la vuelta: lo que diga la tienda en sus ajustes; si no dice nada, lo de la ley (14/11/2026: la página de
            // envíos decía «pasamos a recogerlo» y esta, «corres con los gastos»).
            $this->returns()->customPayer() !== ''
                ? t('We will reimburse all payments received from you, including the costs of standard delivery, without undue delay and in any event not later than 14 days after we receive the goods back or you send proof that you have returned them.') . ' ' . $this->returns()->customPayer()
                : t('We will reimburse all payments received from you, including the costs of standard delivery, without undue delay and in any event not later than 14 days after we receive the goods back or you send proof that you have returned them. You bear the direct cost of returning the goods.'),
        ]);
    }

    // ------------------------------------------------------------------ quién entra y cómo

    /**
     * @param array<string,string> $params
     *
     * @return array<string,mixed>
     */
    private function bySecret(array $params): array
    {
        $number = isset($params['number']) ? (string) $params['number'] : '';
        $secret = isset($params['secret']) ? (string) $params['secret'] : '';
        $order = $number !== '' && $secret !== '' ? $this->app->service('orders')->findBySecret($number, $secret) : null;
        if ($order === null) {
            throw HttpError::notFound();
        }

        return $order;
    }

    /**
     * El pedido de un cliente que ha entrado: de otra persona, o sin cuenta, da 404.
     *
     * @param array<string,string> $params
     *
     * @return array<string,mixed>
     */
    private function byAccount(array $params): array
    {
        $customer = $this->app->has('customers') ? $this->app->service('customers')->current() : null;
        $order = isset($params['number']) ? $this->app->service('orders')->findByNumber((string) $params['number']) : null;
        if ($customer === null || $order === null || $order['customer_id'] === null || (int) $order['customer_id'] !== (int) $customer['id']) {
            throw HttpError::notFound();
        }

        return $order;
    }

    /**
     * El pedido que casa con ese número y ese correo (sin mirar mayúsculas), o null.
     *
     * @return array<string,mixed>|null
     */
    private function byLookup(string $number, string $email): ?array
    {
        $email = strtolower(trim($email));
        $order = $number !== '' && $email !== '' ? $this->app->service('orders')->findByNumber($number) : null;
        if ($order === null || !hash_equals(strtolower(trim((string) $order['email'])), $email)) {
            return null;
        }

        return $order;
    }

    /**
     * @param array<string,mixed>  $order
     * @param array<string,string> $params
     *
     * @return array<string,mixed>
     */
    private function secretAccess(array $order, array $params): array
    {
        $args = ['number' => (string) $order['number'], 'secret' => (string) $params['secret']];

        return [
            'action' => route('returns.submit', $args), 'cancel' => route('returns.cancel', $args), 'redirect' => route('returns.order', $args),
            'hidden' => [], 'customer_id' => $order['customer_id'], 'back' => route('order.thanks', $args), 'back_label' => t('Back to my order'),
        ];
    }

    /**
     * @param array<string,mixed> $order
     *
     * @return array<string,mixed>
     */
    private function accountAccess(array $order): array
    {
        $args = ['number' => (string) $order['number']];

        return [
            'action' => route('returns.account.submit', $args), 'cancel' => route('returns.account.cancel', $args), 'redirect' => route('returns.account', $args),
            'hidden' => [], 'customer_id' => $order['customer_id'], 'back' => route('account.order', $args), 'back_label' => t('Back to my order'),
        ];
    }

    /**
     * @param array<string,mixed> $order
     *
     * @return array<string,mixed>
     */
    private function lookupAccess(array $order, string $email): array
    {
        return [
            'action' => route('returns.lookup.submit'), 'cancel' => '', 'redirect' => '',
            'hidden' => ['number' => (string) $order['number'], 'email' => $email], 'customer_id' => $order['customer_id'],
            'back' => route('returns.withdrawal'), 'back_label' => t('Back'), 'withdrawal_page' => true,
        ];
    }

    /**
     * Apunta un intento de un formulario público: 5 por hora y pedido y un tope por dirección IP. Pasado el tope, un 429.
     *
     * @throws HttpError
     */
    private function limit(string $kind, string $number, Request $request): void
    {
        $limiter = new RateLimiter($this->app->db(), $this->app->clock());
        $key = 'returns:' . $kind . ':' . strtolower(trim($number));
        if (!$limiter->hit($key, self::ATTEMPTS, 3600) || !$limiter->hit('returns-ip:' . $request->ip, self::ATTEMPTS_IP, 3600)) {
            throw HttpError::tooMany(t('Too many attempts. Try again in an hour.'));
        }
    }

    private function returns(): Returns
    {
        /** @var Returns $returns */
        $returns = $this->app->service('returns');

        return $returns;
    }
}
