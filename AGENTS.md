# AGENTS.md

## Read first

- `README.md` — setup, behavior, and the intended developer workflow.
- `docs/superpowers/specs/2026-11-12-refersion-leaderboard-design.md` — architecture and trade-offs.
- `AI_USAGE.md` — AI disclosure; keep it current when AI usage changes.

## Environment

- The host has Node/npm and Docker, but **no PHP or Composer**. Use the repo scripts or Docker for all PHP work.
- Bare `php artisan ...` and `composer dev` on the host will fail.

## Commands

- Full PHP suite on the host: `bash scripts/test.sh`.
- Full PHP suite inside the running app container: `bash scripts/test-container.sh` (preferred for Docker verification).
- One PHP test: `bash scripts/test.sh --filter=RefersionClientTest`.
- PHP formatting: `bash scripts/pint.sh` / `bash scripts/pint.sh --test`.
- PHP static analysis: `bash scripts/phpstan.sh` (Larastan level 7, `app/` only).
- Credential check: `bash scripts/check-secrets.sh` before every commit and push.
- Frontend checks: `npm test`, `npm run type-check`, `npm run lint:check`, `npm run format:check`, `npm run build`.
- One frontend test: `npm test -- resources/js/components/status-badge.test.tsx`.
- Stack: `docker compose up --build`; health is `GET /health`; teardown is `docker compose down -v`.

## Architecture and seams

- Single Laravel 12 + Inertia 2 + React 19 + TypeScript + Tailwind 4 app; there is no separate API.
- Request flow: `routes/web.php` → `LeaderboardController` → `LeaderboardService` → `RefersionClient` → `RefersionAuthService`.
- `RefersionRetryPolicy` owns retries, backoff, `Retry-After`, and 401/403 classification. Do not add retry loops in the client or auth service.
- `LeaderboardEligibility` owns the exact `Display in leaderboard = TRUE` rule. Do not duplicate or normalize the label/value.
- `LeaderboardService` exposes `get()` and `refresh()`; there is no boolean force-refresh flag.
- Rankings use integer `commissionCents`; `REFERSION_COMMISSION_UNIT` defaults to cents.
- `LeaderboardController` returns page data `{ data, meta, error }`; `meta` is null on error.
- The only page is `resources/js/pages/leaderboard.tsx`; use `StatusBadge` and the `brand-*` / `status-*` tokens rather than arbitrary hex values.

## Non-obvious constraints

- `config/inertia.php` maps Inertia pages to lowercase `resources/js/pages`; add new pages there.
- Keep page-level Vitest tests in `resources/js/test/`. Files under `resources/js/pages/**/*.tsx` are glob-imported into the app bundle.
- The app container runs with `APP_ENV=production` and the database cache. Use `scripts/test-container.sh`; bare `docker compose exec app php artisan test` fails on test-environment isolation.
- `phpunit.xml` uses `force="true"` for test env values; keep it. Tests use fake Refersion URLs and `Http::fake()` and must never call the real API.
- `docker/entrypoint.sh` intentionally does not run `config:cache`; adding it breaks the containerized test environment.
- `.dockerignore` excludes `docs/`, `README.md`, `AI_USAGE.md`, `.github/`, and `.superpowers/`; do not expect them in the image.
- The runtime image includes Composer dev dependencies so tests can run in-container; a no-dev production split is a documented trade-off.
- Credentials live only in `.env`. Never commit credentials or live affiliate PII; fixtures use fictional names.
- `AffiliateSummary::displayEmail()` returns an empty string; React renders the dash. Do not put UI formatting back in the DTO.
- The POST refresh route has no request body and always forces a rebuild; no FormRequest is needed.
- The starter-kit UI directory was removed (`resources/js/components/ui`); use existing components or plain Tailwind instead of importing shadcn primitives.

## Before calling work complete

- Match CI: secret scan, PHPUnit (host and container), Pint, PHPStan 7, Vitest, `tsc`, Prettier, ESLint, and Vite build.
- Verify Docker health after code or Docker changes.
- Update `README.md`, the design spec, and `AI_USAGE.md` when behavior or verification changes.
