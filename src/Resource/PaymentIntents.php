<?php

declare(strict_types=1);

namespace Kuti\Resource;

use Kuti\KutiClient;
use Kuti\PaymentIntent;

final class PaymentIntents
{
    public function __construct(private readonly KutiClient $client)
    {
    }

    /**
     * Consulta el estado real de un cobro. Es la fuente de verdad — nunca confíes en un callback del
     * frontend (`onSuccess` de KUTI.js) para confirmar un pago; el navegador del comprador se puede
     * falsificar. Verifica isPaid() aquí antes de entregar un producto o servicio.
     */
    public function retrieve(string $id): PaymentIntent
    {
        $response = $this->client->request('GET', '/payment-intents/' . rawurlencode($id));

        return PaymentIntent::fromArray($response['data']);
    }
}
