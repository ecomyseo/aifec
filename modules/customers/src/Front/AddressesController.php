<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Customers\Front;

use Aifec\Geo\Countries;
use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

/**
 * Las direcciones de quien ha entrado: lista, alta, cambio y borrado. La validación es la del servicio de clientes; una
 * dirección de otro cliente es un 404 al verla, cambiarla o borrarla.
 */
final class AddressesController
{
    /** Campos de texto del formulario, con su largo máximo. */
    private const FIELDS = [
        'label' => 60, 'first_name' => 120, 'last_name' => 120, 'company' => 160, 'vat_id' => 40, 'phone' => 40,
        'line1' => 191, 'line2' => 191, 'postcode' => 16, 'city' => 120, 'region' => 16, 'country' => 2,
    ];

    /** @var App */
    private $app;

    /** @var Account */
    private $account;

    public function __construct(App $app)
    {
        $this->app = $app;
        $this->account = new Account($app);
    }

    /**
     * @param array<string,string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $a = $this->account;
        $me = $a->me();
        if ($me === null) {
            return $a->toLogin();
        }
        $rows = [];
        foreach ($a->customers()->addresses((int) $me['id']) as $address) {
            $rows[] = [
                'label' => trim((string) $address['label']),
                'lines' => $a->addressLines($address),
                'phone' => trim((string) $address['phone']),
                'shipping' => (int) $address['default_shipping'] === 1,
                'billing' => (int) $address['default_billing'] === 1,
                'edit_url' => $a->link('account.address', ['id' => (int) $address['id']]),
                'delete_url' => $a->link('account.address.delete', ['id' => (int) $address['id']]),
            ];
        }

        return $a->render('addresses', t('My addresses'), ['customer' => $me, 'active' => 'addresses', 'rows' => $rows]);
    }

    /**
     * Alta (sin «id») o cambio (con «id») de una dirección.
     *
     * @param array<string,string> $params id (opcional)
     */
    public function form(Request $request, array $params): Response
    {
        $a = $this->account;
        $me = $a->me();
        if ($me === null) {
            return $a->toLogin();
        }
        $customers = $a->customers();
        $addressId = isset($params['id']) ? (int) $params['id'] : null;
        $current = null;
        if ($addressId !== null) {
            $current = $customers->address($addressId, (int) $me['id']);
            if ($current === null) {
                throw HttpError::notFound();
            }
        }
        $values = array_fill_keys(array_keys(self::FIELDS), '');
        $values['country'] = 'ES';
        $values['default_shipping'] = false;
        $values['default_billing'] = false;
        if ($current !== null) {
            foreach (array_keys(self::FIELDS) as $field) {
                $values[$field] = isset($current[$field]) && is_scalar($current[$field]) ? (string) $current[$field] : '';
            }
            $values['default_shipping'] = (int) $current['default_shipping'] === 1;
            $values['default_billing'] = (int) $current['default_billing'] === 1;
        } else {
            $values['first_name'] = trim((string) $me['first_name']);
            $values['last_name'] = trim((string) $me['last_name']);
            $values['phone'] = trim((string) $me['phone']);
        }
        $messages = [];
        if ($request->isPost()) {
            $data = [];
            foreach (self::FIELDS as $field => $max) {
                $data[$field] = mb_substr($request->str($field), 0, $max, 'UTF-8');
            }
            // Una casilla sin marcar no viaja: lo que no llega se queda como estaba.
            foreach (['default_shipping', 'default_billing'] as $flag) {
                if ($request->bool($flag)) {
                    $data[$flag] = 1;
                }
            }
            $values = $data + ['default_shipping' => $request->bool('default_shipping'), 'default_billing' => $request->bool('default_billing')];
            try {
                $customers->saveAddress((int) $me['id'], $data, $addressId);
                $a->flash('ok', t('Address saved'));

                return $a->go('account.addresses');
            } catch (\InvalidArgumentException $e) {
                $messages[] = ['bad', t($e->getMessage())];
            }
        }

        return $a->render('address-form', $addressId !== null ? t('Edit address') : t('New address'), [
            'customer' => $me, 'active' => 'addresses', 'values' => $values, 'messages' => $messages,
            'action' => $addressId !== null ? $a->link('account.address', ['id' => $addressId]) : $a->link('account.address.new'),
            'countries' => Countries::all($this->app->lang()), 'editing' => $addressId !== null,
        ]);
    }

    /**
     * @param array<string,string> $params id
     */
    public function delete(Request $request, array $params): Response
    {
        $a = $this->account;
        $me = $a->me();
        if ($me === null) {
            return $a->toLogin();
        }
        try {
            $a->customers()->deleteAddress(isset($params['id']) ? (int) $params['id'] : 0, (int) $me['id']);
        } catch (\InvalidArgumentException $e) {
            throw HttpError::notFound();
        }
        $a->flash('ok', t('Address deleted'));

        return $a->go('account.addresses');
    }
}
