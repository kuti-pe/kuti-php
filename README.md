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

Todas las excepciones de la API extienden `KutiApiException` (`getStatus()`, `getKutiCode()`, `getRequestId()`, `getCorrelationId()`, `getDocUrl()`, `getDetails()`). Hay subclases para los casos más comunes:

```php
use Kuti\Exception\KutiValidationException;
use Kuti\Exception\KutiNotFoundException;
use Kuti\Exception\KutiPermissionException;
use Kuti\Exception\KutiApiException;

try {
    $kuti->checkoutSessions->create(...);
} catch (KutiValidationException $e) {
    // $e->getDetails() => [['field' => 'amount.amount', 'code' => 'MUST_BE_POSITIVE', ...]]
} catch (KutiNotFoundException $e) {
    // ...
} catch (KutiPermissionException $e) {
    if ($e->isInsufficientScope()) {
        // a la API key le falta el permiso de este endpoint (edítala en el panel o usa otra)
    } elseif ($e->isDashboardOnly()) {
        // endpoint solo del panel de KUTI (p. ej. cambiar la cuenta bancaria): ninguna key puede usarlo
    }
} catch (KutiApiException $e) {
    error_log("{$e->getKutiCode()} request_id={$e->getRequestId()}"); // para reportar a soporte
    $diagnosis = $kuti->diagnostics->getRequest($e->getRequestId()); // qué pasó con esa llamada
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

## Yape afiliado y suscripciones

> Disponible en producción próximamente. Ya puedes integrarlo y probarlo con una clave de prueba
> (`kuti_test_…`).

Con `PaymentMethodType::Yape`, tu cliente aprueba una sola vez desde su app y su Yape queda afiliado
a tu negocio. Desde ahí puedes cobrarle sin que vuelva a aprobar.

```php
// Qué tiene guardado el cliente
$methods = $kuti->customers->listPaymentMethods('cus_…');

// Cobrarle ahora, sin que esté presente
$pi = $kuti->paymentIntents->create(
    amount: new Money('80.00', 'PEN'),
    paymentMethodTypes: [PaymentMethodType::Yape],
    customer: new CustomerInput(id: 'cus_…'),
    description: 'Pedido #1042',
    sendVia: [],
    paymentMethod: $methods[0]['id'],
    confirm: true,
    idempotencyKey: 'pedido-1042',
);
// $pi->isPaid(), o $pi->lastSavedMethodPayment['failureCode'] (p. ej. 'insufficient_funds')
// y el cobro queda abierto: su enlace ($pi->checkoutUrl) sigue sirviendo.

// Enviarle un enlace donde vea su Yape guardado y pague con un toque (vale 30 minutos)
$kuti->paymentIntents->create(
    amount: new Money('120.00', 'PEN'),
    paymentMethodTypes: [PaymentMethodType::Yape, PaymentMethodType::InteroperableQr],
    customer: new CustomerInput(id: 'cus_…'),
    savedPaymentMethods: 'enabled',
);

// Tienda con login propio que incrusta el checkout: la llave se la pasas a KUTI.js
$session = $kuti->paymentIntents->createCustomerSession($pi->id);
```

**Suscripción de monto fijo.** KUTI cobra solo cada periodo (máximo S/ 2,500).

```php
$sub = $kuti->subscriptions->create([
    'customer' => new CustomerInput(id: 'cus_…'),
    'description' => 'Plan Pro',
    'amount' => '99.00',
    'frequency' => 'MONTHLY',
    'chargeTime' => '09:00', // hora de Perú; nunca entre 01:00 y 03:00
    'retryPolicy' => ['intervalDays' => [1, 3, 5], 'onExhausted' => 'past_due'], // opcional
    'metadata' => ['workspace_id' => 'ws_4821'],
], "sub-plan-{$customerId}");

if ($sub->status === 'INCOMPLETE') {
    // El cliente aún no tiene su Yape afiliado: debe afiliarlo y pagar el primer periodo aquí.
    echo $sub->latestCycle['checkoutUrl'];
}
```

**Suscripción de monto variable** (por consumo). Al crearla no se cobra nada; se cobra a periodo
vencido y tú envías el monto de cada periodo.

```php
$sub = $kuti->subscriptions->create([
    'customer' => new CustomerInput(id: 'cus_…'),
    'description' => 'LIA por consumo',
    'billingMode' => 'variable',
    'frequency' => 'MONTHLY',
]);
// Si falta afiliar: $sub->setupUrl (también se lo enviamos por correo).

// Al recibir el webhook subscription.amount_required (o al cerrar tu periodo):
$kuti->subscriptions->charge(
    $sub->id,
    '184.00',
    description: '92 alumnos en octubre',
    period: '2026-10',
    idempotencyKey: "consumo-{$sub->id}-2026-10",
);
```

Eventos: `subscription.created`, `.activated`, `.payment_succeeded`, `.payment_failed`,
`.amount_required`, `.period_skipped`, `.updated`, `.paused`, `.resumed`, `.cancelled`,
`.completed`. El `data` es la suscripción completa; `latest_cycle` trae el motivo del fallo, el
intento y cuándo se reintenta. KUTI no corta tu servicio: tú decides qué hacer con cada aviso.

## API

- `new KutiClient(string $secretKey, ?string $baseUrl = null, ?ClientInterface $httpClient = null)`
- `$kuti->customers->create(CustomerInput $customer, ?array $metadata)` / `retrieve($id)` / `update($id, array $params)` / `list(array $params)` / `delete($id)`
- `$kuti->checkoutSessions->create(...)` — Checkout.js
- `$kuti->paymentIntents->create(...)` — cobro directo
- `$kuti->paymentIntents->list(...)` — filtros `status`, `q`, `customerId`, `source` (single | link | subscription), `paymentLinkId`
- `$kuti->paymentIntents->retrieve(string $id)`
- `$kuti->paymentIntents->cancel(string $id)`
- `$kuti->paymentIntents->sendWhatsApp(...)`
- `$kuti->paymentIntents->enableSavedPaymentMethods($id)` / `createCustomerSession($id)` — mostrar el Yape guardado en el checkout
- `$kuti->customers->listPaymentMethods($id)` / `detachPaymentMethod($id, $paymentMethodId)` — Yape afiliado del cliente
- `$kuti->subscriptions->create(array $params, ?string $idempotencyKey)` / `retrieve($id)` / `list(array $params)` / `update($id, array $params)` / `pause($id, ?string $idempotencyKey)` / `resume($id, ?string $idempotencyKey)` / `cancel($id, ?string $idempotencyKey)` / `retry($id, ?string $idempotencyKey)` / `charge($id, $amount, $description, $period, $idempotencyKey)` / `listCycles($id)`
- `$kuti->paymentLinks->create(array $params)` / `retrieve($id)` / `update($id, array $params)` / `list(array $params)` / `activate($id)` / `deactivate($id)` / `checkSlug($slug, $exceptId)`
- `$kuti->paymentExceptions->list(array $params)` / `resolve($id, $status, $note)` — pagos para revisar
- `$kuti->webhookDeliveries->retrieve($id)` / `retry($id)` — cada intento con el status HTTP y lo que respondió tu servidor
- `$kuti->diagnostics->getRequest($requestId)` / `listByCorrelationId($id)` / `tracePaymentIntent($id)` — permiso `diagnostics:read`
- `Webhooks::verifySignature($payload, $signatureHeader, $timestampHeader, $secret, $toleranceSeconds = 300)`

## Tests

```bash
composer install
composer test
```
