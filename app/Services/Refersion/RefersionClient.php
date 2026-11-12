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
use Throwable;

class RefersionClient
{
    public function __construct(
        private readonly RefersionAuthService $auth,
        private readonly RefersionRetryPolicy $retry,
    ) {}

    /**
     * @return AffiliateSummary[]
     */
    public function listAllAffiliates(): array
    {
        $page = 1;
        $limit = min(100, max(1, (int) config('services.refersion.page_limit', 100)));
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
     * @param  array<string, int|string>  $query
     */
    private function request(string $path, array $query = []): Response
    {
        $auth = $this->auth->tokenWithGeneration();
        $reauthAttempted = false;

        for ($attempt = 1; $attempt <= RefersionRetryPolicy::MAX_ATTEMPTS; $attempt++) {
            try {
                $response = $this->http($auth['token'])->get($this->url($path), $query);
            } catch (ConnectionException $exception) {
                if ($this->retry->shouldRetry($attempt)) {
                    $this->retry->backoff($attempt);

                    continue;
                }

                throw new RefersionApiException(
                    'Unable to connect to Refersion.',
                    null,
                    null,
                    $exception,
                );
            }

            if ($this->retry->isAuthFailure($response) && ! $reauthAttempted) {
                $this->auth->refresh($auth['generation']);
                $auth = $this->auth->tokenWithGeneration();
                $reauthAttempted = true;

                continue;
            }

            if ($response->status() === 429) {
                $retryAfter = $this->retry->retryAfter($response);

                if ($this->retry->shouldRetry($attempt)) {
                    $this->retry->sleepForRateLimit($retryAfter);

                    continue;
                }

                throw new RefersionRateLimitException(
                    'Refersion rate limit exceeded.',
                    $retryAfter,
                );
            }

            if ($response->serverError()) {
                if ($this->retry->shouldRetry($attempt)) {
                    $this->retry->backoff($attempt);

                    continue;
                }

                throw new RefersionApiException(
                    'Refersion returned a server error.',
                    $response->status(),
                );
            }

            if ($this->retry->isAuthFailure($response)) {
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

        throw new RefersionApiException('Unable to complete the Refersion request.');
    }

    /**
     * @param  int[]  $affiliateIds
     * @return array<int, CustomField[]>
     */
    private function requestCustomFieldsChunk(array $affiliateIds): array
    {
        $auth = $this->auth->tokenWithGeneration();
        $reauthAttempted = false;

        for ($attempt = 1; $attempt <= RefersionRetryPolicy::MAX_ATTEMPTS; $attempt++) {
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

            $hasAuthFailure = $this->retry->hasAuthFailure($responses);
            $hasRateLimit = $this->retry->hasStatus($responses, 429);
            $hasServerError = $this->retry->hasServerError($responses);
            $hasConnectionError = $this->retry->hasConnectionError($responses);

            if ($hasAuthFailure && ! $reauthAttempted) {
                $this->auth->refresh($auth['generation']);
                $auth = $this->auth->tokenWithGeneration();
                $reauthAttempted = true;

                continue;
            }

            if ($hasRateLimit) {
                $retryAfter = $this->retry->maxRetryAfter($responses);

                if ($this->retry->shouldRetry($attempt)) {
                    $this->retry->sleepForRateLimit($retryAfter);

                    continue;
                }

                throw new RefersionRateLimitException(
                    'Refersion rate limit exceeded.',
                    $retryAfter,
                );
            }

            if ($hasServerError || $hasConnectionError) {
                if ($this->retry->shouldRetry($attempt)) {
                    $this->retry->backoff($attempt);

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

        throw new RefersionApiException('Unable to complete the Refersion request.');
    }

    /**
     * @param  array<int|string, Response|Throwable>  $responses
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
}
