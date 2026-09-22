# kuti-pe/kuti-php

SDK oficial de KUTI para PHP. Crea sesiones de checkout, consulta el estado de un pago y verifica webhooks — sin reimplementar auth, manejo de errores ni firma HMAC a mano.

> **Nunca expongas tu secret key** (`kuti_live_...` / `kuti_test_...`) en código que se sirva al navegador. Este SDK solo debe usarse desde tu backend.

## Instalación

```bash
composer require kuti-pe/kuti-php
```

## Quickstart

```php
use Kuti\KutiClient;
use Kuti\Money;
use Kuti\PaymentMethodType;
use Kuti\CheckoutSessionCustomer;

$kuti = new KutiClient($_ENV['KUTI_SECRET_KEY']);

$session = $kuti->checkoutSessions->create(
    amount: new Money('249.90', 'PEN'),
    paymentMethodTypes: [PaymentMethodType::InteroperableQr],
    customer: new CheckoutSessionCustomer(id: 'cus_01ABC'),
    // customer: new CheckoutSessionCustomer(name: 'María López', email: 'maria@example.com'),
    description: 'Zapatillas running talla 42',
    idempotencyKey: "order-{$orderId}",
);

// window.Kuti.open({ checkoutUrl: $session->checkoutUrl, onSuccess, onFailure })
echo json_encode(['checkoutUrl' => $session->checkoutUrl]);
```

## Confirmar un pago

```php
$intent = $kuti->paymentIntents->retrieve($paymentIntentId);
if ($intent->isPaid()) {
    // fulfill order
}
```

## Verificar un webhook

```php
use Kuti\Webhooks;
use Kuti\Exception\KutiSignatureVerificationException;

$payload = file_get_contents('php://input'); // el string CRUDO, sin json_decode antes de verificar

try {
    Webhooks::verifySignature(
        $payload,
        $_SERVER['HTTP_X_KUTI_SIGNATURE'],
        $_SERVER['HTTP_X_KUTI_TIMESTAMP'],
        $_ENV['KUTI_WEBHOOK_SECRET'],
    );
} catch (KutiSignatureVerificationException $e) {
    http_response_code(400);
    exit('Invalid signature');
}

$event = json_decode($payload, true);
// procesa $event['type'] (payment.succeeded, checkout.session.completed, ...)
http_response_code(200);
```

## Manejo de errores

Todas las excepciones de la API extienden `KutiApiException` (`getStatus()`, `getKutiCode()`, `getRequestId()`, `getDocUrl()`, `getDetails()`). Hay subclases para los casos más comunes:

```php
use Kuti\Exception\KutiValidationException;
use Kuti\Exception\KutiNotFoundException;
use Kuti\Exception\KutiApiException;

try {
    $kuti->checkoutSessions->create(...);
} catch (KutiValidationException $e) {
    // $e->getDetails() => [['field' => 'amount.amount', 'code' => 'MUST_BE_POSITIVE', ...]]
} catch (KutiNotFoundException $e) {
    // ...
} catch (KutiApiException $e) {
    error_log("{$e->getKutiCode()} request_id={$e->getRequestId()}"); // para reportar a soporte
}
```

Los `GET` y los `create()` con `idempotencyKey` se reintentan automáticamente en errores de red o `429`/`503`. Un `create()` sin `idempotencyKey` nunca se reintenta, para no duplicar un cobro.

## Cliente HTTP

Por defecto se usa Guzzle. Si ya tienes tu propio cliente configurado (proxy, logging, etc.), puedes inyectarlo — solo debe implementar `GuzzleHttp\ClientInterface`:

```php
$kuti = new KutiClient($secretKey, httpClient: $miClienteGuzzlePersonalizado);
```

## API

- `new KutiClient(string $secretKey, ?string $baseUrl = null, ?ClientInterface $httpClient = null)`
- `$kuti->checkoutSessions->create(...)` — Checkout.js
- `$kuti->paymentIntents->create(...)` — cobro directo
- `$kuti->paymentIntents->list(...)`
- `$kuti->paymentIntents->retrieve(string $id)`
- `$kuti->paymentIntents->cancel(string $id)`
- `$kuti->paymentIntents->sendWhatsApp(...)`
- `Webhooks::verifySignature(...)`
- `Webhooks::verifySignature($payload, $signatureHeader, $timestampHeader, $secret, $toleranceSeconds = 300)`

## Tests

```bash
composer install
composer test
```
