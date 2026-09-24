<?php

declare(strict_types=1);

namespace Kuti\Resource;

use Kuti\Customer;
use Kuti\CustomerInput;
use Kuti\KutiClient;

final class Customers
{
    public function __construct(private readonly KutiClient $client)
    {
    }

    /**
     * POST /customers — 409 CUSTOMER_ALREADY_EXISTS if the externalId or document already exists.
     *
     * @param array<string, string>|null $metadata
     */
    public function create(CustomerInput $customer, ?array $metadata = null): Customer
    {
        $body = $customer->toArray();
        unset($body['id']);
        if ($metadata !== null) {
            $body['metadata'] = $metadata;
        }
        $response = $this->client->request('POST', '/customers', $body);

        return Customer::fromArray($response['data']);
    }

    /** GET /customers/{id} */
    public function retrieve(string $id): Customer
    {
        $response = $this->client->request('GET', '/customers/' . rawurlencode($id));

        return Customer::fromArray($response['data']);
    }

    /**
     * PATCH /customers/{id} — only the fields you send change. Type, document and externalId
     * cannot be edited. customFields: only the keys you send change; null removes a value.
     *
     * @param array{
     *   firstName?: string,
     *   lastName?: string,
     *   companyName?: string,
     *   email?: string,
     *   phone?: string,
     *   metadata?: array<string, string>,
     *   customFields?: array<string, mixed>
     * } $params
     */
    public function update(string $id, array $params): Customer
    {
        $map = [
            'firstName' => 'first_name',
            'lastName' => 'last_name',
            'companyName' => 'company_name',
            'email' => 'email',
            'phone' => 'phone',
            'metadata' => 'metadata',
            'customFields' => 'custom_fields',
        ];
        $body = [];
        foreach ($map as $param => $field) {
            if (array_key_exists($param, $params)) {
                $body[$field] = $params[$param];
            }
        }
        $response = $this->client->request('PATCH', '/customers/' . rawurlencode($id), $body);

        return Customer::fromArray($response['data']);
    }

    /**
     * GET /customers
     *
     * @param array{q?: string, page?: int, perPage?: int|string} $params
     * @return array{data: list<Customer>, pagination: array<string, mixed>}
     */
    public function list(array $params = []): array
    {
        $query = array_filter(
            [
                'q' => $params['q'] ?? null,
                'page' => $params['page'] ?? null,
                'per_page' => $params['perPage'] ?? null,
            ],
            static fn (mixed $v): bool => $v !== null,
        );
        $path = '/customers' . ($query === [] ? '' : '?' . http_build_query($query));
        $response = $this->client->request('GET', $path);

        return [
            'data' => array_map(
                static fn (array $c): Customer => Customer::fromArray($c),
                $response['data'] ?? [],
            ),
            'pagination' => $response['pagination'] ?? [],
        ];
    }

    /**
     * DELETE /customers/{id} — archived instead of deleted if it has payment intents.
     *
     * @return array{deleted: bool, archived: bool, paymentIntentsCount: int}
     */
    public function delete(string $id): array
    {
        $response = $this->client->request('DELETE', '/customers/' . rawurlencode($id));

        return [
            'deleted' => (bool) ($response['deleted'] ?? false),
            'archived' => (bool) ($response['archived'] ?? false),
            'paymentIntentsCount' => (int) ($response['payment_intents_count'] ?? 0),
        ];
    }
}
