<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Exceptions;

use RuntimeException;

final class TenantNotActiveException extends RuntimeException
{
}
