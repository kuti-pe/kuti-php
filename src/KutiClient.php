<?php

declare(strict_types=1);

namespace Kuti;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Kuti\Exception\KutiApiException;
use Kuti\Exception\KutiConnectionException;
use Kuti\Resource\CheckoutSessions;
use Kuti\Resource\Customers;
use Kuti\Resource\PaymentIntents;

/**
 * Cliente HTTP central de KUTI. Cuelgan de aquí los recursos (checkoutSessions, customers, paymentIntents);
 * esta clase solo resuelve auth, reintentos y mapeo de errores — cada recurso solo arma su propio
 * path/body.
 */
final class KutiClient
{
    private const DEFAULT_BASE_URL = 'https://api.kuti.pe/v1';
    private const SECRET_KEY_PREFIXES = ['kuti_live_', 'kuti_test_'];
    private const MAX_RETRIES = 2;
    private const RETRYABLE_STATUS = [429, 503];

    private readonly ClientInterface $http;
    private readonly string $secretKey;
    private readonly string $baseUrl;

    public readonly CheckoutSessions $checkoutSessions;
    public readonly Customers $customers;
    public readonly PaymentIntents $paymentIntents;

    public function __construct(string $secretKey, ?string $baseUrl = null, ?ClientInterface $httpClient = null)
    {
        $hasValidPrefix = false;
        foreach (self::SECRET_KEY_PREFIXES as $prefix) {
            if (str_starts_with($secretKey, $prefix)) {
                $hasValidPrefix = true;
                break;
            }
        }
        if (!$hasValidPrefix) {
            throw new \InvalidArgumentException(
                'KutiClient expects a secret key (kuti_live_... / kuti_test_...), not a publishable key.',
            );
        }

        $this->secretKey = $secretKey;
        $this->baseUrl = rtrim($baseUrl ?? self::DEFAULT_BASE_URL, '/');
        $this->http = $httpClient ?? new GuzzleClient();

        $this->checkoutSessions = new CheckoutSessions($this);
        $this->customers = new Customers($this);
        $this->paymentIntents = new PaymentIntents($this);
    }

    /**
     * @internal usado por los recursos — no lo llames directo.
     * @param array<string, mixed>|\stdClass|null $body
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, array|\stdClass|null $body = null, ?string $idempotencyKey = null): array
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->secretKey,
            'Accept' => 'application/json',
        ];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        // Solo se reintenta si el request es idempotente por diseño (GET) o el caller ya
        // proveyó una Idempotency-Key — nunca se reintenta un POST "a ciegas", eso duplicaría cobros.
        $canRetry = $method === 'GET' || $idempotencyKey !== null;
        $maxAttempts = $canRetry ? self::MAX_RETRIES + 1 : 1;

        $lastException = null;
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            if ($attempt > 0) {
                usleep((int) ((2 ** $attempt) * 200_000));
            }
            try {
                $response = $this->http->request($method, $this->baseUrl . $path, [
                    'headers' => $headers,
                    'json' => $body,
                    'http_errors' => false,
                ]);

                $status = $response->getStatusCode();
                $decoded = json_decode((string) $response->getBody(), true) ?? [];

                if ($status >= 200 && $status < 300) {
                    return $decoded;
                }

                $errorBody = $decoded['error'] ?? [];
                $apiException = KutiApiException::forStatus(
                    $status,
                    $errorBody['message'] ?? $decoded['message'] ?? 'Unknown error',
                    $errorBody['code'] ?? 'UNKNOWN_ERROR',
                    $errorBody['request_id'] ?? null,
                    $errorBody['doc_url'] ?? null,
                    $errorBody['details'] ?? [],
                );

                if ($canRetry && in_array($status, self::RETRYABLE_STATUS, true) && $attempt < self::MAX_RETRIES) {
                    $lastException = $apiException;
                    continue;
                }
                throw $apiException;
            } catch (ConnectException $e) {
                $lastException = new KutiConnectionException('Could not reach the KUTI API.', $e);
                if (!$canRetry) {
                    throw $lastException;
                }
            } catch (RequestException|GuzzleException $e) {
                throw new KutiConnectionException('Could not reach the KUTI API.', $e);
            }
        }

        throw $lastException ?? new KutiConnectionException('Could not reach the KUTI API.');
    }
}
