# Refersion Leaderboard — Implementation Plan

**Spec:** `docs/superpowers/specs/2026-11-12-refersion-leaderboard-design.md`  
**Stack:** Laravel 12 (current stable React starter kit), PHP 8.4, React 19, Inertia 2, TypeScript, Tailwind 4, SQLite cache, Docker.  
**Rule:** Every task ends with a green test run and a commit. Production behavior is written test-first.

## Global constraints

- Keep secrets in `.env`; never commit credentials or live affiliate PII.
- No auth screens, settings screens, or dashboard from the starter kit.
- Use the service/client/DTO/exception architecture from the spec.
- `docs/` stays out of the Docker build context.
- Tests use `Http::fake()` and never call the real Refersion API.
- Run tests with `bash scripts/test.sh`; run the frontend build with `npm run build`.
- Record any deviation as a `Ruling:` line in the execution ledger.

---

## Task 1: Scaffold, prune, and configure the runtime

**Goal:** A clean Laravel 12 + Inertia 2 + React 19 + Tailwind 4 application with the starter kit's auth/settings surface removed, a working local test runner, and the Refersion config block.

**Steps**

1. Keep the official Laravel React starter kit files; remove the auth, settings, dashboard, welcome, and demo UI surfaces.
   - Delete `routes/auth.php`, `routes/settings.php`.
   - Replace `routes/web.php` with a health route and a placeholder root route.
   - Delete `app/Http/Controllers/Auth`, `app/Http/Controllers/Settings`, and their tests.
   - Delete `resources/js/pages/auth`, `resources/js/pages/settings`, `resources/js/pages/dashboard.tsx`, `resources/js/pages/welcome.tsx`, the app/auth/settings layouts, and the unused nav/user/appearance components.
   - Simplify `app/Http/Middleware/HandleInertiaRequests.php` to share only `name` and flash messages.
   - Update `resources/js/app.tsx` to remove the theme initializer and use the brand progress color.
   - Delete the unused auth/settings tests; keep `tests/TestCase.php`, `tests/Pest.php` if the test runner uses it, and add a `tests/Feature/ScaffoldTest.php`.
2. Update project metadata.
   - `composer.json` name/description.
   - `package.json` name.
   - `resources/views/app.blade.php` title and font loading.
   - `config/app.php` name fallback.
3. Add `config/services.php` Refersion block with env-backed settings from the spec.
4. Add `scripts/test.sh` and `scripts/pint.sh` helpers that run the PHP tooling through the existing composer Docker image.
5. Update `phpunit.xml` for the testing environment (array cache, array session, in-memory SQLite, sync queue).
6. Add a smoke test: the application boots, `/up` returns 200, and the root route returns an Inertia response.

**Tests / verification**

- Write `tests/Feature/ScaffoldTest.php` with:
  - `test_health_endpoint_returns_ok`
  - `test_root_route_returns_inertia_response`
- Run `bash scripts/test.sh`.
- Run `npm run build` to prove the React starter kit still builds after pruning.

**Expected:** All PHP tests pass; `npm run build` succeeds; no auth/settings routes remain.

**Commit:** `chore: scaffold Laravel React application`

---

## Task 2: Money, DTOs, and exception types

**Goal:** The immutable domain vocabulary used by the service layer.

**Steps**

1. Write failing unit tests for `Money`:
   - integer cents pass through unchanged;
   - decimal values are treated as major units and converted to cents;
   - null/missing becomes zero;
   - negative values are preserved;
   - currency is retained.
2. Implement `app/Support/Money.php`.
3. Write failing tests for DTOs:
   - `AffiliateSummary` display-name fallback (name → company → email local part → `Affiliate #id`);
   - `LeaderboardEntry` rank/commission shape.
4. Implement `app/DTOs/CustomField.php`, `AffiliateSummary.php`, `LeaderboardEntry.php`, `LeaderboardMeta.php`, `LeaderboardResult.php`.
5. Implement typed exceptions in `app/Exceptions/`: `RefersionApiException`, `RefersionAuthenticationException`, `RefersionRateLimitException`, `RefersionMalformedResponseException`, `RefersionScanLimitException`, `LeaderboardBusyException`.

**Tests / verification**

- `bash scripts/test.sh --filter=Money`
- `bash scripts/test.sh --filter=Dto`

**Expected:** RED tests fail for missing classes/behavior, then pass after minimal implementation.

**Commit:** `feat: add domain DTOs, money handling, and typed exceptions`

---

## Task 3: RefersionAuthService

**Goal:** OAuth client-credentials exchange with cached, single-flight token renewal.

**Steps**

1. Write failing tests with `Http::fake()`:
   - token exchange sends form fields and returns the access token;
   - a second call reuses the cached token;
   - an expired cached token is replaced;
   - a 401 triggers one refresh and a retry;
   - concurrent-looking refresh attempts use one token request under the cache lock;
   - missing credentials throw `RefersionAuthenticationException`;
   - token/secret are not exposed in exceptions or logs.
2. Implement `app/Services/Refersion/RefersionAuthService.php`:
   - `token(): string`
   - `forget(): void`
   - `refresh(): string`
   - cache key/TTL and 30-second lock as specified.
3. Use `Cache::lock(...)->block(...)` and re-check the cache inside the lock.

**Tests / verification**

- `bash scripts/test.sh --filter=RefersionAuthService`

**Expected:** All auth tests green; one token request per cache window.

**Commit:** `feat: add cached Refersion OAuth token service`

---

## Task 4: RefersionClient

**Goal:** Paginated affiliate retrieval, pooled custom fields, and resilient HTTP behavior.

**Steps**

1. Write failing tests with `Http::fake()` / `Http::preventStrayRequests()`:
   - pagination stops on a short page;
   - pagination stops on an empty page;
   - exactly `limit` records continues to the next page;
   - malformed `data` throws;
   - custom fields map label/value correctly;
   - pooled custom fields return per-affiliate maps;
   - 429 respects `Retry-After` and retries;
   - persistent 429 throws `RefersionRateLimitException`;
   - 5xx retries then throws;
   - 404 custom fields are skipped;
   - 401 invalidates the token and retries once.
2. Implement `app/Services/Refersion/RefersionClient.php`:
   - `listAllAffiliates(): array`
   - `getCustomFieldsForMany(array $affiliateIds): array`
   - `getCustomFields(int $affiliateId): array`
   - retry/backoff helpers, pool handling, response validation.
3. Keep HTTP concerns out of the service and controller.

**Tests / verification**

- `bash scripts/test.sh --filter=RefersionClient`

**Expected:** All client tests green; no real network calls.

**Commit:** `feat: add paginated and pooled Refersion API client`

---

## Task 5: LeaderboardService

**Goal:** Exact eligibility, deterministic ranking, early exit, and cache behavior.

**Steps**

1. Write failing tests:
   - exact `"TRUE"` qualifies; near-matches do not;
   - commission sorting is numeric and descending;
   - ties break by affiliate ID;
   - only five entries are returned;
   - fewer than five returns fewer;
   - null/negative commissions are handled;
   - early exit stops custom-field requests once five are found;
   - empty affiliate list sends no custom-field requests;
   - `REFERSION_MAX_CANDIDATES` throws `RefersionScanLimitException`;
   - second call uses cache; force refresh rebuilds;
   - failed refresh preserves the previous cache entry.
2. Implement `app/Services/Leaderboard/LeaderboardService.php`:
   - `get(bool $forceRefresh = false): LeaderboardResult`
   - sort, chunk, pool, filter, early exit;
   - cache key/TTL/lock;
   - meta timestamps and request counters.
3. Keep the ranking logic in the service, not the controller.

**Tests / verification**

- `bash scripts/test.sh --filter=LeaderboardService`

**Expected:** All leaderboard tests green, including early-exit request counts.

**Commit:** `feat: add ranked leaderboard aggregation with early exit and caching`

---

## Task 6: HTTP and Inertia boundary

**Goal:** A small controller, validated refresh route, deferred page data, and friendly errors.

**Steps**

1. Write failing feature tests:
   - `GET /` returns the `leaderboard` Inertia component;
   - the deferred page data resolves to entries on success;
   - an upstream failure resolves to `data: []`, `meta: null`, and an error payload;
   - `POST /leaderboard/refresh` calls the service with force refresh;
   - refresh is throttled at 10/min;
   - `GET /health` returns 200 without calling Refersion.
2. Implement `app/Http/Controllers/LeaderboardController.php`:
   - `index()` uses `Inertia::defer(fn () => $this->buildPageData())`;
   - `refresh()` forces a rebuild and renders the same page;
   - `buildPageData()` catches domain exceptions, logs sanitized context, and returns the page payload.
3. Implement `app/Http/Requests/LeaderboardRefreshRequest.php`.
4. Replace `routes/web.php` with `/`, `/leaderboard/refresh`, and `/health`.
5. Add the TypeScript page-payload types in `resources/js/types/leaderboard.ts`.

**Tests / verification**

- `bash scripts/test.sh --filter=LeaderboardPage`
- `bash scripts/test.sh --filter=LeaderboardRefresh`
- `bash scripts/test.sh --filter=Health`

**Expected:** All HTTP tests green; no raw exception text in props.

**Commit:** `feat: add leaderboard controller, routes, and page data`

---

## Task 7: React leaderboard interface

**Goal:** The polished, responsive leaderboard UI with all required states.

**Steps**

1. Add Vitest + React Testing Library and a `test` script.
2. Write failing tests for:
   - `formatCurrency(702513)` → `$7,025.13`;
   - `formatCurrency(574870)` → `$5,748.70`;
   - empty state renders;
   - error state renders with a reference;
   - success table renders five rows and top-three badges.
3. Implement:
   - `resources/js/lib/format.ts`
   - `resources/js/pages/leaderboard.tsx`
   - `resources/js/components/leaderboard-table.tsx`
   - `rank-badge.tsx`, `affiliate-avatar.tsx`, `refresh-button.tsx`, `status-banner.tsx`, `empty-state.tsx`, `error-state.tsx`, `skeleton-table.tsx`
   - `resources/css/app.css` brand tokens and local fonts.
4. Wire the refresh action with Inertia `router.post` and preserve state/scroll.
5. Keep the last successful data visible when a refresh fails.

**Tests / verification**

- `npm test -- --run`
- `npm run build`

**Expected:** All frontend tests green; production build succeeds.

**Commit:** `feat: build responsive React leaderboard interface`

---

## Task 8: Docker runtime

**Goal:** One-command Docker environment with Nginx, PHP-FPM, SQLite, migrations, and a healthcheck.

**Steps**

1. Add `Dockerfile` with `assets`, `vendor`, and `runtime` stages.
2. Add `docker-compose.yml` with the app service, SQLite volume, env file, and healthcheck.
3. Add `docker/nginx.conf`, `docker/supervisord.conf`, `docker/entrypoint.sh`.
4. Ensure `.dockerignore` excludes `docs/`, `.env`, `vendor`, `node_modules`, built assets, and local runtime data.
5. Build and run:
   - `docker compose build`
   - `docker compose up -d`
   - `curl -fsS http://localhost:8080/health`
   - `docker compose exec app php artisan test`
6. Fix ownership, cache, and migration issues until the flow is reliable.

**Tests / verification**

- `docker compose build`
- `curl -fsS http://localhost:8080/health`
- `docker compose exec app php artisan test`
- `docker compose exec app ./vendor/bin/pint --test`

**Expected:** Container healthy; health endpoint returns 200; full suite green inside the container.

**Commit:** `build: add one-command Docker runtime`

---

## Task 9: CI, documentation, and final verification

**Goal:** Reproducible checks and an accurate README.

**Steps**

1. Update `.github/workflows/tests.yml` to run:
   - composer install;
   - npm ci;
   - npm run build;
   - `php artisan test`;
   - Pint check;
   - frontend type-check.
2. Update the README:
   - remove the "implementation next milestone" status;
   - add final setup and test commands;
   - add the architecture and verification summary;
   - link the spec and AI usage log.
3. Update the spec/README to Laravel 12 (the stable starter kit version) where needed.
4. Run:
   - `bash scripts/test.sh`
   - `npm test -- --run`
   - `npm run build`
   - `./vendor/bin/pint --test`
   - `npm run lint`
5. Confirm no credentials or live affiliate PII are tracked.
6. Commit the final documentation and CI updates.

**Tests / verification**

- Full PHP suite green.
- Full frontend suite green.
- Production assets build.
- Pint clean.
- ESLint clean.
- `git grep` secret scan clean.

**Expected:** CI-equivalent checks pass locally and the README matches the delivered code.

**Commit:** `docs: finalize project documentation and CI`

---

## Final review

After Task 9, run the whole-branch review with a fresh subagent using the `requesting-code-review` skill. Fix Critical/Important findings with TDD; defer Minor findings to the final report. Then run the full suite once more and report the commit range, test counts, and any deferred items.
