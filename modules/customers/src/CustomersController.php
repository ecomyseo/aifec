<?php
/**
 * AIFEC — pantallas propias de clientes en el panel: direcciones de un cliente, anonimizar, copia de sus datos y exportación del listado.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Customers;

use Aifec\Admin\Admin;
use Aifec\Admin\Form;
use Aifec\Admin\Table;
use Aifec\Geo\Countries;
use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

final class CustomersController
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    // ------------------------------------------------------------------ direcciones

    /** @param array<string,string> $params */
    public function addresses(Request $request, array $params): Response
    {
        $row = $this->customer($params, 'customers.view');
        $form = $this->addressForm();
        $editId = $request->int('address', 0);
        $values = ['country' => 'ES'];
        if ($editId > 0) {
            $address = $this->service()->address($editId, (int) $row['id']);
            if ($address === null) {
                throw HttpError::notFound();
            }
            $values = $form->load($address);
        }

        return $this->screen($row, $form, $values, [], $editId > 0 ? $editId : null, 200);
    }

    /** @param array<string,string> $params */
    public function saveAddress(Request $request, array $params): Response
    {
        $row = $this->customer($params, 'customers.edit');
        $id = (int) $row['id'];
        $addressId = $request->int('address_id', 0) > 0 ? $request->int('address_id', 0) : null;
        if ($addressId !== null && $this->service()->address($addressId, $id) === null) {
            throw HttpError::notFound();
        }
        $form = $this->addressForm();
        list($values, $errors) = $form->validate($request);
        if ($errors === []) {
            try {
                $this->service()->saveAddress($id, $values, $addressId);
                Admin::audit($this->app, $addressId === null ? 'address_create' : 'address_update', 'customer', (string) $id, (string) $row['email']);
                Admin::flash($this->app, 'ok', t('Address saved'));

                return Response::redirect($this->app->url()->plain('/admin/customers/' . $id . '/addresses'));
            } catch (\InvalidArgumentException $e) {
                Admin::flash($this->app, 'bad', t($e->getMessage()));
            }
        }

        return $this->screen($row, $form, $values, $errors, $addressId, 422);
    }

    /** @param array<string,string> $params */
    public function deleteAddress(Request $request, array $params): Response
    {
        $row = $this->customer($params, 'customers.edit');
        $id = (int) $row['id'];
        try {
            $this->service()->deleteAddress((int) $params['address'], $id);
        } catch (\InvalidArgumentException $e) {
            throw HttpError::notFound();
        }
        Admin::audit($this->app, 'address_delete', 'customer', (string) $id, (string) $row['email']);
        Admin::flash($this->app, 'ok', t('Address deleted'));

        return Response::redirect($this->app->url()->plain('/admin/customers/' . $id . '/addresses'));
    }

    // ------------------------------------------------------------------ derechos sobre los datos

    /** @param array<string,string> $params */
    public function anonymise(Request $request, array $params): Response
    {
        $row = $this->customer($params, 'customers.privacy');
        $id = (int) $row['id'];
        $this->service()->anonymise($id);
        Admin::audit($this->app, 'anonymise', 'customer', (string) $id, 'customer #' . $id);
        Admin::flash($this->app, 'ok', t('The personal data of this customer has been erased.'));

        return Response::redirect(Admin::resourceUrl($this->app, 'customers', $id));
    }

    /** @param array<string,string> $params */
    public function data(Request $request, array $params): Response
    {
        $row = $this->customer($params, 'customers.privacy');
        $id = (int) $row['id'];
        $data = $this->service()->exportData($id);
        Admin::audit($this->app, 'privacy_export', 'customer', (string) $id, (string) $row['email']);

        return Response::json($data)->header('Content-Disposition', 'attachment; filename="customer-' . $id . '.json"');
    }

    /** Lista de clientes en CSV, con los mismos filtros que se ven. Quien exporta queda apuntado en la auditoría. */
    public function exportCsv(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('customers.export');
        $table = new Table($this->app, CustomersAdmin::listConfig(true));
        $info = $table->query($request);
        Admin::audit($this->app, 'export', 'customers', '', $info['total'] . ' rows');

        return $table->csv($request, 'customers-' . gmdate('Ymd', $this->app->clock()->now()) . '.csv');
    }

    // ------------------------------------------------------------------ piezas

    private function service(): Customers
    {
        $service = $this->app->service('customers');
        if (!$service instanceof Customers) {
            throw new \LogicException('The customers service is not available');
        }

        return $service;
    }

    /**
     * El cliente de la dirección, tras pedir el permiso.
     *
     * @param array<string,string> $params
     *
     * @return array<string,mixed>
     */
    private function customer(array $params, string $cap): array
    {
        $this->app->auth()->requireCap($cap);
        $row = $this->app->db()->row('SELECT * FROM {customers} WHERE id = ?', [(int) $params['id']]);
        if ($row === null) {
            throw HttpError::notFound();
        }

        return $row;
    }

    private function addressForm(): Form
    {
        return new Form($this->app, ['fields' => [
            'label' => ['label' => 'Label', 'rules' => 'nullable|max:60', 'hint' => 'For example Home or Office.', 'width' => 'md'],
            'first_name' => ['label' => 'First name', 'rules' => 'required|max:80', 'width' => 'md'],
            'last_name' => ['label' => 'Last name', 'rules' => 'nullable|max:120', 'width' => 'md'],
            'company' => ['label' => 'Company', 'rules' => 'nullable|max:160'],
            'vat_id' => ['label' => 'Tax ID', 'rules' => 'nullable|max:40', 'width' => 'md'],
            'phone' => ['type' => 'tel', 'label' => 'Phone', 'rules' => 'nullable|max:40', 'width' => 'md'],
            'line1' => ['label' => 'Address', 'rules' => 'required|max:191'],
            'line2' => ['label' => 'Address line 2', 'rules' => 'nullable|max:191'],
            'postcode' => ['label' => 'Postcode', 'rules' => 'required|max:16', 'width' => 'sm'],
            'city' => ['label' => 'City', 'rules' => 'required|max:120', 'width' => 'md'],
            'region' => ['label' => 'Province or state', 'rules' => 'nullable|max:16', 'hint' => 'In Spain it is filled in from the postcode.', 'width' => 'sm'],
            'country' => ['type' => 'select', 'label' => 'Country', 'rules' => 'required|len:2', 'default' => 'ES', 'translate' => false,
                'options' => static function (App $app): array {
                    return Countries::all($app->lang());
                }],
            'default_shipping' => ['type' => 'toggle', 'label' => 'Default shipping address', 'default' => 0],
            'default_billing' => ['type' => 'toggle', 'label' => 'Default billing address', 'default' => 0],
        ]]);
    }

    /**
     * La pantalla de direcciones: la lista y el formulario de alta o cambio.
     *
     * @param array<string,mixed>  $row
     * @param array<string,mixed>  $values
     * @param array<string,string> $errors
     */
    private function screen(array $row, Form $form, array $values, array $errors, ?int $editId, int $status): Response
    {
        $app = $this->app;
        $id = (int) $row['id'];
        $canEdit = $app->auth()->can('customers.edit');
        $rows = [];
        foreach ($this->service()->addresses($id) as $address) {
            $rows[] = [
                'address' => $address,
                'edit_url' => $app->url()->plain('/admin/customers/' . $id . '/addresses', ['address' => $address['id']]),
                'delete_url' => $app->url()->plain('/admin/customers/' . $id . '/addresses/' . $address['id'] . '/delete'),
            ];
        }
        $formHtml = '';
        if ($canEdit) {
            $formHtml = $form->render($values + $form->defaults(), $errors, [
                'action' => $app->url()->plain('/admin/customers/' . $id . '/addresses/save'),
                'cancel' => $editId !== null ? $app->url()->plain('/admin/customers/' . $id . '/addresses') : '',
                'submit' => t('Save address'),
                'hidden' => $editId !== null ? ['address_id' => $editId] : [],
            ]);
        }
        $name = trim(Rules::str($row['first_name']) . ' ' . Rules::str($row['last_name']));

        return Admin::page($app, 'customers::admin/addresses', [
            'rows' => $rows,
            'form' => $formHtml,
            'canEdit' => $canEdit,
            'editing' => $editId !== null,
            'newUrl' => $app->url()->plain('/admin/customers/' . $id . '/addresses'),
        ], [
            'title' => t('Addresses'),
            'crumbs' => [
                [t('Customers'), Admin::resourceUrl($app, 'customers')],
                [$name !== '' ? $name : (string) $row['email'], Admin::resourceUrl($app, 'customers', $id)],
            ],
            'active' => '/admin/r/customers',
        ], $status);
    }
}
