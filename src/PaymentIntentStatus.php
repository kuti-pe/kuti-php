<?php

declare(strict_types=1);

namespace Kuti;

enum PaymentIntentStatus: string
{
    case RequiresPaymentMethod = 'REQUIRES_PAYMENT_METHOD';
    case Pending = 'PENDING';
    case Processing = 'PROCESSING';
    case Succeeded = 'SUCCEEDED';
    case Failed = 'FAILED';
    case Cancelled = 'CANCELLED';
    case Expired = 'EXPIRED';
}
