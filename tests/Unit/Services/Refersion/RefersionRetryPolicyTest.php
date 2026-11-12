<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Refersion;

use App\Services\Refersion\RefersionRetryPolicy;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Tests\TestCase;

class RefersionRetryPolicyTest extends TestCase
{
    public function test_it_retries_until_the_maximum_attempt(): void
    {
        $policy = new RefersionRetryPolicy;

        $this->assertTrue($policy->shouldRetry(1));
        $this->assertTrue($policy->shouldRetry(2));
        $this->assertFalse($policy->shouldRetry(3));
    }

    public function test_it_reads_retry_after_from_the_header(): void
    {
        $policy = new RefersionRetryPolicy;

        $response = new Response(new Psr7Response(429, ['Retry-After' => '7']));

        $this->assertSame(7, $policy->retryAfter($response));
    }

    public function test_it_reads_retry_after_from_the_body(): void
    {
        $policy = new RefersionRetryPolicy;

        $response = new Response(new Psr7Response(429, [], json_encode(['retryAfter' => 9])));

        $this->assertSame(9, $policy->retryAfter($response));
    }

    public function test_it_defaults_retry_after_to_sixty_seconds(): void
    {
        $policy = new RefersionRetryPolicy;

        $this->assertSame(60, $policy->retryAfter(new Response(new Psr7Response(429))));
    }

    public function test_it_classifies_authentication_failures(): void
    {
        $policy = new RefersionRetryPolicy;

        $this->assertTrue($policy->isAuthFailure(new Response(new Psr7Response(401))));
        $this->assertTrue($policy->isAuthFailure(new Response(new Psr7Response(403))));
        $this->assertFalse($policy->isAuthFailure(new Response(new Psr7Response(429))));
    }

    public function test_it_scans_pooled_responses_for_auth_failures(): void
    {
        $policy = new RefersionRetryPolicy;

        $responses = [
            1 => new Response(new Psr7Response(200)),
            2 => new Response(new Psr7Response(403)),
        ];

        $this->assertTrue($policy->hasAuthFailure($responses));
        $this->assertFalse($policy->hasAuthFailure([1 => new Response(new Psr7Response(200))]));
    }

    public function test_it_classifies_pooled_server_and_connection_errors(): void
    {
        $policy = new RefersionRetryPolicy;

        $this->assertTrue($policy->hasServerError([
            1 => new Response(new Psr7Response(500)),
        ]));
        $this->assertTrue($policy->hasConnectionError([
            1 => new ConnectionException('connection failed'),
        ]));
        $this->assertFalse($policy->hasConnectionError([
            1 => new Response(new Psr7Response(200)),
        ]));
    }

    public function test_it_clamps_the_rate_limit_sleep(): void
    {
        $policy = new RefersionRetryPolicy;

        // The sleep is delegated to Laravel's Sleep helper; this assertion
        // records the policy's cap without making the test wait.
        $this->assertSame(30, $policy->rateLimitSleepSeconds(120));
        $this->assertSame(1, $policy->rateLimitSleepSeconds(0));
    }
}
