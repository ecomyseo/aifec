<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Erp\Api;

use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Modules\Customers\Customers;
use Aifec\Modules\Erp\ApiException;
use Aifec\Modules\Erp\Present;

/**
 * Clientes para el ERP. Se casan por correo; nunca se toca una contraseña, un consentimiento de publicidad ni un grupo.
 */
final class CustomersApi extends ApiBase
{
    /** Los únicos campos que el ERP puede escribir. */
    private const WRITABLE = ['first_name', 'last_name', 'company', 'vat_id', 'phone', 'locale'];

    /** @param array<string,string> $params */
    public function index(Request $request, array $params): Response
    {
        $limit = $this->limit($request);
        $after = $this->cursor($request);
        $sql = 'SELECT id FROM {customers} WHERE id > ?';
        $args = [$after];
        $since = $request->get('updated_since');
        if (is_string($since) && $since !== '') {
            $when = Present::toSql($since);
            if ($when === null) {
                throw new ApiException(400, 'invalid_date', 'updated_since must be an ISO 8601 date', 'updated_since');
            }
            $sql .= ' AND updated_at >= ?';
            $args[] = $when;
        }
        $args[] = $limit + 1;
        $ids = array_map('intval', $this->app->db()->column($sql . ' ORDER BY id ASC LIMIT ?', $args));

        return $this->page($request, $ids, $limit, $after, function (int $id): ?array {
            return $this->presentId($id);
        });
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params): Response
    {
        $present = $this->presentId((int) $params['id']);
        if ($present === null) {
            throw ApiException::notFound('customer_not_found', 'There is no customer with that identifier');
        }

        return $this->ok($present);
    }

    /** @param array<string,string> $params */
    public function put(Request $request, array $params): Response
    {
        $body = $this->body($request);
        $email = $this->requireString($body['email'] ?? null, 'email', 191);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw ApiException::invalid('The email address is not valid', 'email');
        }
        $data = [];
        foreach (self::WRITABLE as $field) {
            if (array_key_exists($field, $body)) {
                if ($body[$field] !== null && !is_string($body[$field])) {
                    throw ApiException::invalid('The field ' . $field . ' must be text', $field);
                }
                // Un campo vacío no borra nada: el ERP solo escribe lo que trae.
                if ($body[$field] !== null && trim($body[$field]) !== '') {
                    $data[$field] = trim($body[$field]);
                }
            }
        }
        $customers = $this->customers();
        $existing = $customers->findByEmail($email);
        try {
            if ($existing === null) {
                $id = $customers->ensureGuest($email, $data);
                $created = true;
            } else {
                $id = (int) $existing['id'];
                $created = false;
                if ($data !== []) {
                    $customers->update($id, $data);
                }
            }
        } catch (\InvalidArgumentException $e) {
            throw ApiException::invalid($e->getMessage());
        }
        $this->activity($request, $created ? 'erp.customer.created' : 'erp.customer.updated', 'customer', (string) $id, $email);
        $present = $this->presentId($id);

        return $this->ok($present, $created ? 201 : 200);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function presentId(int $id): ?array
    {
        $customer = $this->customers()->find($id);

        return $customer === null ? null : Present::customer($customer, $this->customers()->addresses($id));
    }

    private function customers(): Customers
    {
        $customers = $this->app->service('customers');
        if (!$customers instanceof Customers) {
            throw new \LogicException('The customers service is not available');
        }

        return $customers;
    }
}
