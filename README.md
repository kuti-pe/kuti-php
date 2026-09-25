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
    // customer: new CheckoutSessionCustomer(firstName: 'María', lastName: 'López', email: 'maria@example.com'),
    description: 'Zapatillas running talla 42',
    idempotencyKey: "order-{$orderId}",
);

// window.Kuti.open({ checkoutUrl: $session->checkoutUrl, onSuccess, onFailure })
echo json_encode(['checkoutUrl' => $session->checkoutUrl]);
```


## Clientes y campos personalizados

El cliente tiene la **misma forma** en `customers->create`, en el `customer` de un cobro y en el de
una checkout session. `customFields` son los campos que el negocio definió en
**Ajustes → Clientes → Campos** (la key de cada campo):

```php
use Kuti\CustomerInput;
use Kuti\PaymentIntentCustomer;

$customer = $kuti->customers->create(new CustomerInput(
    type: 'INDIVIDUAL',
    firstName: 'María',
    lastName: 'López',
    document: ['type' => 'DNI', 'number' => '45678912'], // type opcional: se deduce del número
    email: 'maria@example.com',
    customFields: ['grade' => 'quinto', 'student_code' => '2026-00781'],
));

// En un cobro: se reutiliza el cliente por id → externalId → documento, o se crea.
$kuti->paymentIntents->create(
    amount: new Money('250.00', 'PEN'),
    paymentMethodTypes: [PaymentMethodType::InteroperableQr],
    customer: new PaymentIntentCustomer(document: ['number' => '45678912'], customFields: ['grade' => 'sexto']),
    description: 'Pensión marzo',
    idempotencyKey: 'pension-2026-03-45678912',
);

// Editar: solo cambian las keys enviadas; null borra el valor.
$kuti->customers->update($customer->id, ['customFields' => ['birth_date' => null]]);
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


## Links de pago

Un enlace permanente que pagan muchas personas (curso, entrada, donación). Cada pago es un cobro
normal con `paymentLinkId`.

```php
$link = $kuti->paymentLinks->create([
    'title' => 'Taller de Excel — sábado 10am',
    'template' => 'COURSE',
    'pricing' => 'FIXED',
    'amount' => '120.00',
    'paymentMethodTypes' => ['INTEROPERABLE_QR', 'BANK_TRANSFER'],
    'customerFieldIds' => ['cfd_…'],     // [] = solo nombre, apellido y correo
    'buttonLabel' => 'Inscribirme',
    'successMessage' => '¡Listo! Te esperamos el sábado.',
    'successButtonLabel' => 'Unirme al grupo',
    'successButtonUrl' => 'https://chat.whatsapp.com/…',
]);
echo $link->url; // https://pay.kuti.pe/l/taller-de-excel

// Quienes pagaron el link
$paid = $kuti->paymentIntents->list(['paymentLinkId' => $link->id, 'status' => 'SUCCEEDED', 'perPage' => 'all']);
```

## Enviar el cobro al crearlo

```php
$kuti->paymentIntents->create(
    new Money('250.00', 'PEN'),
    [PaymentMethodType::InteroperableQr],
    customer: new CustomerInput(id: 'cus_…'),
    sendVia: ['EMAIL', 'WHATSAPP'], // null = ['EMAIL']; [] = no enviar
);
```

## API

- `new KutiClient(string $secretKey, ?string $baseUrl = null, ?ClientInterface $httpClient = null)`
- `$kuti->customers->create(CustomerInput $customer, ?array $metadata)` / `retrieve($id)` / `update($id, array $params)` / `list(array $params)` / `delete($id)`
- `$kuti->checkoutSessions->create(...)` — Checkout.js
- `$kuti->paymentIntents->create(...)` — cobro directo
- `$kuti->paymentIntents->list(...)` — filtros `status`, `q`, `customerId`, `source` (single | link | recurring), `paymentLinkId`
- `$kuti->paymentIntents->retrieve(string $id)`
- `$kuti->paymentIntents->cancel(string $id)`
- `$kuti->paymentIntents->sendWhatsApp(...)`
- `$kuti->paymentLinks->create(array $params)` / `retrieve($id)` / `update($id, array $params)` / `list(array $params)` / `activate($id)` / `deactivate($id)` / `checkSlug($slug, $exceptId)`
- `Webhooks::verifySignature($payload, $signatureHeader, $timestampHeader, $secret, $toleranceSeconds = 300)`

## Tests

```bash
composer install
composer test
```
