<?php

declare(strict_types=1);

namespace Kuti\Resource;

use Kuti\CustomerInput;
use Kuti\KutiClient;
use Kuti\Subscription;

/**
 * Suscripciones: KUTI le cobra solo a tu cliente, cada periodo, sobre su Yape afiliado (máximo
 * S/ 2,500 por periodo). Parámetros en camelCase:
 * customer (CustomerInput), description, billingMode (fixed | variable), amount, items
 * ([{description, unitAmount, quantity}]), currency, frequency (DAILY | WEEKLY | MONTHLY | YEARLY),
 * interval, dayOfMonth, lastDayOfMonth, dayOfWeek, startDate, endDate, chargeTime ("HH:mm", hora
 * de Perú; nunca entre 01:00 y 03:00), retryPolicy ({intervalDays, onExhausted}),
 * externalReference, metadata, sendVia.
 */
final class Subscriptions
{
    private const FIELDS = [
        'description' => 'description',
        'billingMode' => 'billing_mode',
        'amount' => 'amount',
        'currency' => 'currency',
        'frequency' => 'frequency',
        'interval' => 'interval',
        'dayOfMonth' => 'day_of_month',
        'lastDayOfMonth' => 'last_day_of_month',
        'dayOfWeek' => 'day_of_week',
        'startDate' => 'start_date',
        'endDate' => 'end_date',
        'chargeTime' => 'charge_time',
        'externalReference' => 'external_reference',
        'metadata' => 'metadata',
        'sendVia' => 'send_via',
    ];

    public function __construct(private readonly KutiClient $client)
    {
    }

    /**
     * POST /subscriptions. Monto fijo: cobra el primer periodo al crearla (si el cliente aún no
     * tiene su Yape afiliado nace INCOMPLETE con latestCycle['checkoutUrl']). Monto variable
     * (billingMode 'variable'): no cobra nada; si falta afiliar, trae setupUrl.
     *
     * @param array<string, mixed> $params
     */
    public function create(array $params, ?string $idempotencyKey = null): Subscription
    {
        $response = $this->client->request('POST', '/subscriptions', self::toBody($params), $idempotencyKey);

        return Subscription::fromArray($response['data']);
    }

    /** GET /subscriptions/{id} — la devuelve ya puesta al día con sus cobros. */
    public function retrieve(string $id): Subscription
    {
        $response = $this->client->request('GET', self::path($id));

        return Subscription::fromArray($response['data']);
    }

    /**
     * GET /subscriptions
     *
     * @param array{status?: string, customerId?: string, page?: int, perPage?: int|string} $params
     * @return array{data: list<Subscription>, pagination: array<string, mixed>}
     */
    public function list(array $params = []): array
    {
        $query = array_filter(
            [
                'status' => $params['status'] ?? null,
                'customer_id' => $params['customerId'] ?? null,
                'page' => $params['page'] ?? null,
                'per_page' => $params['perPage'] ?? null,
            ],
            static fn (mixed $v): bool => $v !== null,
        );
        $response = $this->client->request(
            'GET',
            '/subscriptions' . ($query === [] ? '' : '?' . http_build_query($query)),
        );

        return [
            'data' => array_map(
                static fn (array $s): Subscription => Subscription::fromArray($s),
                $response['data'] ?? [],
            ),
            'pagination' => $response['pagination'] ?? [],
        ];
    }

    /**
     * PATCH /subscriptions/{id} — rige desde el próximo periodo; solo cambia lo que envías
     * (description, amount, items, endDate, chargeTime, retryPolicy, metadata).
     *
     * @param array<string, mixed> $params
     */
    public function update(string $id, array $params): Subscription
    {
        $response = $this->client->request('PATCH', self::path($id), self::toBody($params));

        return Subscription::fromArray($response['data']);
    }

    /** POST /subscriptions/{id}/pause — deja de cobrar y de reintentar. */
    public function pause(string $id): Subscription
    {
        return $this->action($id, 'pause');
    }

    /** POST /subscriptions/{id}/resume */
    public function resume(string $id): Subscription
    {
        return $this->action($id, 'resume');
    }

    /** POST /subscriptions/{id}/cancel — final; anula el cobro del periodo que siga sin pagar. */
    public function cancel(string $id): Subscription
    {
        return $this->action($id, 'cancel');
    }

    /** POST /subscriptions/{id}/retry — debita ahora el periodo más antiguo sin pagar. */
    public function retry(string $id): Subscription
    {
        return $this->action($id, 'retry');
    }

    /**
     * POST /subscriptions/{id}/charges — solo monto variable: envía el monto de un periodo y KUTI
     * lo debita. Llámalo al recibir subscription.amount_required o al cerrar tu periodo. Un solo
     * cobro por periodo (409 SUBSCRIPTION_PERIOD_ALREADY_CHARGED si lo repites).
     */
    public function charge(
        string $id,
        string $amount,
        ?string $description = null,
        ?string $period = null,
        ?string $idempotencyKey = null,
    ): Subscription {
        $body = array_filter(
            ['amount' => $amount, 'description' => $description, 'period' => $period],
            static fn (mixed $v): bool => $v !== null,
        );
        $response = $this->client->request('POST', self::path($id) . '/charges', $body, $idempotencyKey);

        return Subscription::fromArray($response['data']);
    }

    /**
     * GET /subscriptions/{id}/cycles — periodos, del más reciente al más antiguo.
     *
     * @return list<array<string, mixed>>
     */
    public function listCycles(string $id): array
    {
        $response = $this->client->request('GET', self::path($id) . '/cycles');

        return array_map(
            static fn (array $c): array => Subscription::cycleFromArray($c),
            $response['data'] ?? [],
        );
    }

    private function action(string $id, string $action): Subscription
    {
        $response = $this->client->request('POST', self::path($id) . '/' . $action);

        return Subscription::fromArray($response['data']);
    }

    private static function path(string $id): string
    {
        return '/subscriptions/' . rawurlencode($id);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private static function toBody(array $params): array
    {
        $body = [];
        foreach (self::FIELDS as $param => $field) {
            if (array_key_exists($param, $params) && $params[$param] !== null) {
                $body[$field] = $params[$param];
            }
        }
        if (isset($params['customer'])) {
            $body['customer'] = $params['customer'] instanceof CustomerInput
                ? $params['customer']->toArray()
                : $params['customer'];
        }
        if (isset($params['items'])) {
            $body['items'] = array_map(
                static fn (array $i): array => array_filter(
                    [
                        'description' => $i['description'] ?? null,
                        'unit_amount' => $i['unitAmount'] ?? null,
                        'quantity' => $i['quantity'] ?? null,
                    ],
                    static fn (mixed $v): bool => $v !== null,
                ),
                $params['items'],
            );
        }
        if (isset($params['retryPolicy'])) {
            $policy = [];
            if (array_key_exists('intervalDays', $params['retryPolicy'])) {
                // [] es válido: "no reintentar".
                $policy['interval_days'] = array_values($params['retryPolicy']['intervalDays']);
            }
            if (isset($params['retryPolicy']['onExhausted'])) {
                $policy['on_exhausted'] = $params['retryPolicy']['onExhausted'];
            }
            $body['retry_policy'] = $policy;
        }

        return $body;
    }
}
