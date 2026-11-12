<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Refersion;

use App\Exceptions\RefersionApiException;
use App\Exceptions\RefersionAuthenticationException;
use App\Exceptions\RefersionMalformedResponseException;
use App\Exceptions\RefersionRateLimitException;
use App\Services\Refersion\RefersionClient;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class RefersionClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Sleep::fake();
        Http::preventStrayRequests();

        config([
            'services.refersion.client_id' => 'test-client-id',
            'services.refersion.client_secret' => 'test-client-secret',
            'services.refersion.auth_url' => 'https://auth.refersion.test/oauth/token',
            'services.refersion.base_url' => 'https://api.refersion.test/v2026-08',
            'services.refersion.token_type' => 'short_lived',
            'services.refersion.page_limit' => 2,
            'services.refersion.custom_fields_chunk' => 2,
            'services.refersion.commission_unit' => 'cents',
            'services.refersion.max_pages' => 10,
            'services.refersion.timeout' => 15,
            'services.refersion.connect_timeout' => 5,
        ]);
    }

    public function test_pagination_stops_on_a_short_page(): void
    {
        $this->fakeAuth();

        $requestedPages = [];
        Http::fake(function (Request $request) use (&$requestedPages) {
            if ($this->isAuthRequest($request)) {
                return $this->authResponse();
            }

            $page = $this->query($request, 'page');
            $requestedPages[] = $page;

            return Http::response([
                'data' => $page === 1
                    ? [$this->affiliate(1, 500), $this->affiliate(2, 400)]
                    : [$this->affiliate(3, 300)],
            ]);
        });

        $affiliates = app(RefersionClient::class)->listAllAffiliates();

        $this->assertCount(3, $affiliates);
        $this->assertSame([1, 2], $requestedPages);
    }

    public function test_pagination_stops_on_an_empty_page(): void
    {
        $this->fakeAuth();

        $requestedPages = [];
        Http::fake(function (Request $request) use (&$requestedPages) {
            if ($this->isAuthRequest($request)) {
                return $this->authResponse();
            }

            $page = $this->query($request, 'page');
            $requestedPages[] = $page;

            return Http::response([
                'data' => $page === 1
                    ? [$this->affiliate(1, 500), $this->affiliate(2, 400)]
                    : [],
            ]);
        });

        $affiliates = app(RefersionClient::class)->listAllAffiliates();

        $this->assertCount(2, $affiliates);
        $this->assertSame([1, 2], $requestedPages);
    }

    public function test_page_limit_is_capped_at_the_api_maximum(): void
    {
        config(['services.refersion.page_limit' => 500]);

        $requestedLimit = null;

        Http::fake(function (Request $request) use (&$requestedLimit) {
            if ($this->isAuthRequest($request)) {
                return $this->authResponse();
            }

            $requestedLimit = $this->query($request, 'limit');

            return Http::response([
                'data' => [$this->affiliate(1, 500)],
            ]);
        });

        app(RefersionClient::class)->listAllAffiliates();

        $this->assertSame(100, $requestedLimit);
    }

    public function test_it_maps_affiliate_performance_to_cents(): void
    {
        $this->fakeAuth();

        Http::fake(function (Request $request) {
            if ($this->isAuthRequest($request)) {
                return $this->authResponse();
            }

            return Http::response([
                'data' => [$this->affiliate(8572722, 702513, [
                    'firstName' => 'Avery',
                    'lastName' => 'Cole',
                    'email' => 'avery@example.com',
                ])],
            ]);
        });

        $affiliate = app(RefersionClient::class)->listAllAffiliates()[0];

        $this->assertSame(8572722, $affiliate->id);
        $this->assertSame('Avery Cole', $affiliate->displayName());
        $this->assertSame('avery@example.com', $affiliate->displayEmail());
        $this->assertSame(702513, $affiliate->commissionCents);
        $this->assertSame('USD', $affiliate->currency);
    }

    public function test_malformed_affiliate_data_throws(): void
    {
        $this->fakeAuth();

        Http::fake(function (Request $request) {
            if ($this->isAuthRequest($request)) {
                return $this->authResponse();
            }

            return Http::response(['unexpected' => []]);
        });

        $this->expectException(RefersionMalformedResponseException::class);

        app(RefersionClient::class)->listAllAffiliates();
    }

    public function test_custom_fields_are_mapped_for_each_affiliate(): void
    {
        $this->fakeAuth();

        Http::fake(function (Request $request) {
            if ($this->isAuthRequest($request)) {
                return $this->authResponse();
            }

            if (preg_match('/affiliates\/(\d+)\/custom-fields/', $request->url(), $matches) === 1) {
                return Http::response([
                    'data' => [
                        $this->customField('Display in leaderboard', $matches[1] === '1' ? 'TRUE' : 'false'),
                    ],
                ]);
            }

            return Http::response(['data' => []]);
        });

        $fields = app(RefersionClient::class)->getCustomFieldsForMany([1, 2]);

        $this->assertSame('TRUE', $fields[1][0]->value);
        $this->assertSame('false', $fields[2][0]->value);
    }

    public function test_404_custom_fields_are_skipped(): void
    {
        $this->fakeAuth();

        Http::fake(function (Request $request) {
            if ($this->isAuthRequest($request)) {
                return $this->authResponse();
            }

            if (str_contains($request->url(), '/affiliates/1/custom-fields')) {
                return Http::response(['error' => 'Affiliate not found'], 404);
            }

            return Http::response([
                'data' => [$this->customField('Display in leaderboard', 'TRUE')],
            ]);
        });

        $fields = app(RefersionClient::class)->getCustomFieldsForMany([1, 2]);

        $this->assertSame([], $fields[1]);
        $this->assertSame('TRUE', $fields[2][0]->value);
    }

    public function test_a_rate_limited_list_request_is_retried(): void
    {
        $this->fakeAuth();

        $attempts = 0;
        Http::fake(function (Request $request) use (&$attempts) {
            if ($this->isAuthRequest($request)) {
                return $this->authResponse();
            }

            $attempts++;

            if ($attempts === 1) {
                return Http::response(['error' => 'Rate limited'], 429, ['Retry-After' => '1']);
            }

            return Http::response([
                'data' => [$this->affiliate(1, 500)],
            ]);
        });

        $affiliates = app(RefersionClient::class)->listAllAffiliates();

        $this->assertCount(1, $affiliates);
        $this->assertSame(2, $attempts);
    }

    public function test_persistent_rate_limits_throw_with_retry_after(): void
    {
        $this->fakeAuth();

        Http::fake(function (Request $request) {
            if ($this->isAuthRequest($request)) {
                return $this->authResponse();
            }

            return Http::response(['error' => 'Rate limited'], 429, ['Retry-After' => '12']);
        });

        try {
            app(RefersionClient::class)->listAllAffiliates();
            $this->fail('Expected a rate limit exception.');
        } catch (RefersionRateLimitException $exception) {
            $this->assertSame(12, $exception->retryAfter);
        }
    }

    public function test_persistent_server_errors_throw_an_api_exception(): void
    {
        $this->fakeAuth();

        Http::fake(function (Request $request) {
            if ($this->isAuthRequest($request)) {
                return $this->authResponse();
            }

            return Http::response(['error' => 'Server error'], 500);
        });

        $this->expectException(RefersionApiException::class);

        app(RefersionClient::class)->listAllAffiliates();
    }

    public function test_a_401_refreshes_the_token_and_retries_once(): void
    {
        $authCalls = 0;
        $apiCalls = 0;

        Http::fake(function (Request $request) use (&$authCalls, &$apiCalls) {
            if ($this->isAuthRequest($request)) {
                $authCalls++;

                return Http::response([
                    'expires_in' => 3600,
                    'access_token' => 'token-'.$authCalls,
                ]);
            }

            $apiCalls++;

            if ($apiCalls === 1) {
                return Http::response(['error' => 'Unauthenticated'], 401);
            }

            return Http::response([
                'data' => [$this->affiliate(1, 500)],
            ]);
        });

        $client = app(RefersionClient::class);
        $affiliates = $client->listAllAffiliates();

        $this->assertCount(1, $affiliates);
        $this->assertSame(2, $authCalls);
        $this->assertSame(2, $apiCalls);

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), '/affiliates')
                && $request->hasHeader('Authorization', 'Bearer token-2');
        });
    }

    public function test_a_pooled_401_refreshes_the_token_once(): void
    {
        $authCalls = 0;
        $apiCalls = 0;

        Http::fake(function (Request $request) use (&$authCalls, &$apiCalls) {
            if ($this->isAuthRequest($request)) {
                $authCalls++;

                return Http::response([
                    'expires_in' => 3600,
                    'access_token' => 'token-'.$authCalls,
                ]);
            }

            $apiCalls++;

            if ($apiCalls <= 2) {
                return Http::response(['error' => 'Unauthenticated'], 401);
            }

            return Http::response([
                'data' => [$this->customField('Display in leaderboard', 'TRUE')],
            ]);
        });

        $fields = app(RefersionClient::class)->getCustomFieldsForMany([1, 2]);

        // One initial token request + one single-flight refresh for the batch.
        $this->assertSame(2, $authCalls);
        $this->assertSame(4, $apiCalls);
        $this->assertSame('TRUE', $fields[1][0]->value);
        $this->assertSame('TRUE', $fields[2][0]->value);
    }

    public function test_a_403_refreshes_the_token_and_retries_once(): void
    {
        $authCalls = 0;
        $apiCalls = 0;

        Http::fake(function (Request $request) use (&$authCalls, &$apiCalls) {
            if ($this->isAuthRequest($request)) {
                $authCalls++;

                return Http::response([
                    'expires_in' => 3600,
                    'access_token' => 'token-'.$authCalls,
                ]);
            }

            $apiCalls++;

            if ($apiCalls === 1) {
                return Http::response(['error' => 'Forbidden'], 403);
            }

            return Http::response([
                'data' => [$this->affiliate(1, 500)],
            ]);
        });

        $affiliates = app(RefersionClient::class)->listAllAffiliates();

        $this->assertCount(1, $affiliates);
        $this->assertSame(2, $authCalls);
        $this->assertSame(2, $apiCalls);
    }

    public function test_a_pooled_403_refreshes_the_token_once(): void
    {
        $authCalls = 0;
        $apiCalls = 0;

        Http::fake(function (Request $request) use (&$authCalls, &$apiCalls) {
            if ($this->isAuthRequest($request)) {
                $authCalls++;

                return Http::response([
                    'expires_in' => 3600,
                    'access_token' => 'token-'.$authCalls,
                ]);
            }

            $apiCalls++;

            if ($apiCalls <= 2) {
                return Http::response(['error' => 'Forbidden'], 403);
            }

            return Http::response([
                'data' => [$this->customField('Display in leaderboard', 'TRUE')],
            ]);
        });

        $fields = app(RefersionClient::class)->getCustomFieldsForMany([1, 2]);

        $this->assertSame(2, $authCalls);
        $this->assertSame(4, $apiCalls);
        $this->assertSame('TRUE', $fields[1][0]->value);
        $this->assertSame('TRUE', $fields[2][0]->value);
    }

    public function test_persistent_401_after_refresh_throws_authentication_exception(): void
    {
        $authCalls = 0;

        Http::fake(function (Request $request) use (&$authCalls) {
            if ($this->isAuthRequest($request)) {
                $authCalls++;

                return Http::response([
                    'expires_in' => 3600,
                    'access_token' => 'token-'.$authCalls,
                ]);
            }

            return Http::response(['error' => 'Unauthenticated'], 401);
        });

        $this->expectException(RefersionAuthenticationException::class);

        app(RefersionClient::class)->listAllAffiliates();
    }

    public function test_persistent_403_after_refresh_throws_authentication_exception(): void
    {
        $authCalls = 0;

        Http::fake(function (Request $request) use (&$authCalls) {
            if ($this->isAuthRequest($request)) {
                $authCalls++;

                return Http::response([
                    'expires_in' => 3600,
                    'access_token' => 'token-'.$authCalls,
                ]);
            }

            return Http::response(['error' => 'Forbidden'], 403);
        });

        $this->expectException(RefersionAuthenticationException::class);

        app(RefersionClient::class)->listAllAffiliates();
    }

    public function test_malformed_commission_values_throw_a_malformed_response_exception(): void
    {
        $this->fakeAuth();

        Http::fake(function (Request $request) {
            if ($this->isAuthRequest($request)) {
                return $this->authResponse();
            }

            return Http::response([
                'data' => [$this->affiliate(1, 500, [
                    'performance' => ['total' => ['commission' => ['nested' => true]]],
                ])],
            ]);
        });

        $this->expectException(RefersionMalformedResponseException::class);

        app(RefersionClient::class)->listAllAffiliates();
    }

    public function test_non_numeric_commission_values_throw_a_malformed_response_exception(): void
    {
        $this->fakeAuth();

        Http::fake(function (Request $request) {
            if ($this->isAuthRequest($request)) {
                return $this->authResponse();
            }

            return Http::response([
                'data' => [$this->affiliate(1, 500, [
                    'performance' => ['total' => ['commission' => 'not-a-number']],
                ])],
            ]);
        });

        $this->expectException(RefersionMalformedResponseException::class);

        app(RefersionClient::class)->listAllAffiliates();
    }

    public function test_decimal_affiliate_ids_are_rejected(): void
    {
        $this->fakeAuth();

        Http::fake(function (Request $request) {
            if ($this->isAuthRequest($request)) {
                return $this->authResponse();
            }

            return Http::response([
                'data' => [$this->affiliate(1, 500, ['id' => 1.5])],
            ]);
        });

        $this->expectException(RefersionMalformedResponseException::class);

        app(RefersionClient::class)->listAllAffiliates();
    }

    public function test_malformed_custom_field_entries_are_ignored(): void
    {
        $this->fakeAuth();

        Http::fake(function (Request $request) {
            if ($this->isAuthRequest($request)) {
                return $this->authResponse();
            }

            return Http::response([
                'data' => [
                    ['label' => ['nested'], 'value' => ['nested']],
                    $this->customField('Display in leaderboard', 'TRUE'),
                ],
            ]);
        });

        $fields = app(RefersionClient::class)->getCustomFieldsForMany([1]);

        $this->assertCount(1, $fields[1]);
        $this->assertSame('Display in leaderboard', $fields[1][0]->label);
        $this->assertSame('TRUE', $fields[1][0]->value);
    }

    private function fakeAuth(): void
    {
        // Auth is faked inside each test callback; this helper documents intent.
    }

    private function isAuthRequest(Request $request): bool
    {
        return str_contains($request->url(), 'auth.refersion.test');
    }

    private function authResponse(): PromiseInterface
    {
        return Http::response([
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'access_token' => 'token-1',
        ]);
    }

    private function query(Request $request, string $key): int
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return (int) ($query[$key] ?? 0);
    }

    /**
     * @return array<string, mixed>
     */
    private function affiliate(int $id, int $commission, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'firstName' => 'Affiliate',
            'lastName' => (string) $id,
            'company' => null,
            'email' => "affiliate{$id}@example.com",
            'performance' => [
                'total' => [
                    'commission' => $commission,
                ],
            ],
        ], $overrides);
    }

    /**
     * @return array{label: string, value: string}
     */
    private function customField(string $label, string $value): array
    {
        return ['label' => $label, 'value' => $value];
    }
}
