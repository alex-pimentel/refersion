<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Refersion;

use App\Exceptions\RefersionAuthenticationException;
use App\Exceptions\RefersionRateLimitException;
use App\Services\Refersion\RefersionAuthService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RefersionAuthServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::preventStrayRequests();

        config([
            'services.refersion.client_id' => 'test-client-id',
            'services.refersion.client_secret' => 'test-client-secret',
            'services.refersion.auth_url' => 'https://auth.refersion.test/oauth/token',
            'services.refersion.token_type' => 'short_lived',
            'services.refersion.timeout' => 15,
            'services.refersion.connect_timeout' => 5,
        ]);
    }

    public function test_it_exchanges_credentials_for_a_token(): void
    {
        Http::fake([
            'https://auth.refersion.test/oauth/token' => Http::response([
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'access_token' => 'token-1',
            ]),
        ]);

        $token = app(RefersionAuthService::class)->token();

        $this->assertSame('token-1', $token);
        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://auth.refersion.test/oauth/token'
                && $request->method() === 'POST'
                && $request['client_id'] === 'test-client-id'
                && $request['client_secret'] === 'test-client-secret'
                && $request['token_type'] === 'short_lived';
        });
    }

    public function test_it_reuses_the_cached_token(): void
    {
        Http::fake([
            'https://auth.refersion.test/oauth/token' => Http::response([
                'expires_in' => 3600,
                'access_token' => 'token-1',
            ]),
        ]);

        $service = app(RefersionAuthService::class);

        $this->assertSame('token-1', $service->token());
        $this->assertSame('token-1', $service->token());

        Http::assertSentCount(1);
    }

    public function test_it_replaces_the_cached_token_when_refreshing(): void
    {
        Http::fakeSequence('https://auth.refersion.test/oauth/token')
            ->push(['expires_in' => 3600, 'access_token' => 'token-1'])
            ->push(['expires_in' => 3600, 'access_token' => 'token-2']);

        $service = app(RefersionAuthService::class);

        $this->assertSame('token-1', $service->token());
        $this->assertSame('token-2', $service->refresh());

        Http::assertSentCount(2);
    }

    public function test_refresh_reuses_a_newer_generation_without_another_request(): void
    {
        Http::fakeSequence('https://auth.refersion.test/oauth/token')
            ->push(['expires_in' => 3600, 'access_token' => 'token-1'])
            ->push(['expires_in' => 3600, 'access_token' => 'token-2']);

        $service = app(RefersionAuthService::class);

        $first = $service->tokenWithGeneration();
        $second = $service->refresh($first['generation']);
        $third = $service->refresh($first['generation']);

        $this->assertSame($first['generation'], 1);
        $this->assertSame('token-2', $second);
        $this->assertSame('token-2', $third);
        Http::assertSentCount(2);
    }

    public function test_missing_credentials_throw_an_authentication_exception(): void
    {
        config([
            'services.refersion.client_id' => null,
            'services.refersion.client_secret' => null,
        ]);

        $this->expectException(RefersionAuthenticationException::class);

        app(RefersionAuthService::class)->token();
    }

    public function test_a_failed_token_request_throws_an_authentication_exception(): void
    {
        Http::fake([
            'https://auth.refersion.test/oauth/token' => Http::response(['error' => 'invalid_client'], 401),
        ]);

        $this->expectException(RefersionAuthenticationException::class);

        app(RefersionAuthService::class)->token();
    }

    public function test_a_rate_limited_token_request_preserves_retry_after(): void
    {
        Http::fake([
            'https://auth.refersion.test/oauth/token' => Http::response(
                ['error' => 'Rate limit exceeded'],
                429,
                ['Retry-After' => '7'],
            ),
        ]);

        try {
            app(RefersionAuthService::class)->token();
            $this->fail('Expected a rate limit exception.');
        } catch (RefersionRateLimitException $exception) {
            $this->assertSame(7, $exception->retryAfter);
        }
    }

    public function test_secrets_are_not_in_exception_messages(): void
    {
        Http::fake([
            'https://auth.refersion.test/oauth/token' => Http::response(['error' => 'invalid_client'], 401),
        ]);

        try {
            app(RefersionAuthService::class)->token();
            $this->fail('Expected an authentication exception.');
        } catch (RefersionAuthenticationException $exception) {
            $this->assertStringNotContainsString('test-client-secret', $exception->getMessage());
        }
    }
}
