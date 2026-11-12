# Refersion Top Affiliates Leaderboard — Specification

- **Status:** Approved for implementation
- **Date:** 2026-11-12
- **Scope:** First implementation milestone
- **Source:** Refersion API requirements, architecture notes, live API inspection, and `refersion.com` brand styles.

---

## 1. Intent

Build a small, production-minded web application that shows the **top five eligible affiliates** in a Refersion account, ranked by **lifetime total commission** (`performance.total.commission`), highest to lowest.

An affiliate is eligible only when its custom-field list contains a field with:

- `label === "Display in leaderboard"` (exact, case-sensitive)
- `value === "TRUE"` (exact, case-sensitive string — not boolean `true`, not `true`, not `TRUE ` with whitespace)

The app is a single-page dashboard built with **Laravel + React 19 via Inertia**, shipped as a **one-command Docker environment**, with automated tests proving the pagination, filtering, ordering, caching, and error behavior.

---

## 2. What success looks like

At the end of the first milestone the repository should:

1. Start with `docker compose up --build` after `.env` credentials are provided.
2. Show a polished leaderboard at `http://localhost:8080`.
3. Fetch **all** affiliates by following the page/limit contract.
4. Fetch custom fields only for as many candidates as needed, using concurrent batches and an early exit.
5. Display exactly the top five eligible affiliates, correctly ordered.
6. Handle loading, empty, upstream-error, and refresh states without a generic 500 page.
7. Reuse a cached OAuth token and cache the leaderboard result.
8. Pass an automated test suite that uses `Http::fake()` and never calls the real API.
9. Document architecture decisions, trade-offs, verification steps, unfinished work, next steps, and AI usage in the repository.

---

## 3. Non-goals

- No end-user authentication, registration, roles, or SSO.
- No persistent affiliate/conversion domain tables, synchronization jobs, or webhooks.
- No multi-account, multi-currency, or multi-tenant UI.
- No affiliate CRUD or admin screens.
- No MySQL/PostgreSQL or Redis requirement for the default setup.
- No pixel-perfect clone of the Refersion marketing site.
- No queue worker, WebSocket, or real-time updates in the first delivery.

## 4. Anti-goals

These are explicit failure modes to avoid:

- Calling `GET /affiliates/{id}/custom-fields` sequentially for every affiliate (external N+1).
- Requesting a new OAuth token for every page load or refresh.
- Sorting commission values as strings.
- Trimming or lowercasing custom-field values before comparison.
- Exposing client credentials, access tokens, raw API errors, or affiliate PII in logs.
- Adding a heavy relational database or microservice architecture for a stateless read-only leaderboard.
- Hiding known limitations or unfinished work.

---

## 5. Constraints and assumptions

### 5.1 Stack

| Concern | Choice |
|---|---|
| Backend | Laravel 12 (current stable React starter kit), PHP 8.4 |
| Frontend | React 19 + TypeScript + Inertia 2 + Tailwind CSS 4 |
| Build | Vite |
| Container | Docker multi-stage build, Nginx + PHP-FPM, SQLite for cache |
| Tests | Pest (or PHPUnit if the starter kit is PHPUnit-based) |
| Formatting and analysis | Laravel Pint, Larastan level 7, ESLint/Prettier, `tsc --noEmit` |
| CI | GitHub Actions |

Exact patch versions are pinned in `composer.lock` and `package-lock.json` at implementation time. The Docker base images should use current stable tags (e.g. `php:8.4-fpm-alpine`, `node:22-alpine`).

### 5.2 External API

- Auth URL: `https://auth.refersion.com/oauth/token`
- API base URL: `https://api.refersion.com/v2026-08`
- Auth: OAuth2 `client_credentials`, Bearer token.
- Token lifetimes: `short_lived` = 3600s, `long_lived` = 90 days. This app requests `short_lived`.
- Read rate limit: **300 GET requests/minute per account**, shared across read endpoints.
- Auth rate limit: 60 requests/minute per Client ID.
- Affiliate list max page size: **100**.
- Pagination contract: start at `page=1`; continue while the page returns exactly `limit` records; stop when it returns fewer.
- Custom fields response: `{ "data": [ { "label": "...", "value": "..." }, ... ] }`.

### 5.3 Live-account findings (used for verification fixtures)

A sample Refersion account was inspected during specification:

- 44 affiliates exist in one page when `limit=100`.
- The API returns `data` as the only top-level key for the list endpoint.
- Commission values are integers in the live payload and sum exactly from the affiliate's conversions (e.g. eight conversions summing to `439034`). They behave as **minor currency units (cents)**, not dollars.
- The sample data includes decoys: `"TRUE"`, `"true"`, `"TRUE "` (trailing space), `"TRU"`, `"TRUe"`, `"FALSE"`, `"false"`, `"no"`, `"yes"`, `"YUP"`, and missing fields.
- Exact `"TRUE"` yields this expected top five (IDs are safe to use in tests):

| Rank | Affiliate ID | Name | Lifetime commission (cents) | Display |
|---:|---:|---|---:|---:|
| 1 | 8572722 | Avery Cole | 702513 | $7,025.13 |
| 2 | 8572717 | Jordan Blake | 574870 | $5,748.70 |
| 3 | 8572699 | Morgan Reed | 572666 | $5,726.66 |
| 4 | 8572735 | Casey Ellis | 562730 | $5,627.30 |
| 5 | 8572728 | Riley Chen | 477234 | $4,772.34 |

Names in this document and in the automated fixtures are fictional aliases. The affiliate IDs and commission values are retained as verification fixtures; live affiliate emails and credentials are never committed.

Additional edge cases from the sample data:

- ID `8572714` (Taylor Quinn) has value `"TRUE "` with trailing whitespace and **must not** be eligible.
- ID `8572734` (Jamie Park) has value `"true"` and **must not** be eligible.
- ID `8572733` (Drew Morgan) has value `"false"` and **must not** be eligible.
- ID `8572702` (Robin Hayes) has value `"no"` and **must not** be eligible.

These values should be represented in the automated test suite, not fetched during tests.

---

## 6. Decisions (our calls)

The following are deliberate choices for this delivery. They are listed here so they can be reviewed before implementation.

### 6.1 Inertia + React, not a separately deployed SPA

**Decision:** Use Laravel + Inertia 2 + React 19 in one application.

**Why:**
- The prior architecture notes recommend Inertia + React for a time-constrained first delivery.
- React is the target production frontend, so the example stays close to the real stack.
- Avoids CORS, a second deployment, token handling for the internal frontend, and duplicated routing.
- React still provides the SPA feel and rich states required by the requirements.
- Inertia deferred props let the page render a skeleton immediately while the API aggregation runs.

**Trade-off:** The frontend is coupled to Laravel's page responses. If the requirement were a public API consumed by other clients, a decoupled JSON API would be better. The service layer remains reusable if that split is needed later.

### 6.2 Single app container with SQLite cache; Redis is a documented upgrade

**Decision:** Docker Compose runs one application container (Nginx + PHP-FPM). Cache and locks use Laravel's `database` cache store backed by SQLite. No Redis in the default stack.

**Why:**
- One command, fewer moving parts, fewer failure modes for an operator.
- The app is a stateless BFF/aggregator; no domain tables are needed.
- SQLite's database cache supports atomic locks in Laravel, which is enough to protect token refresh and cache stampedes in a single-container deployment.
- Redis would add a second service, health checks, and connection failure modes without changing the product behavior at this scale.

**Production note (documented in README):** At multiple app replicas, move cache and locks to Redis so all replicas share the token and leaderboard cache, and use `Cache::lock()` to prevent concurrent token issuance. Redis is a next step, not a first-delivery requirement.

### 6.3 Commission values are minor units (cents) by default

**Decision:** Treat API commission values as integer cents, expose `commissionCents` to the UI, and format as USD in React by dividing by 100.

**Why:** Live data sums exactly from conversion records and the magnitudes are realistic only as cents. The value `702513` represents `$7,025.13`, not `$702,513.00`.

**Safety valve:** `REFERSION_COMMISSION_UNIT` supports `cents` (default) and `major`. All conversion logic lives in one `Money` value object and is covered by tests. The README states the assumption and how to change it.

### 6.4 Exact-match eligibility, no normalization

**Decision:** Compare label and value with strict equality. Do not trim, lowercase, cast, or coerce.

**Why:** The requirements specify value equal to the string `"TRUE"`. The sample data includes near-matches that catch loose comparisons.

### 6.5 Fetch all affiliates, then early-exit custom fields

**Decision:** Paginate **all** affiliates first. Sort the complete list by commission descending. Then request custom fields in concurrent chunks (default 10) and stop as soon as five eligible affiliates are found.

**Why:**
- The requirements require fetching all affiliates via pagination.
- Sorting before custom-field lookups means the first five eligible records encountered are the true top five.
- Early exit reduces custom-field requests from `N` to roughly `ceil(candidates_scanned / chunk_size) * chunk_size`, which is far below the 300 GET/min limit for the observed account.

**Tie-break:** Commission descending, then affiliate ID ascending for deterministic output.

### 6.6 Token and result caching with locks

**Decision:**
- OAuth token cached until `expires_in - 60` seconds, keyed by a hash of the client ID, protected by a `Cache::lock`.
- Leaderboard result cached for 5 minutes, keyed by a hash of the client ID, protected by a cache lock.
- Refresh recomputes and replaces the cache only after a successful build; a failed refresh does not destroy the last good result.

**Why:** The auth docs explicitly warn that requesting a new token revokes the previous token of the same type. A per-request token would break under concurrent requests. Caching the leaderboard protects the 300 GET/min budget and keeps the UI fast.

### 6.7 Friendly Inertia error state, sanitized details

**Decision:** The controller catches domain exceptions and returns the Inertia page with a structured `error` payload and a short reference code. Raw exception messages, tokens, and headers are never sent to React. Technical details are logged without secrets.

The page keeps the last successful data visible when a refresh fails (client-side stale-while-error), showing a warning banner instead of blanking the table.

**Trade-off:** A pure JSON API would return HTTP 503 for upstream failures. With Inertia, returning 200 with a rendered error state gives the best user experience and keeps the page shell. This is documented as an intentional choice.

### 6.8 No user authentication in this delivery

**Decision:** The leaderboard route is public in the demo.

**Why:** The requirements do not include user login, and adding auth would expand the first milestone unnecessarily. The README documents that production should protect the route with `auth:sanctum`, company SSO, or network-level restrictions.

### 6.9 Secrets only through environment variables

**Decision:** `REFERSION_CLIENT_ID`, `REFERSION_CLIENT_SECRET`, and `APP_KEY` live in `.env` (not committed). `.env.example` contains empty placeholders and documentation. API credentials are entered locally by the operator.

**Why:** Standard security practice. The repository should demonstrate correct secret handling even when the credentials are only sample values.

### 6.10 Include a test target in the Docker image

**Decision:** The runtime image installs Composer dev dependencies so the container can run the PHPUnit suite through `bash scripts/test-container.sh`.

**Trade-off:** The image is larger than a production-only image. For this example project, verifiability is more important than image size. A production multi-stage `--no-dev` target can be added later.

---

## 7. Architecture

### 7.1 High-level flow

```text
Browser
  │  Inertia page / refresh POST
  ▼
LeaderboardController
  │
  ▼
LeaderboardService ──────────────► Cache (SQLite via Laravel cache)
  │                                   ├─ leaderboard result (5 min)
  │                                   ├─ OAuth token (expires_in - 60s)
  │                                   └─ locks
  │
  ├──► RefersionAuthService ──────► POST https://auth.refersion.com/oauth/token
  │
  └──► RefersionClient ───────────► GET /affiliates?page=&limit=100 (all pages)
                                     GET /affiliates/{id}/custom-fields (pooled chunks)
```

### 7.2 Layers and responsibilities

| Layer | Responsibility |
|---|---|
| `LeaderboardController` | HTTP/Inertia boundary, validation, error presentation |
| `LeaderboardService` | Orchestration: pagination result, sorting, eligibility, early exit, caching, DTO assembly |
| `RefersionAuthService` | OAuth client-credentials exchange, token cache, refresh-on-401, lock around renewal |
| `RefersionClient` | HTTP concerns: base URL, auth header, pagination, pooled custom-field requests, retries, error mapping |
| `Money` | Normalize commission values to integer minor units; currency-aware formatting helpers for tests |
| DTOs | Immutable transport objects between layers and to Inertia |
| Exceptions | Typed failures: API, auth, rate limit, malformed response, lock timeout |
| React page/components | Presentation only; no API secrets or direct Refersion calls |

No `Http::` calls in controllers, models, or React components.

### 7.3 Proposed file structure

```text
app/
  DTOs/
    AffiliateSummary.php
    CustomField.php
    LeaderboardEntry.php
    LeaderboardMeta.php
    LeaderboardResult.php
  Exceptions/
    RefersionApiException.php
    RefersionAuthenticationException.php
    RefersionRateLimitException.php
    RefersionMalformedResponseException.php
    LeaderboardBusyException.php
  Http/
    Controllers/LeaderboardController.php
  Services/Refersion/
    RefersionAuthService.php
    RefersionClient.php
    RefersionRetryPolicy.php
  Services/Leaderboard/
    LeaderboardEligibility.php
    LeaderboardService.php
  Support/
    Money.php
config/
  services.php            # refersion block
routes/
  web.php                 # GET /, POST /leaderboard/refresh, GET /health
resources/
  css/app.css             # Tailwind theme tokens
  js/
    app.tsx
    Pages/Leaderboard.tsx
    Components/
      LeaderboardTable.tsx
      RankBadge.tsx
      AffiliateAvatar.tsx
      RefreshButton.tsx
      StatusBanner.tsx
      EmptyState.tsx
      ErrorState.tsx
      SkeletonTable.tsx
    lib/format.ts
    types/leaderboard.ts
tests/
  Unit/Services/Refersion/RefersionAuthServiceTest.php
  Unit/Services/Refersion/RefersionClientTest.php
  Unit/Services/Leaderboard/LeaderboardServiceTest.php
  Unit/Support/MoneyTest.php
  Feature/LeaderboardPageTest.php
  Feature/LeaderboardRefreshTest.php
docker/
  nginx.conf
  supervisord.conf
  entrypoint.sh
.github/workflows/ci.yml
```

### 7.4 Request lifecycle

**Initial page load (`GET /`)**

1. Controller returns an Inertia response with `leaderboard` as a deferred prop. The deferred closure delegates to a private `loadPageData()` method on the controller, which receives the explicit `get()` loader.
2. React renders the page shell, header, and skeleton rows immediately.
3. Inertia requests the deferred prop.
4. `loadPageData()` invokes the supplied loader, which calls `LeaderboardService::get()`.
   - Cache hit: returns cached DTO with `meta.cached = true`.
   - Cache miss: acquires the leaderboard lock, builds fresh data, stores it, returns with `meta.cached = false`.
   - Any domain exception is caught here, logged with a reference, and converted to `{ data: [], meta: null, error: { message, reference } }`. The deferred request itself always resolves to a renderable payload.
5. React replaces the skeleton with the table, empty state, or error state.

**Refresh (`POST /leaderboard/refresh`)**

1. Inertia posts with CSRF protection; route has `throttle:10,1`.
2. Controller calls `LeaderboardService::refresh()` directly; the POST route itself is the forced-refresh action.
3. Service acquires the lock, rebuilds, and replaces the cached result on success.
4. Controller returns the same Inertia page with fresh props; page keeps state and scroll.
5. On failure, controller returns the page with `error` and the last cached result if available. React keeps the previous table data and shows a warning banner.

**Health (`GET /health`)**

- Returns `200` with `{"status":"ok","time":"..."}`.
- Does not call Refersion, so it cannot consume API quota.
- Used by Docker healthcheck.

---

## 8. Refersion integration

### 8.1 Authentication

Request:

```http
POST https://auth.refersion.com/oauth/token
Content-Type: application/x-www-form-urlencoded

client_id=...
client_secret=...
token_type=short_lived
```

Response:

```json
{
  "token_type": "Bearer",
  "expires_in": 3600,
  "access_token": "..."
}
```

Implementation rules:

- Cache key: `refersion.oauth_token.<sha1(client_id)>`.
- TTL: `max(60, expires_in - 60)` seconds.
- Lock key: `refersion.oauth_token.lock.<sha1(client_id)>`, **30-second lock**, 10-second block. The lock TTL must be longer than the 15-second auth request timeout so a slow token request cannot allow a second concurrent renewal.
- On a cache miss, acquire the lock, re-check the cache, request a token, store it, release the lock.
- **Single-flight 401 handling:** if a pooled batch receives one or more `401` responses, discard the whole batch result, invalidate the cached token once, acquire the token lock, re-check the cache, request exactly one new token, and retry the failed batch once with that token. Do not let each request in the pool refresh independently; concurrent refreshes would revoke each other's tokens.
- Store a monotonically increasing token generation/version alongside the token. A request captures the generation before it is sent; a 401 triggers a refresh only if the cached generation still matches. Otherwise it reuses the newer token already in cache.
- If the batch still returns `401`/`403` after one refresh-and-retry cycle, throw `RefersionAuthenticationException`.
- Never log the token or client secret.
- Do not request `long_lived` in this app; short-lived tokens with safe reuse are sufficient.

### 8.2 Pagination

Contract from the docs and requirements:

```http
GET /affiliates?page=1&limit=100
Authorization: Bearer <token>
Accept: application/json
```

Rules:

- `limit = 100` (API maximum).
- Start at `page = 1`.
- Append `data`, continue while `count(data) === limit`.
- Stop when `count(data) < limit`.
- Stop immediately on an empty page.
- Defensive guard: `maxPages` default 500 to prevent an infinite loop. If exceeded, throw `RefersionMalformedResponseException` with a clear message.
- The response is expected to have `data` as an array. Missing/non-array `data` is a malformed response.
- Map each record to `AffiliateSummary` immediately:
  - `id` (int)
  - `firstName`, `lastName`, `company`, `email` (nullable/string)
  - `commissionCents` (from `performance.total.commission`, via `Money`)
  - `currency` (default `USD` until a per-account currency is available)

### 8.3 Custom fields

```http
GET /affiliates/{affiliateId}/custom-fields
Authorization: Bearer <token>
Accept: application/json
```

Expected response:

```json
{
  "data": [
    { "id": 123, "label": "Display in leaderboard", "value": "TRUE" }
  ]
}
```

Rules:

- Read only `data`.
- Map to `CustomField` DTOs with `label` and `value` as strings.
- A malformed payload for one affiliate should not corrupt the whole result. If `data` is missing/non-array, treat that affiliate as ineligible and log a warning with the affiliate ID.
- `404` on custom fields: log a warning and treat the affiliate as ineligible. Do not fail the entire leaderboard because one affiliate disappeared between calls.
- `401`: invalidate token and retry once (see 8.1).
- `429`: respect `Retry-After` (see 8.5).
- `5xx`/timeout: retry the request/chunk up to two times with exponential backoff; then throw `RefersionApiException`.

### 8.4 Eligibility and ranking algorithm

```text
affiliates = client.listAllAffiliates()
sort affiliates by commissionCents DESC, then id ASC

eligible = []
for chunk in affiliates.chunk(10):
    fieldsByAffiliate = client.getCustomFieldsForMany(chunk.ids)   // pooled
    for affiliate in chunk:
        if fieldsByAffiliate[affiliate.id] contains
             label === "Display in leaderboard" AND value === "TRUE":
            eligible.append(affiliate)
            if eligible.length === 5:
                break outer loop

return eligible.map((affiliate, index) => new LeaderboardEntry(rank = index + 1, ...))
```

Important details:

- Sort **before** custom-field requests. This is what makes early exit correct.
- Cache the final `LeaderboardResult`, not the raw affiliate list.
- If fewer than five eligible affiliates exist, return all eligible affiliates (0–4). The UI states that fewer than five were found.
- If two affiliates tie on commission, ID ascending gives deterministic rank.
- Negative commissions are allowed (manual credits can be negative); they sort naturally.
- `null`/missing commission is treated as `0` and sorts last.
- No trimming, no case folding, no boolean casting.

### 8.5 Rate limiting and retries

Read budget is 300 GET/min. The algorithm's expected budget for the live account:

- 1 list request (44 affiliates fit in one page at limit 100).
- Chunk 1: top 10 affiliates → 4 eligible found.
- Chunk 2: next 10 affiliates → 5th eligible found (Riley Chen).
- Total: **1 + 20 = 21 GET requests**, versus 45 for a naive implementation.

**Sparse-eligibility and large-account policy**

The early-exit budget above assumes eligible affiliates are concentrated near the top of the sorted list. If fewer than five eligible affiliates exist, or eligibility is sparse, the service must continue scanning to be correct. For a very large account this can exceed the 300 GET/min budget and outlive a synchronous HTTP request.

First-delivery policy:

1. **Correctness first.** Scan until five eligible affiliates are found or the affiliate list is exhausted.
2. Add a configurable safety guard `REFERSION_MAX_CANDIDATES` (default `1000`). If the guard is reached without five eligible affiliates, throw `RefersionScanLimitException` and render a clear error: the account is too large for an interactive refresh and should use the queued refresh path.
3. Do **not** silently return an incomplete top five. A partial leaderboard is worse than an explicit, actionable error.
4. Document the limitation: the synchronous path is sized for small and medium accounts. The next step for large accounts is a queued `SyncLeaderboardJob` with persisted progress and a worker-side rate limiter.
5. Pacing, if added, must be a shared token bucket (Redis) so it works across workers/replicas. The default SQLite cache is single-container and cannot coordinate multiple workers.

Retry policy:

| Condition | Behavior |
|---|---|
| `429 Too Many Requests` | Read `Retry-After` header (fallback to `retryAfter` body). Wait the indicated seconds, capped at 30s. Retry the request/chunk up to 2 times. If still limited, throw `RefersionRateLimitException` with the wait time. |
| `5xx` / connection timeout | Retry up to 2 times with exponential backoff (250ms, 750ms). |
| `401` / `403` | Invalidate token, request one new token, retry once. Then throw auth exception. |
| `404` on custom fields | Log warning, skip affiliate, continue. |
| Other `4xx` | Throw `RefersionApiException` with status and endpoint; do not retry. |
| Invalid JSON / unexpected shape | Throw `RefersionMalformedResponseException`; do not retry indefinitely. |

Timeouts:

- Connect timeout: 5 seconds.
- Total request timeout: 15 seconds.
- `Http::pool` chunk size: 10 concurrent requests. This is deliberately conservative: 10 concurrent + retries is far below 300/min, and it avoids overwhelming the API.

Use Laravel's `Sleep::fake()` in tests where available to avoid real waits.

### 8.6 Error taxonomy

| Exception | Meaning | UI message |
|---|---|---|
| `RefersionAuthenticationException` | Missing/invalid credentials, token rejected after retry | "We couldn't authenticate with Refersion. Check the API credentials." |
| `RefersionRateLimitException` | Still rate-limited after retries | "Refersion is temporarily rate-limiting requests. Try again in about N seconds." |
| `RefersionApiException` | Upstream 4xx/5xx/timeout after retries | "We couldn't reach Refersion right now. Please try again in a moment." |
| `RefersionMalformedResponseException` | Unexpected API payload | Same as above, with a reference code |
| `RefersionScanLimitException` | Candidate safety guard reached before five eligible affiliates were found | "This account has too many affiliates for an interactive refresh. A background sync is required." |
| `LeaderboardBusyException` | Could not acquire the refresh lock | "A refresh is already in progress. Please wait a moment." |

All exceptions carry a generated `reference` (short random string) that is logged with technical context and returned to the UI. The UI shows the reference in small text for support. Raw exception messages are never shown to the user.

---

## 9. Data contract

### 9.1 Backend DTOs

```php
final readonly class CustomField
{
    public function __construct(
        public string $label,
        public string $value,
    ) {}
}

final readonly class AffiliateSummary
{
    public function __construct(
        public int $id,
        public ?string $firstName,
        public ?string $lastName,
        public ?string $company,
        public ?string $email,
        public int $commissionCents,
        public string $currency,
    ) {}
}

final readonly class LeaderboardEntry
{
    public function __construct(
        public int $rank,
        public int $affiliateId,
        public string $name,
        public string $email,
        public int $commissionCents,
        public string $currency,
    ) {}
}

final readonly class LeaderboardMeta
{
    public function __construct(
        public string $generatedAt,       // ISO 8601
        public bool $cached,
        public int $totalAffiliates,
        public int $scannedAffiliates,
        public int $eligibleCount,
        public int $customFieldRequests,
        public int $durationMs,
    ) {}
}

final readonly class LeaderboardResult
{
    /** @param LeaderboardEntry[] $entries */
    public function __construct(
        public array $entries,
        public LeaderboardMeta $meta,
    ) {}
}
```

`name` fallback order: `trim(firstName . ' ' . lastName)` → `company` → local part of email → `"Affiliate #<id>"`.

`email` fallback: empty string if missing. The UI displays a dash for missing emails.

### 9.2 Inertia page props

```ts
type LeaderboardEntry = {
  rank: number
  affiliateId: number
  name: string
  email: string
  commissionCents: number
  currency: string
}

type LeaderboardMeta = {
  generatedAt: string
  cached: boolean
  totalAffiliates: number
  scannedAffiliates: number
  eligibleCount: number
  customFieldRequests: number
  durationMs: number
}

type LeaderboardPageData = {
  data: LeaderboardEntry[]
  meta: LeaderboardMeta | null
  error: { message: string; reference: string } | null
}
```

The page receives:

```ts
defineProps<{
  leaderboard?: LeaderboardPageData   // undefined while the deferred prop loads
}>()
```

Page-data states:

| State | `leaderboard` | `data` | `meta` | `error` |
|---|---|---|---|---|
| Loading deferred prop | `undefined` | — | — | — |
| Success | object | entries | populated | `null` |
| Empty | object | `[]` | populated | `null` |
| Upstream failure | object | `[]` | `null` | populated |
| Refresh failure with last good data | object | last good entries | last good meta | populated |

The success-only `LeaderboardResult` DTO is separate from the page payload. `meta` is nullable only on the failure path; the React page must guard `leaderboard.meta` before rendering timestamps or scan counts.

### 9.3 Frontend formatting

- `formatCurrency(cents, currency = 'USD')` divides by 100 and uses:
  ```ts
  new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency,
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })
  ```
- `formatRelativeTime(iso)` uses `Intl.RelativeTimeFormat('en', { numeric: 'auto' })`; the `title` attribute contains the absolute date/time.
- Numbers use `font-variant-numeric: tabular-nums` so columns align.
- The UI never does arithmetic on floats for ranking; ranking is decided by the backend using integer cents.

---

## 10. UI / UX

### 10.1 Route and page

| Route | Method | Purpose |
|---|---|---|
| `/` | GET | Inertia page with deferred leaderboard data |
| `/leaderboard/refresh` | POST | Force a fresh build; throttle 10/min per IP |
| `/health` | GET | Container health check |

No other public routes. No auth pages.

### 10.2 Visual direction

Refersion-inspired, not a generic admin template:

- Deep indigo brand header with cream text and a lime accent.
- Warm off-white/light sage page background.
- White leaderboard card with a subtle border and layered shadow.
- A thin lime-to-blue accent line or corner glow, used sparingly.
- Top-three rows get restrained gold/silver/bronze treatment, not loud gradients.
- Rounded but not bubbly: 16–20px card radius, 10–12px controls.
- Generous spacing, clear hierarchy, one primary action.
- Avoid the default purple/blue SaaS gradient look; use the extracted Refersion palette below.

### 10.3 Color palette extracted from `refersion.com`

These values come from the live site's custom theme variables and inline styles.

**Core brand palette**

| Token | Hex | Role |
|---|---|---|
| `brand-primary` | `#221F4E` | Deep indigo; header, headings, primary buttons |
| `brand-secondary` | `#3A86FF` | Electric blue; links, focus rings, data accents |
| `brand-text-body` | `#5F738C` | Original brand body text on white |
| `brand-bg-base` | `#F2F6EF` | Light greenish-grey page background |
| `brand-bg-blue` | `#D4E2F2` | Light blue surface/accent |
| `brand-bg-warm` | `#DED7D4` | Warm taupe surface/border accent |
| `brand-white` | `#FFFFFF` | Card surface |
| `brand-green` | `#54DE62` | Lime accent, success, CTA hover |
| `brand-cream` | `#FFF5E2` | Warm hover/accent surface |
| `brand-orange` | `#FF6B35` | Secondary accent / attention |
| `gradient-pink` | `#FF1053` | Decorative gradient start (site) |
| `gradient-blue` | `#3452FF` | Decorative gradient end (site) |
| `gradient-cyan` | `#32D6FF` | Decorative cyan gradient start |
| `gradient-teal` | `#2AC4EA` | Decorative cyan gradient end |

**Accessible application variants**

| Token | Hex | Role |
|---|---|---|
| `text-primary` | `#221F4E` | Primary text |
| `text-muted` | `#53677D` | Muted text on `#F2F6EF` (contrast 5.33:1) |
| `link` | `#1D4ED8` | Accessible link/action blue on white (6.7:1) |
| `border` | `#DED7D4` | Card/table borders |
| `success-bg` | `#54DE62` | Success fill; pair with dark green text |
| `success-text` | `#14532D` | Success text on lime (5.21:1) |
| `error-bg` | `#FEF3F2` | Error banner background |
| `error-text` | `#B42318` | Error text (6.57:1 on white) |
| `warning-bg` | `#FFF5E2` | Warning/stale banner background |
| `warning-text` | `#8A4B00` | Warning text on cream |
| `rank-gold` | `#D4A017` | Rank 1 accent |
| `rank-silver` | `#94A3B8` | Rank 2 accent |
| `rank-bronze` | `#B06A3B` | Rank 3 accent |

**Notes**

- The site's body color `#5F738C` is 4.45:1 on `#F2F6EF`, just below the 4.5:1 threshold for normal text. Use `#53677D` on the page background; keep `#5F738C` for text on white.
- Lime `#54DE62` is only 1.75:1 on white. Use it as a fill/indicator with dark text, never as small text on white.
- Brand blue `#3A86FF` is 3.48:1 on white. Use it for large text, focus rings, and decorative accents; use `#1D4ED8` for accessible links.
- The orange/red/teal/purple/dark colors used in testimonial logo boxes (`#FF5E04`, `#FF462D`, `#5FC5BF`, `#5C3C74`, `#1D4D42`, `#1C252C`, `#003055`, `#009639`) are customer logo colors, not core Refersion brand colors. Do not build the UI around them.

**Typography**

- Brand site uses `Catalogue` (headings) and `Cera Pro` (body), both licensed.
- For this app, bundle open-source stand-ins via Fontsource: `Manrope` for headings/display and `Inter` for body. If the project has licensed Catalogue files, they can be swapped in later.
- Monospace for IDs/numbers: `ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace`.

**CSS token sketch**

```css
@theme {
  --color-brand-ink: #221F4E;
  --color-brand-blue: #3A86FF;
  --color-brand-green: #54DE62;
  --color-brand-cream: #FFF5E2;
  --color-brand-orange: #FF6B35;
  --color-canvas: #F2F6EF;
  --color-surface: #FFFFFF;
  --color-surface-blue: #D4E2F2;
  --color-surface-warm: #DED7D4;
  --color-text: #221F4E;
  --color-text-muted: #53677D;
  --color-border: #DED7D4;
  --color-link: #1D4ED8;
  --color-error-bg: #FEF3F2;
  --color-error-text: #B42318;
  --color-warning-bg: #FFF5E2;
  --color-warning-text: #8A4B00;
}
```

### 10.4 Layout

```text
┌─────────────────────────────────────────────────────────────┐
│  Refersion mark        Affiliate program                    │
│                                                             │
│  Top affiliates leaderboard                 [Refresh data]  │
│  Ranked by lifetime total commission.                       │
│  Only affiliates opted in with “Display in leaderboard”.    │
│                                                             │
│  ┌───────────────────────────────────────────────────────┐  │
│  │ #   Affiliate              Email          Lifetime    │  │
│  │ 1   ● Avery Cole        lily@...       $7,025.13  │  │
│  │ 2   ● Jordan Blake         clark@...      $5,748.70  │  │
│  │ 3   ● Morgan Reed           peter@...      $5,726.66  │  │
│  │ 4   ● Casey Ellis     chauncey@...   $5,627.30  │  │
│  │ 5   ● Riley Chen         mitchel@...    $4,772.34  │  │
│  └───────────────────────────────────────────────────────┘  │
│                                                             │
│  ● Cached · Updated 2 min ago · Scanned 13 of 44            │
└─────────────────────────────────────────────────────────────┘
```

**Header:** brand mark/wordmark left, small "Affiliate program" eyebrow, title, subtitle, refresh button on the right (stacks below on mobile).

**Card:** max width ~64rem, centered, white, 1px border, 20px radius, soft shadow. A 4px accent bar at the top using `#54DE62` with a small `#3A86FF` segment.

**Table columns:** Rank (badge), Affiliate (avatar + name + `#id`), Email, Lifetime commission (right-aligned). On mobile, the table scrolls horizontally; the email column may be hidden below `sm` while remaining available in the card layout if implemented. Prefer a real `<table>` for semantics.

**Footer inside the card:** cache/updated status, scan count, and an eligibility hint. Keep it small and muted.

### 10.5 States

1. **Initial loading**
   - Page shell renders immediately via Inertia deferred props.
   - Five skeleton rows with shimmer; table header visible.
   - Refresh button disabled with a subtle spinner.
   - No layout shift when data arrives.

2. **Success**
   - Table with five rows (or fewer if the account has fewer eligible affiliates).
   - Top 3 rank badges: gold, silver, bronze.
   - Lifetime commission right-aligned, tabular numbers, currency formatted.
   - Footer: "Cached" or "Live", "Updated just now / 2 min ago", "Scanned X of Y affiliates".
   - Small green dot if fresh, blue dot if cached.

3. **Empty**
   - Trophy/flag illustration (inline SVG).
   - Title: "No eligible affiliates yet."
   - Body: "Set the “Display in leaderboard” custom field to `TRUE` to include an affiliate."
   - Secondary action: "Refresh data".

4. **Error**
   - Alert banner with `error-text` on `error-bg`.
   - Title: "Couldn't load the leaderboard."
   - Body: friendly message from the backend, plus "Reference: ABC123" in small text.
   - "Try again" button.
   - If previous data exists, keep the table visible below the banner with reduced emphasis and a "Showing last successful result" note.

5. **Refreshing**
   - Refresh button shows spinner and "Refreshing…", disabled.
   - Table remains visible with a subtle skeleton overlay or shimmer to signal work without losing context.
   - `aria-live="polite"` announces "Refreshing leaderboard".

6. **Fewer than five**
   - Show the available rows.
   - Small note: "Showing X of 5 possible spots — no more eligible affiliates were found."
   - Do not show empty filler rows.

### 10.6 Interactions

- Refresh uses Inertia `router.post('/leaderboard/refresh', {}, { preserveState: true, preserveScroll: true })`.
- `onStart` sets refreshing; `onFinish` clears it; `onError` handles unexpected transport errors.
- The last successful data is retained in a local `ref` so a failed refresh does not blank the page.
- Keyboard: refresh button is a native `<button>`; table is semantic; focus rings use `#3A86FF` with at least 2px visible outline.
- Motion: subtle 150–250ms fade/translate on rows; respect `prefers-reduced-motion: reduce` by disabling animations.
- No external images/avatars. Initials avatar circles are generated locally from the name with deterministic background colors from the brand palette.

### 10.7 Accessibility

- Semantic `<table>` with `<caption class="sr-only">`, `<thead>`, `<th scope="col">`.
- `aria-live="polite"` region for refresh/loading status.
- Error banner uses `role="alert"`.
- Empty state uses a heading and descriptive text.
- Color is not the only signal: rank number and text labels accompany color.
- Focus-visible states on all interactive elements.
- Minimum contrast: body text 4.5:1, large text 3:1, UI boundaries 3:1.
- Respect reduced motion.
- The page should remain usable at 200% zoom and 320px width.

---

## 11. Docker and operations

### 11.1 Dockerfile stages

1. **`assets`** (`node:22-alpine`):
   - `npm ci`
   - copy source
   - `npm run build`
2. **`vendor`** (`composer:2`):
   - copy `composer.json`/`composer.lock`
   - `composer install --prefer-dist --optimize-autoloader` (includes dev dependencies so tests run in the container)
   - copy source and dump autoload
3. **`runtime`** (`php:8.4-fpm-alpine`):
   - install `nginx`, `supervisor`, `curl`, `sqlite-dev`, PHP extensions `pdo_sqlite`, `sqlite3`, `opcache`
   - copy app from `vendor`, built assets from `assets`
   - copy Nginx/Supervisor/entrypoint configs
   - create `database/database.sqlite` and set `www-data` ownership on `storage`, `bootstrap/cache`, `database`
   - run as root for entrypoint (migrations/chown), then Supervisor starts PHP-FPM as `www-data` and Nginx as `nginx`
   - healthcheck hits `/health`

### 11.2 docker-compose.yml

```yaml
services:
  app:
    build: .
    ports:
      - "8080:80"
    env_file:
      - .env
    environment:
      APP_ENV: production
      APP_DEBUG: "false"
      APP_URL: http://localhost:8080
      DB_CONNECTION: sqlite
      DB_DATABASE: /var/www/html/database/database.sqlite
      CACHE_STORE: database
      SESSION_DRIVER: file
      QUEUE_CONNECTION: sync
    volumes:
      - sqlite-data:/var/www/html/database
    healthcheck:
      test: ["CMD", "curl", "-fsS", "http://localhost/health"]
      interval: 10s
      timeout: 3s
      retries: 5
      start_period: 20s

volumes:
  sqlite-data:
```

No public database port. No Redis service in the default stack.

### 11.3 Entrypoint

`docker/entrypoint.sh`:

1. `set -euo pipefail`
2. `cd /var/www/html`
3. Create `.env` from `.env.example` if missing.
4. If `APP_KEY` is empty, run `php artisan key:generate --force`, read the generated key from `.env`, and export it so PHP-FPM inherits it.
5. `mkdir -p database && touch database/database.sqlite`
6. `chown -R www-data:www-data storage bootstrap/cache database`
7. `php artisan migrate --force --no-interaction`
8. `php artisan route:cache`
9. `php artisan view:cache`
10. `chown -R www-data:www-data storage bootstrap/cache database`
11. `exec "$@"` → Supervisor starts Nginx + PHP-FPM.

Notes:

- Route and view caches keep the container fast. Config is intentionally read at runtime rather than cached, so the containerized test wrapper can override the production environment with `APP_ENV=testing` and the array/in-memory test settings.
- Migrations at startup are acceptable for a single-container demo. For production, run migrations as a separate release job.
- If the SQLite volume is wiped, the app re-creates the schema and refetches from Refersion.

### 11.4 Nginx

- Root `/var/www/html/public`.
- `try_files $uri $uri/ /index.php?$query_string`.
- `fastcgi_pass 127.0.0.1:9000`.
- Gzip for text assets.
- Security headers: `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy` minimal.
- Static asset caching for `/build/`.
- No directory listing.

### 11.5 Environment variables

`.env.example` documents every variable with empty secrets:

```dotenv
APP_NAME="Refersion Leaderboard"
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost:8080

REFERSION_CLIENT_ID=
REFERSION_CLIENT_SECRET=
REFERSION_AUTH_URL=https://auth.refersion.com/oauth/token
REFERSION_BASE_URL=https://api.refersion.com/v2026-08
REFERSION_TOKEN_TYPE=short_lived
REFERSION_PAGE_LIMIT=100
REFERSION_CUSTOM_FIELDS_CHUNK=10
REFERSION_LEADERBOARD_SIZE=5
REFERSION_LEADERBOARD_CACHE_TTL=300
REFERSION_LEADERBOARD_LOCK_TTL=600
REFERSION_COMMISSION_UNIT=cents
REFERSION_MAX_PAGES=500
REFERSION_MAX_CANDIDATES=1000

DB_CONNECTION=sqlite
DB_DATABASE=database/database.sqlite
CACHE_STORE=database
SESSION_DRIVER=file
QUEUE_CONNECTION=sync
```

### 11.6 Commands

```bash
# First-time setup
cp .env.example .env
# Fill REFERSION_CLIENT_ID and REFERSION_CLIENT_SECRET in .env

# Start everything
docker compose up --build

# App
open http://localhost:8080

# Tests
bash scripts/test-container.sh

# Formatting
docker compose exec app ./vendor/bin/pint --test

# Stop
docker compose down

# Stop and remove cache volume (forces fresh API data)
docker compose down -v
```

Optional `Makefile` targets: `setup`, `up`, `down`, `test`, `lint`, `build`.

### 11.7 CI (GitHub Actions)

- Trigger on push and pull request.
- PHP 8.4 + Node 22.
- Composer install, npm ci, npm run build.
- Copy `.env.example` to `.env`, generate app key, create SQLite file, run migrations.
- `php artisan test`.
- `./vendor/bin/pint --test`.
- `./vendor/bin/phpstan analyse --memory-limit=1G` (Larastan level 7).
- `npm run format:check`, `npm run lint:check`, `npm run type-check`, and `npm test`.
- All tests use `Http::fake()`; no secrets required in CI.

---

## 12. Testing and verification

### 12.1 Test strategy

- Unit-test the service/client with `Http::fake()` and `Http::preventStrayRequests()`.
- Use `Sleep::fake()` (or an injected sleeper) for retry/backoff tests.
- Freeze time for token TTL tests using `Carbon::setTestNow()`.
- Reset cache between tests with `Cache::flush()` or the array cache store.
- Feature-test the Inertia page and refresh route.
- Do not make real network calls in the test suite.
- Use a **synthetic, anonymized fixture** under `tests/Fixtures/refersion/` that reproduces the documented top five and the near-match decoys. Do not commit the full live 44-affiliate payload: it contains PII and is not needed for correctness.
- Early-exit request-count tests use purpose-built synthetic fixtures (e.g. 12 affiliates, decoys in the top 10) so the expected `Http::assertSentCount()` is deterministic and independent of the live account's distribution.
- Live verification with the sample account is a **manual** step, not a CI gate. It requires local `.env` credentials. The documented expected top five in §5.3 is the reference; if the sample account changes, update the documentation rather than coupling the automated tests to live data.

### 12.2 Required test cases

**Auth**

1. Exchanges credentials for a token and sends `Bearer` on API calls.
2. Reuses the cached token across multiple service calls; only one `POST /oauth/token`.
3. Requests a new token when the cached token is expired.
4. On API `401`, invalidates and retries once with a fresh token.
5. Multiple `401`s from a pooled batch trigger exactly one token refresh (single-flight) and the batch is retried once with the new token.
6. Missing credentials produce a clear configuration/auth exception.

**Pagination**

7. Fetches page 1 then page 2 and stops when page 2 has fewer than `limit`.
8. Stops on an empty first page.
9. Exactly `limit` records on every page continues to the next page.
10. Malformed `data` throws `RefersionMalformedResponseException`.

**Eligibility**

11. `"TRUE"` qualifies.
12. `"true"`, `"TRUE "`, `"TRU"`, `"TRUe"`, `"FALSE"`, `"false"`, `"no"`, `"yes"`, `"YUP"`, missing field, and null value do not qualify.
13. A matching label with a different case does not qualify.
14. Duplicate custom fields: any exact `"TRUE"` qualifies.

**Ordering and top five**

15. Sorts by commission descending, integer cents, not lexicographic.
16. Ties break by affiliate ID ascending.
17. Returns only five entries when more than five are eligible.
18. Returns fewer than five when fewer are eligible.
19. Null/missing commission sorts as zero.
20. Negative commissions sort below positive values.

**Early exit**

21. With 12 affiliates and five eligible in the first five sorted candidates, only the minimum number of custom-field requests is sent (assert `Http::assertSentCount`).
22. With decoys in the top 10, a second chunk is fetched and the fifth eligible is found; the remaining affiliates are never requested.
23. No custom-field requests are sent when the affiliate list is empty.
24. When `REFERSION_MAX_CANDIDATES` is reached before five eligible affiliates are found, `RefersionScanLimitException` is thrown and no partial top five is returned.

**Resilience**

25. `429` with `Retry-After` waits and retries; a subsequent success returns data.
26. Persistent `429` throws `RefersionRateLimitException` after the retry budget.
27. `500` retries and eventually throws `RefersionApiException` if it persists.
28. `404` on one affiliate's custom fields skips that affiliate and still returns the rest.
29. `401` after a fresh-token retry throws `RefersionAuthenticationException`.

**Caching**

30. Second leaderboard call is served from cache and makes no new API calls.
31. Force refresh bypasses the cache and replaces it on success.
32. Failed refresh does not destroy the previous cache entry.
33. Token cache and leaderboard cache use distinct keys.
34. Cache lock contention returns cached data or a friendly busy error (test the lock timeout path if practical).

**HTTP/Inertia**

35. `GET /` returns 200 and the `Leaderboard` Inertia component.
36. `POST /leaderboard/refresh` returns the page with fresh props and preserves state.
37. Upstream failure returns the page with an `error` payload and a reference; no raw exception text.
38. Refresh route is throttled at 10 requests/minute (test the 11th request gets 429).
39. `GET /health` returns 200 and does not call Refersion.

**Frontend**

40. `formatCurrency(702513)` returns `$7,025.13`.
41. `formatCurrency(574870)` returns `$5,748.70`.
42. Empty, error, loading/skeleton, and top-three badge rendering (Vitest + React Testing Library, if time allows).

### 12.3 Manual verification

1. Put API credentials only in the local `.env`; confirm `.env` is ignored by Git.
2. `docker compose up --build`.
3. Open `http://localhost:8080`.
4. Verify the expected top five order and amounts from §5.3.
5. Verify Taylor Quinn (`"TRUE "`) is absent.
6. Click "Refresh data"; observe spinner/skeleton, then the same data with an updated timestamp.
7. Stop network/API credentials or set an invalid secret; refresh and verify the friendly error banner with a reference code, not a stack trace.
8. Restart the container; confirm the cache persists via the SQLite volume and the page loads without an immediate API call (check logs).
9. `bash scripts/test-container.sh` — all green.
10. `docker compose exec app ./vendor/bin/pint --test` — clean.
11. `bash scripts/phpstan.sh` — Larastan level 7 clean.
12. Run a final `npm run build` and `php artisan test` on the host or in CI.
13. Scan the staged files and Git history for credentials before pushing.

### 12.4 Acceptance criteria

- [ ] Dockerized Laravel + React app starts with documented commands.
- [ ] All affiliates are paginated until a short page.
- [ ] Exact custom-field eligibility is enforced.
- [ ] Top five are ranked by lifetime commission descending.
- [ ] OAuth token is cached and reused with a lock.
- [ ] Leaderboard is cached; refresh bypasses and replaces it.
- [ ] Custom-field requests use concurrency and early exit.
- [ ] 429/5xx/401/404 cases are handled per §8.5.
- [ ] UI has loading, success, empty, error, and refreshing states.
- [ ] Top three have gold/silver/bronze treatment.
- [ ] Currency formatting is correct (cents → USD).
- [ ] Table is responsive and accessible.
- [ ] Tests cover pagination, filtering, ordering, caching, errors, and early exit.
- [ ] README, AI_USAGE.md, architecture decisions, project status, and next steps are documented.
- [ ] CI runs tests and formatting.
- [ ] No secrets or live affiliate PII are committed.
- [ ] The staged files and Git history have been scanned for credentials before pushing.

---

## 13. Security

- Secrets only in `.env`; `.env` is gitignored. `.env.example` has empty placeholders.
- Never expose the client secret, access token, or raw `Authorization` header to React, logs, or error payloads.
- Do not log affiliate names/emails; log affiliate IDs only at warning level.
- Sanitize all upstream errors; show generic messages plus a correlation reference.
- React escapes interpolated text by default; do not use `dangerouslySetInnerHTML` with API data.
- CSRF protection is active for the refresh POST via Laravel/Inertia.
- Inbound throttling: `throttle:10,1` on refresh, `throttle:60,1` on the page.
- Nginx security headers as in §11.4.
- The refresh route requires no request body; the route itself is the explicit refresh action.
- Document production authentication/SSO as a next step.
- Do not commit API credentials; the operator supplies them via `.env`.

---

## 14. Documentation deliverables

### 14.1 README.md

Sections:

1. Overview and screenshot/GIF.
2. Why this solution (30-second summary).
3. Architecture diagram (Mermaid) and data flow.
4. API integration details: auth, pagination, custom fields, exact eligibility, early exit, rate limits.
5. Caching and refresh behavior.
6. Setup: prerequisites, `.env`, `docker compose up --build`, URL.
7. Testing and verification commands.
8. Engineering decisions and trade-offs (Inertia vs SPA, SQLite vs Redis, cents, cache TTL, sync vs queue).
9. AI usage summary and link to `AI_USAGE.md`.
10. Time spent and what was unfinished.
11. Next steps / production improvements.
12. Known limitations.

### 14.2 AI_USAGE.md

Include:

- Tools/models/harnesses used (e.g. OpenCode, DeepSeek model, effort level, any subagents).
- The main prompts used, verbatim or summarized.
- Tasks delegated to AI (spec drafting, scaffolding, service implementation, tests, UI, docs).
- What the human reviewed/changed and why.
- What AI got wrong or what required correction.
- How correctness was verified (tests, live API smoke checks, manual UI checks).
- The current planning session and the prior architecture conversation, if used.

### 14.3 Additional docs

- `docs/ARCHITECTURE.md` (optional if the README becomes too long).
- `docs/VERIFICATION.md` (optional test/evidence log).
- This document is the canonical design specification at `docs/superpowers/specs/2026-11-12-refersion-leaderboard-design.md`.

---

## 15. AI usage disclosure plan

AI-assisted development should be documented openly, not treated as an afterthought.

- Keep `AI_USAGE.md` updated during implementation, not written from memory at the end.
- Record the exact model/effort/harness for each major task.
- Include prompts that shaped architecture (e.g. "use Http::pool, DTOs, exact string match, cache token with lock") and prompts for tests.
- Show where AI output was rejected or corrected (e.g. trimming `"TRUE "`, treating commission as dollars, forgetting token revocation risk).
- Reference the prior architecture discussion PDF, if used.
- Keep the disclosure honest: the maintainers remain responsible for the code and decisions.

---

## 16. Implementation timeline

| Minutes | Work |
|---:|---|
| 0–10 | Scaffold Laravel + Inertia + React + Tailwind; Dockerfile/Compose; env/config skeleton |
| 10–25 | `Money`, DTOs, exceptions, `RefersionAuthService`, `RefersionClient` (pagination + pool + retries) |
| 25–40 | `LeaderboardService` (sort, strict filter, early exit, cache/locks), controller, routes, health |
| 40–55 | React leaderboard page, table, badges, skeleton, empty/error/refresh states, palette/typography |
| 55–70 | Pest tests (auth, pagination, eligibility, ordering, early exit, errors, cache) and fixes |
| 70–80 | Docker end-to-end run, live API verification, UI polish, responsive/accessibility pass |
| 80–88 | README, AI_USAGE, architecture/trade-offs, next steps, CI |
| 88–90 | Final review, git commit/push, confirm clean working tree |

If time runs out, stop feature work and document the unfinished items in README and the final response. Do not silently omit tests or documentation.

---

## 17. Known limitations and next steps

### Known limitations of the first delivery

- Single-container SQLite cache is not suitable for horizontal scaling without moving to Redis.
- Synchronous refresh can take a few seconds for very large accounts; a queued job + polling/websocket would be better.
- USD is assumed; per-account/per-affiliate currency is not handled.
- No user authentication/authorization on the demo route.
- No historical trend, search, filtering, or pagination of the leaderboard itself.
- Inertia error responses use HTTP 200 with an error payload for a friendly page; a public API would use 5xx status codes.
- Image/avatar is initials only; no affiliate profile images.
- No webhook-driven cache invalidation.
- No dark mode.
- No browser E2E test (manual check + component tests instead).

### Next steps (in priority order)

1. Move cache/locks to Redis and add a healthcheck; document horizontal scaling.
2. Move leaderboard refresh to a queued job (`SyncLeaderboardJob`) with progress/notification, and add a scheduled warm-up.
3. Add Refersion webhooks (conversion/affiliate updates) to invalidate/refresh the cache.
4. Add production authentication/SSO and role-based access.
5. Add per-account currency resolution and formatting; support multi-currency.
6. Add a proper public JSON API (`/api/v1/leaderboard`) if other clients need the data.
7. Add adaptive rate-limit handling with a shared token bucket and metrics.
8. Add observability: structured logs, metrics (API latency, cache hit rate, scanned candidates), tracing.
9. Add browser E2E tests (Playwright) for loading/error/refresh flows.
10. Add a dark theme and additional accessibility audits.

---

## 18. Decisions and defaults

The following defaults are used for the first implementation. Inertia + React is confirmed; the other choices are configurable and can be changed without affecting the core design.

1. **Frontend coupling**
   - Confirmed: Inertia + React in the same Laravel app.
   - Alternative: Laravel JSON API + separately built React SPA.
   - Impact: affects routing, build, deployment, and test strategy.

2. **Cache backend**
   - Default: SQLite/database cache, one container.
   - Alternative: Redis, two containers.
   - Impact: Docker Compose shape, failure modes, and the scaling story.

3. **Commission unit**
   - Default: cents (live data confirms), with `REFERSION_COMMISSION_UNIT` override.
   - Alternative: treat API values as dollars.
   - Impact: displayed amounts and tests. Ranking is unaffected.

4. **Credentials handling**
   - Default: `.env` only, never committed; the operator supplies API credentials.
   - Alternative: commit sample credentials for a zero-setup demo (not recommended).
   - Impact: one-command setup vs. security best practice.

These defaults are accepted for the first implementation. Any change can be made through the config/env layer for #2 and #3, and through the controller/page boundary for #1.
