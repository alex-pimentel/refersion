<?php

declare(strict_types=1);

namespace App\Services\Refersion;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Sleep;
use Throwable;

final class RefersionRetryPolicy
{
    public const MAX_ATTEMPTS = 3;

    public function shouldRetry(int $attempt): bool
    {
        return $attempt < self::MAX_ATTEMPTS;
    }

    public function retryAfter(Response $response): int
    {
        $header = $response->header('Retry-After');

        if (is_numeric($header)) {
            return max(0, (int) $header);
        }

        $body = $response->json();

        if (is_array($body) && isset($body['retryAfter']) && is_numeric($body['retryAfter'])) {
            return max(0, (int) $body['retryAfter']);
        }

        return 60;
    }

    /**
     * @param  array<int|string, Response|Throwable>  $responses
     */
    public function maxRetryAfter(array $responses): int
    {
        $retryAfter = 1;

        foreach ($responses as $response) {
            if ($response instanceof Response && $response->status() === 429) {
                $retryAfter = max($retryAfter, $this->retryAfter($response));
            }
        }

        return $retryAfter;
    }

    public function rateLimitSleepSeconds(int $retryAfter): int
    {
        return min(30, max(1, $retryAfter));
    }

    public function sleepForRateLimit(int $retryAfter): void
    {
        Sleep::sleep($this->rateLimitSleepSeconds($retryAfter));
    }

    public function backoff(int $attempt): void
    {
        Sleep::usleep($attempt === 1 ? 250_000 : 750_000);
    }

    public function isAuthFailure(Response $response): bool
    {
        return in_array($response->status(), [401, 403], true);
    }

    /**
     * @param  array<int|string, Response|Throwable>  $responses
     */
    public function hasAuthFailure(array $responses): bool
    {
        foreach ($responses as $response) {
            if ($response instanceof Response && $this->isAuthFailure($response)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int|string, Response|Throwable>  $responses
     */
    public function hasStatus(array $responses, int $status): bool
    {
        foreach ($responses as $response) {
            if ($response instanceof Response && $response->status() === $status) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int|string, Response|Throwable>  $responses
     */
    public function hasServerError(array $responses): bool
    {
        foreach ($responses as $response) {
            if ($response instanceof Response && $response->serverError()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int|string, Response|Throwable>  $responses
     */
    public function hasConnectionError(array $responses): bool
    {
        foreach ($responses as $response) {
            if (! $response instanceof Response) {
                return true;
            }
        }

        return false;
    }
}
