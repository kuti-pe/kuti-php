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
}
