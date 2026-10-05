<?php

declare(strict_types=1);

namespace Kuti;

enum PaymentMethodType: string
{
    /** QR interoperable — el cliente paga con Yape, Plin u otra billetera. */
    case InteroperableQr = 'INTEROPERABLE_QR';
    /** Pago de servicios / transferencia bancaria (BCP, BBVA, Interbank, ...). */
    case BankTransfer = 'BANK_TRANSFER';
    /** Yape afiliado — el cliente aprueba una vez, su Yape queda guardado y se le debita. */
    case Yape = 'YAPE';
}
