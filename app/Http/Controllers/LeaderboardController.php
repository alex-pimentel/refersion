<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\LeaderboardBusyException;
use App\Exceptions\RefersionApiException;
use App\Exceptions\RefersionAuthenticationException;
use App\Exceptions\RefersionRateLimitException;
use App\Exceptions\RefersionScanLimitException;
use App\Http\Requests\LeaderboardRefreshRequest;
use App\Services\Leaderboard\LeaderboardService;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class LeaderboardController extends Controller
{
    public function __construct(
        private readonly LeaderboardService $leaderboard,
    ) {}

    public function index(): Response
    {
        return Inertia::render('leaderboard', [
            'leaderboard' => Inertia::defer(fn (): array => $this->buildPageData()),
        ]);
    }

    public function refresh(LeaderboardRefreshRequest $request): Response
    {
        return Inertia::render('leaderboard', [
            'leaderboard' => $this->buildPageData(forceRefresh: true),
        ]);
    }

    /**
     * @return array{
     *     data: array<int, array<string, mixed>>,
     *     meta: array<string, mixed>|null,
     *     error: array{message: string, reference: string}|null
     * }
     */
    public function buildPageData(bool $forceRefresh = false): array
    {
        try {
            $result = $this->leaderboard->get($forceRefresh);
            $payload = $result->toArray();

            return [
                'data' => $payload['data'],
                'meta' => $payload['meta'],
                'error' => null,
            ];
        } catch (RefersionApiException|LeaderboardBusyException $exception) {
            $this->logFailure($exception);

            $cached = $this->leaderboard->cached();
            $cachedPayload = $cached?->toArray();

            return [
                'data' => $cachedPayload['data'] ?? [],
                'meta' => $cachedPayload['meta'] ?? null,
                'error' => [
                    'message' => $this->errorMessage($exception),
                    'reference' => $exception->reference,
                ],
            ];
        }
    }

    private function errorMessage(RefersionApiException|LeaderboardBusyException $exception): string
    {
        return match (true) {
            $exception instanceof RefersionAuthenticationException => 'We could not authenticate with Refersion. Check the API credentials.',
            $exception instanceof RefersionRateLimitException => 'Refersion is temporarily rate-limiting requests. Please try again shortly.',
            $exception instanceof RefersionScanLimitException => 'This account has too many affiliates for an interactive refresh. A background sync is required.',
            $exception instanceof LeaderboardBusyException => 'A refresh is already in progress. Please wait a moment.',
            default => 'We could not load the leaderboard right now. Please try again in a moment.',
        };
    }

    private function logFailure(RefersionApiException|LeaderboardBusyException $exception): void
    {
        Log::error('Leaderboard page data failed.', [
            'reference' => $exception->reference,
            'exception' => $exception::class,
            'status' => $exception instanceof RefersionApiException ? $exception->status : null,
        ]);
    }
}
