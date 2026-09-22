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

// El monto SIEMPRE se resuelve en tu backend — nunca confíes en un monto
// que te mande el navegador del comprador.
$session = $kuti->checkoutSessions->create(
    amount: new Money('249.90', 'PEN'),
    paymentMethodTypes: [PaymentMethodType::InteroperableQr],
    customer: new CheckoutSessionCustomer(id: 'cus_01ABC'), // existente — si viene id, se ignora el resto
    // customer: new CheckoutSessionCustomer(name: 'María López', email: 'maria@example.com'),
    description: 'Zapatillas running talla 42',
    idempotencyKey: "order-{$orderId}", // evita duplicar el cobro si reintentas el request
);

// Envía $session->checkoutUrl al frontend y ábrelo con KUTI.js:
//   window.Kuti.open({ checkoutUrl: "...", onSuccess, onFailure });
echo json_encode(['checkoutUrl' => $session->checkoutUrl]);
```

## Confirmar un pago (sin necesitar webhooks)

`onSuccess` de KUTI.js corre en el navegador del comprador — no es confiable por sí solo. Vuelve a preguntarle a la API:

```php
$intent = $kuti->paymentIntents->retrieve($paymentIntentId);
if ($intent->isPaid()) {
    // entrega el producto / activa el servicio
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
- `$kuti->checkoutSessions->create(Money $amount, array $paymentMethodTypes, ...)`
- `$kuti->paymentIntents->create(Money $amount, array $paymentMethodTypes, ...)`
- `$kuti->paymentIntents->retrieve(string $id)`
- `Webhooks::verifySignature($payload, $signatureHeader, $timestampHeader, $secret, $toleranceSeconds = 300)`

## Tests

```bash
composer install
composer test
```
