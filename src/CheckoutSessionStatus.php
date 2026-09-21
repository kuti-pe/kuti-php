<?php

declare(strict_types=1);

namespace Kuti;

enum CheckoutSessionStatus: string
{
    case Open = 'OPEN';
    case Completed = 'COMPLETED';
    case Expired = 'EXPIRED';
    case Cancelled = 'CANCELLED';
}
