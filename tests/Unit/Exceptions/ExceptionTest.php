<?php

declare(strict_types=1);

namespace Tests\Unit\Exceptions;

use App\Exceptions\RefersionAuthenticationException;
use App\Exceptions\RefersionRateLimitException;
use PHPUnit\Framework\TestCase;

class ExceptionTest extends TestCase
{
    public function test_authentication_exception_carries_status_and_reference(): void
    {
        $exception = new RefersionAuthenticationException('Token rejected', 'ref-123');

        $this->assertSame(401, $exception->status);
        $this->assertSame('ref-123', $exception->reference);
    }

    public function test_rate_limit_exception_carries_retry_after(): void
    {
        $exception = new RefersionRateLimitException('Rate limited', retryAfter: 42, reference: 'ref-456');

        $this->assertSame(429, $exception->status);
        $this->assertSame(42, $exception->retryAfter);
        $this->assertSame('ref-456', $exception->reference);
    }
}
