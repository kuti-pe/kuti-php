<?php

declare(strict_types=1);

namespace Kuti\Tests;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Kuti\CheckoutSessionCustomer;
use Kuti\CustomerInput;
use Kuti\Exception\KutiAuthenticationException;
use Kuti\Exception\KutiNotFoundException;
use Kuti\Exception\KutiRateLimitException;
use Kuti\Exception\KutiValidationException;
use Kuti\KutiClient;
use Kuti\Money;
use Kuti\PaymentIntentCustomer;
use Kuti\PaymentMethodType;
use Kuti\Exception\KutiPermissionException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class KutiClientTest extends TestCase
{
    private const SECRET_KEY = 'kuti_test_abc123';

    /**
     * @param array<int, Response> $responses
     * @param array<int, array{request: RequestInterface}> $history Parámetro de salida — se llena
     *     conforme Guzzle ejecuta requests, por eso se pasa por referencia en vez de retornarlo.
     */
    private function makeClient(array $responses, array &$history = []): KutiClient
    {
        $history = [];
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $guzzle = new GuzzleClient(['handler' => $stack]);

        return new KutiClient(self::SECRET_KEY, 'https://example.test/v1', $guzzle);
    }

    private static function jsonResponse(int $status, array $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    private static function errorEnvelope(string $code, string $message): array
    {
        return [
            'success' => false,
            'message' => $message,
            'error' => ['code' => $code, 'message' => $message, 'request_id' => 'req_test_1'],
        ];
    }

    public function testRejectsAPublishableKeyAtConstructionTime(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new KutiClient('kuti_pub_test_abc');
    }

    public function testSendsTheSecretKeyAsABearerToken(): void
    {
        $history = [];
        $client = $this->makeClient([
            self::jsonResponse(200, [
                'data' => [
                    'id' => 'pi_1',
                    'merchant_id' => 'mer_1',
                    'amount' => ['amount' => '10.00', 'currency' => 'PEN'],
                    'status' => 'PENDING',
                    'created_at' => '2026-01-01T00:00:00Z',
                ],
            ]),
        ], $history);

        $client->paymentIntents->retrieve('pi_1');

        self::assertCount(1, $history);
        /** @var RequestInterface $request */
        $request = $history[0]['request'];
        self::assertSame('https://example.test/v1/payment-intents/pi_1', (string) $request->getUri());
        self::assertSame('Bearer ' . self::SECRET_KEY, $request->getHeaderLine('Authorization'));
    }

    public function testMapsA401ResponseToKutiAuthenticationException(): void
    {
        $client = $this->makeClient([
            self::jsonResponse(401, self::errorEnvelope('INVALID_API_KEY', 'Invalid API key')),
        ]);

        $this->expectException(KutiAuthenticationException::class);
        $client->paymentIntents->retrieve('pi_1');
    }

    public function testMapsA404ResponseToKutiNotFoundExceptionWithTheOriginalCode(): void
    {
        $client = $this->makeClient([
            self::jsonResponse(404, self::errorEnvelope('PAYMENT_INTENT_NOT_FOUND', 'Not found')),
        ]);

        try {
            $client->paymentIntents->retrieve('pi_missing');
            self::fail('Expected KutiNotFoundException');
        } catch (KutiNotFoundException $e) {
            self::assertSame('PAYMENT_INTENT_NOT_FOUND', $e->getKutiCode());
            self::assertSame('req_test_1', $e->getRequestId());
        }
    }

    public function testMapsA422ResponseToKutiValidationException(): void
    {
        $client = $this->makeClient([
            self::jsonResponse(422, self::errorEnvelope('VALIDATION_ERROR', 'amount is required')),
        ]);

        $this->expectException(KutiValidationException::class);
        $client->checkoutSessions->create(new Money('', 'PEN'), []);
    }

    public function testRetriesAGetOn429AndSucceedsIfALaterAttemptWorks(): void
    {
        $history = [];
        $client = $this->makeClient([
            self::jsonResponse(429, self::errorEnvelope('RATE_LIMITED', 'Too many requests')),
            self::jsonResponse(200, [
                'data' => [
                    'id' => 'pi_1',
                    'merchant_id' => 'mer_1',
                    'amount' => ['amount' => '10.00', 'currency' => 'PEN'],
                    'status' => 'PENDING',
                    'created_at' => '2026-01-01T00:00:00Z',
                ],
            ]),
        ], $history);

        $intent = $client->paymentIntents->retrieve('pi_1');

        self::assertSame('pi_1', $intent->id);
        self::assertCount(2, $history);
    }

    public function testMapsNestedCustomerIdFromPaymentIntentResponses(): void
    {
        $client = $this->makeClient([
            self::jsonResponse(200, [
                'data' => [
                    'id' => 'pi_1',
                    'merchant_id' => 'mer_1',
                    'customer' => [
                        'id' => 'cus_01ABC',
                        'type' => 'INDIVIDUAL',
                        'name' => 'María López',
                    ],
                    'amount' => ['amount' => '50.00', 'currency' => 'PEN'],
                    'status' => 'SUCCEEDED',
                    'paid_with' => [
                        'method_type' => 'INTEROPERABLE_QR',
                        'paid_at' => '2026-01-01T00:01:00Z',
                    ],
                    'created_at' => '2026-01-01T00:00:00Z',
                ],
            ]),
        ]);

        $intent = $client->paymentIntents->retrieve('pi_1');

        self::assertSame('cus_01ABC', $intent->customerId);
        self::assertSame('INTEROPERABLE_QR', $intent->paidWith['methodType'] ?? null);
    }

    public function testDoesNotRetryAPostWithoutAnIdempotencyKeyAndThrowsKutiRateLimitException(): void
    {
        $history = [];
        $client = $this->makeClient([
            self::jsonResponse(429, self::errorEnvelope('RATE_LIMITED', 'Too many requests')),
        ], $history);

        try {
            $client->checkoutSessions->create(
                new Money('10.00', 'PEN'),
                [PaymentMethodType::InteroperableQr],
            );
            self::fail('Expected KutiRateLimitException');
        } catch (KutiRateLimitException) {
            self::assertCount(1, $history);
        }
    }

    public function testDoesRetryAPostWhenTheCallerProvidesAnIdempotencyKey(): void
    {
        $history = [];
        $client = $this->makeClient([
            self::jsonResponse(429, self::errorEnvelope('RATE_LIMITED', 'Too many requests')),
            self::jsonResponse(201, [
                'data' => [
                    'id' => 'cs_1',
                    'merchant_id' => 'mer_1',
                    'payment_intent_id' => 'pi_1',
                    'amount' => ['amount' => '10.00', 'currency' => 'PEN'],
                    'status' => 'OPEN',
                    'created_at' => '2026-01-01T00:00:00Z',
                ],
            ]),
        ], $history);

        $session = $client->checkoutSessions->create(
            new Money('10.00', 'PEN'),
            [PaymentMethodType::InteroperableQr],
            new CheckoutSessionCustomer(firstName: 'Maria', lastName: 'Lopez'),
            idempotencyKey: 'order-42',
        );

        self::assertSame('cs_1', $session->id);
        self::assertCount(2, $history);
        /** @var RequestInterface $firstRequest */
        $firstRequest = $history[0]['request'];
        self::assertSame('order-42', $firstRequest->getHeaderLine('Idempotency-Key'));
    }

    public function testCreatePaymentIntentSendsCustomerAndIdempotencyKey(): void
    {
        $history = [];
        $client = $this->makeClient([
            self::jsonResponse(201, [
                'data' => [
                    'id' => 'pi_created',
                    'merchant_id' => 'mer_1',
                    'customer' => ['id' => 'cus_1'],
                    'amount' => ['amount' => '50.00', 'currency' => 'PEN'],
                    'status' => 'PENDING',
                    'checkout_url' => 'https://pay.kuti.pe/c/ABC',
                    'created_at' => '2026-01-01T00:00:00Z',
                ],
            ]),
        ], $history);

        $intent = $client->paymentIntents->create(
            amount: new Money('50.00', 'PEN'),
            paymentMethodTypes: [PaymentMethodType::InteroperableQr, PaymentMethodType::BankTransfer],
            customer: new PaymentIntentCustomer(
                type: 'INDIVIDUAL',
                firstName: 'María',
                lastName: 'López',
                email: 'maria@example.com',
                document: ['type' => 'DNI', 'number' => '45678912'],
            ),
            description: 'Pedido #1042',
            idempotencyKey: 'order-1042',
        );

        self::assertSame('pi_created', $intent->id);
        self::assertSame('cus_1', $intent->customerId);
        self::assertCount(1, $history);
        /** @var RequestInterface $request */
        $request = $history[0]['request'];
        self::assertSame('https://example.test/v1/payment-intents', (string) $request->getUri());
        self::assertSame('order-1042', $request->getHeaderLine('Idempotency-Key'));
        $body = json_decode((string) $request->getBody(), true);
        self::assertSame('María', $body['customer']['first_name']);
        self::assertSame(['type' => 'DNI', 'number' => '45678912'], $body['customer']['document']);
    }

    public function testCreateCustomerWithDocumentAndCustomFields(): void
    {
        $history = [];
        $client = $this->makeClient([
            self::jsonResponse(201, [
                'data' => [
                    'id' => 'cus_new',
                    'merchant_id' => 'mer_1',
                    'type' => 'INDIVIDUAL',
                    'first_name' => 'María',
                    'document' => ['type' => 'DNI', 'number' => '45678912', 'country' => 'PE'],
                    'custom_fields' => ['grade' => 'quinto'],
                    'created_at' => '2026-01-01T00:00:00Z',
                ],
            ]),
        ], $history);

        $customer = $client->customers->create(new CustomerInput(
            type: 'INDIVIDUAL',
            firstName: 'María',
            lastName: 'López',
            document: ['number' => '45678912'],
            customFields: ['grade' => '5to grado'],
        ));

        self::assertSame('cus_new', $customer->id);
        self::assertSame(['grade' => 'quinto'], $customer->customFields);
        self::assertSame('45678912', $customer->document['number']);
        /** @var RequestInterface $request */
        $request = $history[0]['request'];
        self::assertSame('https://example.test/v1/customers', (string) $request->getUri());
        $body = json_decode((string) $request->getBody(), true);
        self::assertSame(['number' => '45678912'], $body['document']);
        self::assertSame(['grade' => '5to grado'], $body['custom_fields']);
    }

    public function testUpdateCustomerSendsNullToRemoveACustomField(): void
    {
        $history = [];
        $client = $this->makeClient([
            self::jsonResponse(200, [
                'data' => [
                    'id' => 'cus_1',
                    'merchant_id' => 'mer_1',
                    'type' => 'INDIVIDUAL',
                    'custom_fields' => ['grade' => 'sexto'],
                    'created_at' => '2026-01-01T00:00:00Z',
                ],
            ]),
        ], $history);

        $client->customers->update('cus_1', ['customFields' => ['grade' => 'sexto', 'birth_date' => null]]);

        /** @var RequestInterface $request */
        $request = $history[0]['request'];
        self::assertSame('PATCH', $request->getMethod());
        $body = json_decode((string) $request->getBody(), true);
        self::assertSame(['grade' => 'sexto', 'birth_date' => null], $body['custom_fields']);
    }

    public function testCreatesAPaymentLinkAndSendsEmptyQuestionList(): void
    {
        $history = [];
        $client = $this->makeClient([
            self::jsonResponse(201, [
                'data' => [
                    'id' => 'plink_1',
                    'merchant_id' => 'mer_1',
                    'livemode' => false,
                    'slug' => 'donacion',
                    'url' => 'https://pay.kuti.pe/l/donacion',
                    'title' => 'Donación',
                    'template' => 'DONATION',
                    'pricing' => 'CUSTOMER_CHOOSES',
                    'currency' => 'PEN',
                    'min_amount' => '5.00',
                    'suggested_amounts' => ['20.00', '50.00'],
                    'payment_method_types' => ['INTEROPERABLE_QR'],
                    'status' => 'ACTIVE',
                    'views_count' => 0,
                    'created_at' => '2026-01-01T00:00:00Z',
                    'updated_at' => '2026-01-01T00:00:00Z',
                ],
            ]),
        ], $history);

        $link = $client->paymentLinks->create([
            'title' => 'Donación',
            'template' => 'DONATION',
            'pricing' => 'CUSTOMER_CHOOSES',
            'minAmount' => '5.00',
            'suggestedAmounts' => ['20.00', '50.00'],
            'paymentMethodTypes' => ['INTEROPERABLE_QR'],
            'customerFieldIds' => [],
        ]);

        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/v1/payment-links', $request->getUri()->getPath());
        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame([], $body['customer_field_ids']);
        $this->assertSame('5.00', $body['min_amount']);
        $this->assertSame('https://pay.kuti.pe/l/donacion', $link->url);
        $this->assertTrue($link->isActive());
    }

    public function testFiltersIntentsBySourceAndSendsSendVia(): void
    {
        $history = [];
        $client = $this->makeClient([
            self::jsonResponse(200, ['data' => [], 'pagination' => ['page' => 1, 'per_page' => 25, 'total' => 0, 'total_pages' => 0]]),
            self::jsonResponse(201, [
                'data' => [
                    'id' => 'pi_1',
                    'merchant_id' => 'mer_1',
                    'amount' => ['amount' => '10.00', 'currency' => 'PEN'],
                    'status' => 'PENDING',
                    'send_via' => [],
                    'created_at' => '2026-01-01T00:00:00Z',
                ],
            ]),
        ], $history);

        $client->paymentIntents->list(['source' => 'link', 'paymentLinkId' => 'plink_1']);
        parse_str($history[0]['request']->getUri()->getQuery(), $query);
        $this->assertSame('link', $query['source']);
        $this->assertSame('plink_1', $query['payment_link_id']);

        $intent = $client->paymentIntents->create(
            new Money('10.00', 'PEN'),
            [PaymentMethodType::InteroperableQr],
            sendVia: [],
        );
        $body = json_decode((string) $history[1]['request']->getBody(), true);
        $this->assertSame([], $body['send_via']);
        $this->assertSame([], $intent->sendVia);
    }

    public function testA403ExposesTheKindOfPermissionProblemAndTheCorrelationId(): void
    {
        $client = $this->makeClient([
            self::jsonResponse(403, [
                'success' => false,
                'message' => 'Sin permiso',
                'error' => [
                    'code' => 'API_KEY_NOT_ALLOWED',
                    'message' => 'Este endpoint es solo del panel de KUTI.',
                    'request_id' => 'req_1',
                    'correlation_id' => 'pedido-1042',
                ],
            ]),
        ]);

        try {
            $client->paymentIntents->retrieve('pi_1');
            self::fail('Expected KutiPermissionException');
        } catch (KutiPermissionException $e) {
            self::assertTrue($e->isDashboardOnly());
            self::assertFalse($e->isInsufficientScope());
            self::assertSame('req_1', $e->getRequestId());
            self::assertSame('pedido-1042', $e->getCorrelationId());
        }
    }

    public function testListsAndResolvesPaymentExceptions(): void
    {
        $row = [
            'id' => 'pexc_1', 'merchant_id' => 'mer_1', 'livemode' => false, 'payment_intent_id' => 'pi_1',
            'payment_method_type' => 'BANK_TRANSFER', 'amount' => ['amount' => '250.00', 'currency' => 'PEN'],
            'reason' => 'DUPLICATE', 'status' => 'OPEN', 'created_at' => '2026-09-29T15:20:00Z',
        ];
        $history = [];
        $client = $this->makeClient([
            self::jsonResponse(200, ['data' => [$row], 'pagination' => ['page' => 1, 'total' => 1]]),
            self::jsonResponse(200, ['data' => array_merge($row, ['status' => 'REFUNDED', 'resolution_note' => 'Devuelto'])]),
        ], $history);

        $list = $client->paymentExceptions->list(['status' => 'OPEN', 'paymentIntentId' => 'pi_1']);
        $resolved = $client->paymentExceptions->resolve('pexc_1', 'REFUNDED', 'Devuelto');

        self::assertSame('/v1/payment-exceptions', $history[0]['request']->getUri()->getPath());
        self::assertSame('status=OPEN&payment_intent_id=pi_1', $history[0]['request']->getUri()->getQuery());
        self::assertSame('DUPLICATE', $list['data'][0]->reason);
        self::assertSame('250.00', $list['data'][0]->amount->amount);
        self::assertSame(['status' => 'REFUNDED', 'note' => 'Devuelto'], json_decode((string) $history[1]['request']->getBody(), true));
        self::assertSame('REFUNDED', $resolved->status);
        self::assertSame('Devuelto', $resolved->resolutionNote);
    }

    public function testDiagnosticsAndCustomerCode(): void
    {
        $history = [];
        $client = $this->makeClient([
            self::jsonResponse(200, ['data' => ['request' => ['id' => 'req_1', 'status' => 201], 'events' => []]]),
            self::jsonResponse(200, ['data' => ['payment_intent_id' => 'pi_1', 'timeline' => []]]),
            self::jsonResponse(200, ['data' => [
                'id' => 'cus_1', 'merchant_id' => 'mer_1', 'code' => 'ZIZE00001', 'type' => 'INDIVIDUAL',
                'created_at' => '2026-01-01T00:00:00Z',
            ]]),
        ], $history);

        $diagnosis = $client->diagnostics->getRequest('req_1');
        $trace = $client->diagnostics->tracePaymentIntent('pi_1');
        $customer = $client->customers->retrieve('cus_1');

        self::assertSame(201, $diagnosis['request']['status']);
        self::assertSame('/v1/diagnostics/payment-intents/pi_1/trace', $history[1]['request']->getUri()->getPath());
        self::assertSame('pi_1', $trace['payment_intent_id']);
        self::assertSame('ZIZE00001', $customer->code);
    }

    public function testSubscriptionsCreateChargeAndMapTheLatestCycle(): void
    {
        $history = [];
        $client = $this->makeClient([
            self::jsonResponse(201, ['data' => [
                'id' => 'sub_1', 'merchant_id' => 'mer_1', 'description' => 'Plan Pro', 'billing_mode' => 'fixed',
                'amount' => ['amount' => '99.00', 'currency' => 'PEN'],
                'items' => [['description' => 'Plan Pro', 'unit_amount' => '99.00', 'quantity' => 1, 'amount' => '99.00']],
                'frequency' => 'MONTHLY', 'interval' => 1, 'start_date' => '2026-10-05', 'charge_time' => '09:00',
                'status' => 'INCOMPLETE',
                'retry_policy' => ['interval_days' => [1, 3, 5], 'on_exhausted' => 'past_due'],
                'latest_cycle' => [
                    'id' => 'subc_1', 'billing_period' => '2026-10', 'due_date' => '2026-10-05',
                    'amount' => ['amount' => '99.00', 'currency' => 'PEN'], 'status' => 'OPEN', 'attempts' => 0,
                    'last_failure_code' => 'payment_method_required', 'payment_intent_id' => 'pi_1',
                    'checkout_url' => 'https://pay.kuti.pe/c/ABC',
                ],
                'created_at' => '2026-10-05T14:00:00Z',
            ]]),
            self::jsonResponse(200, ['data' => [
                'id' => 'sub_2', 'merchant_id' => 'mer_1', 'description' => 'LIA por consumo',
                'billing_mode' => 'variable', 'frequency' => 'MONTHLY', 'interval' => 1,
                'start_date' => '2026-09-05', 'status' => 'ACTIVE', 'created_at' => '2026-09-05T14:00:00Z',
            ]]),
            self::jsonResponse(200, ['data' => [
                'id' => 'sub_2', 'merchant_id' => 'mer_1', 'description' => 'LIA por consumo',
                'billing_mode' => 'variable', 'frequency' => 'MONTHLY', 'interval' => 1,
                'start_date' => '2026-09-05', 'status' => 'ACTIVE', 'created_at' => '2026-09-05T14:00:00Z',
            ]]),
        ], $history);

        $sub = $client->subscriptions->create([
            'customer' => new CustomerInput(id: 'cus_1'),
            'description' => 'Plan Pro',
            'amount' => '99.00',
            'frequency' => 'MONTHLY',
            'chargeTime' => '09:00',
            'retryPolicy' => ['intervalDays' => [], 'onExhausted' => 'cancel'],
        ], 'alta-1');
        $charged = $client->subscriptions->charge('sub_2', '184.00', period: '2026-10');

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        self::assertSame('/v1/subscriptions', $history[0]['request']->getUri()->getPath());
        self::assertSame('alta-1', $history[0]['request']->getHeaderLine('Idempotency-Key'));
        self::assertSame('cus_1', $body['customer']['id']);
        self::assertSame('09:00', $body['charge_time']);
        self::assertSame(['interval_days' => [], 'on_exhausted' => 'cancel'], $body['retry_policy']);
        self::assertSame('INCOMPLETE', $sub->status);
        self::assertSame('99.00', $sub->amount?->amount);
        self::assertSame([1, 3, 5], $sub->retryPolicy['intervalDays']);
        self::assertSame('payment_method_required', $sub->latestCycle['lastFailureCode']);
        self::assertSame('https://pay.kuti.pe/c/ABC', $sub->latestCycle['checkoutUrl']);

        self::assertSame('/v1/subscriptions/sub_2/charges', $history[1]['request']->getUri()->getPath());
        self::assertSame(
            ['amount' => '184.00', 'period' => '2026-10'],
            json_decode((string) $history[1]['request']->getBody(), true),
        );
        self::assertSame('variable', $charged->billingMode);
        self::assertNull($charged->amount);

        $client->subscriptions->retry('sub_2', 'retry-1');
        self::assertSame('/v1/subscriptions/sub_2/retry', $history[2]['request']->getUri()->getPath());
        self::assertSame('retry-1', $history[2]['request']->getHeaderLine('Idempotency-Key'));
    }

    public function testSavedPaymentMethodsAndDirectCharge(): void
    {
        $history = [];
        $client = $this->makeClient([
            self::jsonResponse(200, ['data' => [
                ['id' => 'pm_1', 'type' => 'YAPE', 'status' => 'ACTIVE', 'display' => ['phone_last4' => '2011']],
            ]]),
            self::jsonResponse(201, ['data' => [
                'id' => 'pi_1', 'merchant_id' => 'mer_1', 'amount' => ['amount' => '80.00', 'currency' => 'PEN'],
                'status' => 'PENDING', 'saved_payment_methods' => ['status' => 'disabled'],
                'last_saved_method_payment' => ['status' => 'FAILED', 'failure_code' => 'insufficient_funds'],
                'created_at' => '2026-10-05T14:00:00Z',
            ]]),
            self::jsonResponse(201, ['data' => ['customer_session_secret' => 'cuss_secret_x', 'expires_at' => '2026-10-05T14:30:00Z']]),
        ], $history);

        $methods = $client->customers->listPaymentMethods('cus_1');
        $pi = $client->paymentIntents->create(
            amount: new Money('80.00', 'PEN'),
            paymentMethodTypes: [PaymentMethodType::Yape],
            customer: new CustomerInput(id: 'cus_1'),
            paymentMethod: $methods[0]['id'],
            confirm: true,
        );
        $session = $client->paymentIntents->createCustomerSession('pi_1');

        self::assertSame('/v1/customers/cus_1/payment-methods', $history[0]['request']->getUri()->getPath());
        self::assertSame('2011', $methods[0]['phoneLast4']);
        $body = json_decode((string) $history[1]['request']->getBody(), true);
        self::assertSame(['YAPE'], $body['payment_method_types']);
        self::assertSame('pm_1', $body['payment_method']);
        self::assertTrue($body['confirm']);
        self::assertSame('insufficient_funds', $pi->lastSavedMethodPayment['failureCode']);
        self::assertSame('/v1/payment-intents/pi_1/customer-session', $history[2]['request']->getUri()->getPath());
        self::assertSame('cuss_secret_x', $session['customerSessionSecret']);
    }

    public function testSendsIdempotencyKeyOnCustomersPaymentLinksWhatsAppAndDeliveryRetry(): void
    {
        $history = [];
        $client = $this->makeClient([
            self::jsonResponse(201, ['data' => [
                'id' => 'cus_1', 'merchant_id' => 'mer_1', 'type' => 'INDIVIDUAL', 'first_name' => 'Ana',
                'created_at' => '2026-01-01T00:00:00Z',
            ]]),
            self::jsonResponse(201, ['data' => [
                'id' => 'plink_1', 'merchant_id' => 'mer_1', 'livemode' => false, 'slug' => 'taller-excel',
                'url' => 'https://pay.kuti.pe/l/taller-excel', 'title' => 'Taller de Excel', 'template' => 'COURSE',
                'pricing' => 'FIXED', 'currency' => 'PEN', 'amount' => '120.00',
                'payment_method_types' => ['INTEROPERABLE_QR'], 'status' => 'ACTIVE',
                'created_at' => '2026-01-01T00:00:00Z', 'updated_at' => '2026-01-01T00:00:00Z',
            ]]),
            new Response(204),
            self::jsonResponse(200, ['data' => ['id' => 'whd_1', 'event_id' => 'evt_1', 'status' => 'PENDING']]),
        ], $history);

        $client->customers->create(new CustomerInput(type: 'INDIVIDUAL', firstName: 'Ana'), null, 'alta-ana');
        $client->paymentLinks->create(
            ['title' => 'Taller de Excel', 'pricing' => 'FIXED', 'amount' => '120.00', 'paymentMethodTypes' => ['INTEROPERABLE_QR']],
            'link-taller',
        );
        $client->paymentIntents->sendWhatsApp('pi_1', '+51987654321', null, 'wa-pi_1');
        $client->webhookDeliveries->retry('whd_1', 'retry-whd_1');

        $sent = array_map(
            static fn (array $h): array => [$h['request']->getUri()->getPath(), $h['request']->getHeaderLine('Idempotency-Key')],
            $history,
        );
        self::assertSame([
            ['/v1/customers', 'alta-ana'],
            ['/v1/payment-links', 'link-taller'],
            ['/v1/payment-intents/pi_1/send-whatsapp', 'wa-pi_1'],
            ['/v1/webhook-deliveries/whd_1/retry', 'retry-whd_1'],
        ], $sent);
    }

    public function testSendsNoIdempotencyKeyWhenTheCallerDoesNotGiveOne(): void
    {
        $history = [];
        $client = $this->makeClient([
            self::jsonResponse(201, ['data' => [
                'id' => 'cus_1', 'merchant_id' => 'mer_1', 'type' => 'INDIVIDUAL', 'first_name' => 'Ana',
                'created_at' => '2026-01-01T00:00:00Z',
            ]]),
        ], $history);

        $client->customers->create(new CustomerInput(type: 'INDIVIDUAL', firstName: 'Ana'));

        self::assertFalse($history[0]['request']->hasHeader('Idempotency-Key'));
    }
}
