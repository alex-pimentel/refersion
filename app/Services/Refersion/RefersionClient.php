<?php

declare(strict_types=1);

namespace App\Services\Refersion;

use App\DTOs\AffiliateSummary;
use App\DTOs\CustomField;
use App\Exceptions\RefersionApiException;
use App\Exceptions\RefersionAuthenticationException;
use App\Exceptions\RefersionMalformedResponseException;
use App\Exceptions\RefersionRateLimitException;
use App\Support\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

class RefersionClient
{
    public function __construct(
        private readonly RefersionAuthService $auth,
    ) {}

    /**
     * @return AffiliateSummary[]
     */
    public function listAllAffiliates(): array
    {
        $page = 1;
        $limit = max(1, (int) config('services.refersion.page_limit', 100));
        $maxPages = max(1, (int) config('services.refersion.max_pages', 500));
        $affiliates = [];

        while ($page <= $maxPages) {
            $response = $this->request('/affiliates', [
                'page' => $page,
                'limit' => $limit,
            ]);

            $data = $this->decodeData($response);

            foreach ($data as $record) {
                if (! is_array($record)) {
                    throw new RefersionMalformedResponseException(
                        'Refersion returned an invalid affiliate record.',
                    );
                }

                $affiliates[] = $this->mapAffiliate($record);
            }

            if (count($data) < $limit) {
                break;
            }

            $page++;
        }

        if ($page > $maxPages) {
            throw new RefersionMalformedResponseException(
                'The affiliate pagination guard was reached.',
            );
        }

        return $affiliates;
    }

    /**
     * @param  int[]  $affiliateIds
     * @return array<int, CustomField[]>
     */
    public function getCustomFieldsForMany(array $affiliateIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $affiliateIds)));

        if ($ids === []) {
            return [];
        }

        $chunkSize = max(1, (int) config('services.refersion.custom_fields_chunk', 10));
        $fields = [];

        foreach (array_chunk($ids, $chunkSize) as $chunk) {
            foreach ($this->requestCustomFieldsChunk($chunk) as $affiliateId => $customFields) {
                $fields[$affiliateId] = $customFields;
            }
        }

        return $fields;
    }

    /**
     * @return CustomField[]
     */
    public function getCustomFields(int $affiliateId): array
    {
        return $this->getCustomFieldsForMany([$affiliateId])[$affiliateId] ?? [];
    }

    private function request(string $path, array $query = []): Response
    {
        $auth = $this->auth->tokenWithGeneration();
        $attempt = 0;
        $reauthAttempted = false;

        while (true) {
            $attempt++;

            try {
                $response = $this->http($auth['token'])->get($this->url($path), $query);
            } catch (ConnectionException $exception) {
                if ($attempt <= 2) {
                    $this->backoff($attempt);

                    continue;
                }

                throw new RefersionApiException(
                    'Unable to connect to Refersion.',
                    null,
                    null,
                    $exception,
                );
            }

            if ($this->isAuthFailure($response) && ! $reauthAttempted) {
                $this->auth->refresh($auth['generation']);
                $auth = $this->auth->tokenWithGeneration();
                $reauthAttempted = true;

                continue;
            }

            if ($response->status() === 429) {
                $retryAfter = $this->retryAfter($response);

                if ($attempt <= 2) {
                    Sleep::sleep(min(30, max(1, $retryAfter)));

                    continue;
                }

                throw new RefersionRateLimitException(
                    'Refersion rate limit exceeded.',
                    $retryAfter,
                );
            }

            if ($response->serverError()) {
                if ($attempt <= 2) {
                    $this->backoff($attempt);

                    continue;
                }

                throw new RefersionApiException(
                    'Refersion returned a server error.',
                    $response->status(),
                );
            }

            if ($this->isAuthFailure($response)) {
                throw new RefersionAuthenticationException(
                    'Refersion rejected the access token.',
                );
            }

            if ($response->failed()) {
                throw new RefersionApiException(
                    'Refersion returned an error response.',
                    $response->status(),
                );
            }

            return $response;
        }
    }

    /**
     * @param  int[]  $affiliateIds
     * @return array<int, CustomField[]>
     */
    private function requestCustomFieldsChunk(array $affiliateIds): array
    {
        $auth = $this->auth->tokenWithGeneration();
        $attempt = 0;
        $reauthAttempted = false;

        while (true) {
            $attempt++;

            $responses = Http::pool(function (Pool $pool) use ($affiliateIds, $auth): void {
                foreach ($affiliateIds as $affiliateId) {
                    $pool->as((string) $affiliateId)
                        ->withToken($auth['token'])
                        ->acceptJson()
                        ->timeout((int) config('services.refersion.timeout', 15))
                        ->connectTimeout((int) config('services.refersion.connect_timeout', 5))
                        ->get($this->url("/affiliates/{$affiliateId}/custom-fields"));
                }
            });

            $hasAuthFailure = $this->hasAuthFailure($responses);
            $hasRateLimit = $this->hasStatus($responses, 429);
            $hasServerError = $this->hasServerError($responses);
            $hasConnectionError = $this->hasConnectionError($responses);

            if ($hasAuthFailure && ! $reauthAttempted) {
                $this->auth->refresh($auth['generation']);
                $auth = $this->auth->tokenWithGeneration();
                $reauthAttempted = true;

                continue;
            }

            if ($hasRateLimit) {
                $retryAfter = $this->maxRetryAfter($responses);

                if ($attempt <= 2) {
                    Sleep::sleep(min(30, max(1, $retryAfter)));

                    continue;
                }

                throw new RefersionRateLimitException(
                    'Refersion rate limit exceeded.',
                    $retryAfter,
                );
            }

            if ($hasServerError || $hasConnectionError) {
                if ($attempt <= 2) {
                    $this->backoff($attempt);

                    continue;
                }

                throw new RefersionApiException(
                    'Refersion returned a server or connection error.',
                );
            }

            if ($hasAuthFailure) {
                throw new RefersionAuthenticationException(
                    'Refersion rejected the access token.',
                );
            }

            return $this->mapCustomFieldResponses($responses);
        }
    }

    /**
     * @param  array<int|string, Response|ConnectionException>  $responses
     * @return array<int, CustomField[]>
     */
    private function mapCustomFieldResponses(array $responses): array
    {
        $fields = [];

        foreach ($responses as $affiliateId => $response) {
            $affiliateId = (int) $affiliateId;

            if (! $response instanceof Response) {
                $fields[$affiliateId] = [];

                continue;
            }

            if ($response->status() === 404) {
                Log::warning('Refersion custom fields were not found for an affiliate.', [
                    'affiliate_id' => $affiliateId,
                ]);

                $fields[$affiliateId] = [];

                continue;
            }

            if ($response->failed()) {
                throw new RefersionApiException(
                    "Refersion returned an error for affiliate {$affiliateId}.",
                    $response->status(),
                );
            }

            $json = $response->json();

            if (! is_array($json) || ! isset($json['data']) || ! is_array($json['data'])) {
                Log::warning('Refersion returned a malformed custom fields payload for an affiliate.', [
                    'affiliate_id' => $affiliateId,
                ]);

                $fields[$affiliateId] = [];

                continue;
            }

            $fields[$affiliateId] = [];

            foreach ($json['data'] as $field) {
                if (
                    ! is_array($field)
                    || ! isset($field['label'], $field['value'])
                    || ! is_string($field['label'])
                    || ! is_string($field['value'])
                ) {
                    Log::warning('Refersion returned a malformed custom field entry.', [
                        'affiliate_id' => $affiliateId,
                    ]);

                    continue;
                }

                $fields[$affiliateId][] = new CustomField($field['label'], $field['value']);
            }
        }

        return $fields;
    }

    /**
     * @return array<int, mixed>
     */
    private function decodeData(Response $response): array
    {
        $json = $response->json();

        if (! is_array($json) || ! isset($json['data']) || ! is_array($json['data'])) {
            throw new RefersionMalformedResponseException(
                'Refersion returned an invalid data payload.',
            );
        }

        return $json['data'];
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function mapAffiliate(array $record): AffiliateSummary
    {
        $rawId = $record['id'] ?? null;

        if (is_int($rawId)) {
            $id = $rawId;
        } elseif (is_string($rawId) && ctype_digit($rawId)) {
            $id = (int) $rawId;
        } else {
            throw new RefersionMalformedResponseException(
                'Refersion returned an affiliate without a valid integer ID.',
            );
        }

        $commission = data_get($record, 'performance.total.commission');

        if ($commission !== null) {
            if (! is_int($commission) && ! is_float($commission) && ! is_string($commission)) {
                throw new RefersionMalformedResponseException(
                    'Refersion returned a non-numeric affiliate commission.',
                );
            }

            if (is_float($commission) && ! is_finite($commission)) {
                throw new RefersionMalformedResponseException(
                    'Refersion returned a non-finite affiliate commission.',
                );
            }

            if (is_string($commission) && ! is_numeric(trim($commission))) {
                throw new RefersionMalformedResponseException(
                    'Refersion returned a non-numeric affiliate commission.',
                );
            }
        }

        $money = Money::fromApiValue(
            $commission,
            (string) config('services.refersion.commission_unit', 'cents'),
        );

        return new AffiliateSummary(
            id: $id,
            firstName: $this->nullableString($record['firstName'] ?? null),
            lastName: $this->nullableString($record['lastName'] ?? null),
            company: $this->nullableString($record['company'] ?? null),
            email: $this->nullableString($record['email'] ?? null),
            commissionCents: $money->minorUnits,
            currency: $money->currency,
        );
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function http(string $token): PendingRequest
    {
        return Http::withToken($token)
            ->acceptJson()
            ->timeout((int) config('services.refersion.timeout', 15))
            ->connectTimeout((int) config('services.refersion.connect_timeout', 5));
    }

    private function url(string $path): string
    {
        return rtrim((string) config('services.refersion.base_url'), '/').'/'.ltrim($path, '/');
    }

    private function retryAfter(Response $response): int
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
     * @param  array<int|string, Response|ConnectionException>  $responses
     */
    private function maxRetryAfter(array $responses): int
    {
        $retryAfter = 1;

        foreach ($responses as $response) {
            if ($response instanceof Response && $response->status() === 429) {
                $retryAfter = max($retryAfter, $this->retryAfter($response));
            }
        }

        return $retryAfter;
    }

    private function isAuthFailure(Response $response): bool
    {
        return in_array($response->status(), [401, 403], true);
    }

    /**
     * @param  array<int|string, Response|ConnectionException>  $responses
     */
    private function hasAuthFailure(array $responses): bool
    {
        foreach ($responses as $response) {
            if ($response instanceof Response && $this->isAuthFailure($response)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int|string, Response|ConnectionException>  $responses
     */
    private function hasStatus(array $responses, int $status): bool
    {
        foreach ($responses as $response) {
            if ($response instanceof Response && $response->status() === $status) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int|string, Response|ConnectionException>  $responses
     */
    private function hasServerError(array $responses): bool
    {
        foreach ($responses as $response) {
            if ($response instanceof Response && $response->serverError()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int|string, Response|ConnectionException>  $responses
     */
    private function hasConnectionError(array $responses): bool
    {
        foreach ($responses as $response) {
            if (! $response instanceof Response) {
                return true;
            }
        }

        return false;
    }

    private function backoff(int $attempt): void
    {
        Sleep::usleep($attempt === 1 ? 250_000 : 750_000);
    }
}
