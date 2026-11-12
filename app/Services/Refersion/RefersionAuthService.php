<?php

declare(strict_types=1);

namespace App\Services\Refersion;

use App\Exceptions\RefersionApiException;
use App\Exceptions\RefersionAuthenticationException;
use App\Exceptions\RefersionMalformedResponseException;
use App\Exceptions\RefersionRateLimitException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class RefersionAuthService
{
    public function __construct(
        private readonly RefersionRetryPolicy $retry,
    ) {}

    /**
     * @return array{token: string, generation: int}
     */
    public function tokenWithGeneration(): array
    {
        $payload = $this->cachedPayload();

        if ($payload !== null) {
            return [
                'token' => $payload['access_token'],
                'generation' => $payload['generation'],
            ];
        }

        return $this->withTokenLock(function (): array {
            $payload = $this->cachedPayload();

            if ($payload !== null) {
                return [
                    'token' => $payload['access_token'],
                    'generation' => $payload['generation'],
                ];
            }

            $payload = $this->fetchToken($this->currentGeneration());
            $this->store($payload);

            return [
                'token' => $payload['access_token'],
                'generation' => $payload['generation'],
            ];
        });
    }

    public function token(): string
    {
        return $this->tokenWithGeneration()['token'];
    }

    public function refresh(?int $expectedGeneration = null): string
    {
        return $this->withTokenLock(function () use ($expectedGeneration): string {
            $current = $this->cachedPayload();
            $currentGeneration = $this->currentGeneration();

            if (
                $current !== null
                && $expectedGeneration !== null
                && $currentGeneration !== $expectedGeneration
            ) {
                return $current['access_token'];
            }

            $payload = $this->fetchToken($currentGeneration);
            $this->store($payload);

            return $payload['access_token'];
        });
    }

    private function forget(): void
    {
        Cache::forget($this->cacheKey());
    }

    /**
     * @return array{access_token: string, generation: int, expires_in: int}|null
     */
    private function cachedPayload(): ?array
    {
        $payload = Cache::get($this->cacheKey());

        if (! is_array($payload)) {
            return null;
        }

        if (
            ! isset($payload['access_token'], $payload['generation'], $payload['expires_in'])
            || ! is_string($payload['access_token'])
            || ! is_int($payload['generation'])
            || ! is_int($payload['expires_in'])
        ) {
            $this->forget();

            return null;
        }

        return $payload;
    }

    /**
     * @param  array{access_token: string, generation: int, expires_in: int}  $payload
     */
    private function store(array $payload): void
    {
        Cache::put(
            $this->cacheKey(),
            $payload,
            max(60, $payload['expires_in'] - 60),
        );

        // The generation must outlive the token cache so a stale request
        // cannot mistake a newly issued token for the one it just used.
        Cache::forever($this->generationKey(), $payload['generation']);
    }

    private function currentGeneration(): int
    {
        return max(0, (int) Cache::get($this->generationKey(), 0));
    }

    /**
     * @return array{access_token: string, generation: int, expires_in: int}
     */
    private function fetchToken(int $previousGeneration = 0): array
    {
        $clientId = (string) config('services.refersion.client_id');
        $clientSecret = (string) config('services.refersion.client_secret');

        if ($clientId === '' || $clientSecret === '') {
            throw new RefersionAuthenticationException(
                'Refersion API credentials are not configured.',
            );
        }

        for ($attempt = 1; $attempt <= RefersionRetryPolicy::MAX_ATTEMPTS; $attempt++) {
            try {
                $response = Http::asForm()
                    ->acceptJson()
                    ->timeout((int) config('services.refersion.timeout', 15))
                    ->connectTimeout((int) config('services.refersion.connect_timeout', 5))
                    ->post((string) config('services.refersion.auth_url'), [
                        'client_id' => $clientId,
                        'client_secret' => $clientSecret,
                        'token_type' => (string) config('services.refersion.token_type', 'short_lived'),
                    ]);
            } catch (ConnectionException $exception) {
                if ($this->retry->shouldRetry($attempt)) {
                    $this->retry->backoff($attempt);

                    continue;
                }

                throw new RefersionApiException(
                    'Unable to connect to the Refersion token endpoint.',
                    null,
                    null,
                    $exception,
                );
            }

            if ($response->status() === 429) {
                throw new RefersionRateLimitException(
                    'Refersion token endpoint is rate limited.',
                    $this->retry->retryAfter($response),
                );
            }

            if ($response->serverError()) {
                if ($this->retry->shouldRetry($attempt)) {
                    $this->retry->backoff($attempt);

                    continue;
                }

                throw new RefersionApiException(
                    'Refersion token endpoint returned a server error.',
                    $response->status(),
                );
            }

            if ($response->failed()) {
                throw new RefersionAuthenticationException(
                    'Refersion rejected the token request.',
                );
            }

            $data = $response->json();

            if (
                ! is_array($data)
                || ! isset($data['access_token'])
                || ! is_string($data['access_token'])
                || $data['access_token'] === ''
            ) {
                throw new RefersionMalformedResponseException(
                    'Refersion returned an invalid token response.',
                );
            }

            return [
                'access_token' => $data['access_token'],
                'generation' => $previousGeneration + 1,
                'expires_in' => (int) ($data['expires_in'] ?? 3600),
            ];
        }

        throw new RefersionApiException('Unable to obtain a Refersion token.');
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withTokenLock(callable $callback): mixed
    {
        try {
            return Cache::lock($this->lockKey(), 30)->block(10, $callback);
        } catch (LockTimeoutException) {
            throw new RefersionAuthenticationException(
                'Timed out while waiting for the Refersion token refresh lock.',
            );
        }
    }

    private function cacheKey(): string
    {
        return 'refersion.oauth_token.'.sha1((string) config('services.refersion.client_id'));
    }

    private function lockKey(): string
    {
        return 'refersion.oauth_token.lock.'.sha1((string) config('services.refersion.client_id'));
    }

    private function generationKey(): string
    {
        return 'refersion.oauth_token.generation.'.sha1((string) config('services.refersion.client_id'));
    }
}
