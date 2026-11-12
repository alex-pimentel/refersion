# AI Usage

This document records how AI tools were used while designing and building this example project. It is kept in the repository so the process stays transparent and reproducible.

---

## Tools and models

| Purpose | Tool |
|---|---|
| Coding agent / harness | OpenCode |
| Primary model | DeepSeek V4.1 Flash 1017 via the `opencode-go` provider |
| Effort | Default model effort; no custom reasoning budget was configured |
| Prior architecture exploration | A Gemini conversation supplied as context
| API inspection | Refersion documentation plus read-only requests to the sample account |
| Brand palette extraction | Public `refersion.com` HTML/CSS and the site's custom theme variables |

No AI tool was given the API client secret as part of the context. The credentials supplied with .env for the project were used locally for read-only smoke tests and were never written to subagents.

---

## Main prompts and tasks

### 1. Initial project request

> Create a Laravel project with Docker and a frontend, and write the specification from the project requirements. The app must build a leaderboard of the top five affiliates by lifetime total commission, using the Refersion API.

The frontend direction started with Vue and was later changed to **React + Inertia** to match the target production stack. The specification, README, component names, and test strategy were updated accordingly.

The AI was asked to:

- Read the Refersion API documentation for authentication, affiliate listing, and custom fields.
- Evaluate the prior architecture recommendations in the supplied PDF.
- Propose a fast but credible architecture for a time-constrained first delivery.
- Identify the main engineering risks: external N+1 requests, OAuth token revocation, rate limits, strict string matching, and error handling.
- Draft a specification and a README.

### 2. Architecture recommendation

> Would you recommend Inertia.js or a separated backend and frontend?

The recommendation was **Inertia + React for this project** because it keeps the delivery to one Laravel application, one build, one deployment, and one origin. The alternative — a Laravel JSON API plus a separately deployed React SPA — remains documented as the better choice when the frontend is reused by other clients or owned by another team.

### 3. Brand palette extraction

> Also extract the color palette from https://www.refersion.com/.

The AI inspected the public site's HTML and custom theme CSS, extracted the core brand tokens, and separated them from the customer logo colors used in testimonial cards. The resulting palette is documented in the specification and README.

### 4. README presentation

> Present the README as a standalone example project, in clear English.

The README was rewritten to describe a standalone example project. The design document and this AI usage log follow the same public-facing tone.

### 5. Docker context

> Do not forget to add the `/docs` folder to `.dockerignore`.

The Docker build context excludes `docs/` and other files that are not needed at runtime. The design document remains in the repository but is not copied into the application image.

### 6. PHP static analysis

> I want level 7.

Larastan was added as a dev dependency with a `phpstan.neon` configuration at level 7, a `scripts/phpstan.sh` runner, and a CI step. The first level-7 run found seven type/iterable findings in the Refersion client; the docblocks and helper parameter types were tightened until the analysis reported no errors.

---

## What the AI produced

- The design specification in `docs/superpowers/specs/2026-11-12-refersion-leaderboard-design.md`.
- The project README and AI usage log.
- The Laravel services, DTOs, exceptions, controller, routes, and configuration.
- The React/Inertia leaderboard page and its loading, success, empty, error, and refresh states.
- The PHPUnit and Vitest test suites.
- The Larastan level-7 configuration and fixes.
- The Docker multi-stage build, Compose stack, Nginx/Supervisor configuration, entrypoint, and healthcheck.
- The GitHub Actions CI workflow.

The implementation was built and verified in this repository. The final verification is summarized below.

---

## What the AI got wrong or what needed correction

| Issue | Correction |
|---|---|
| The first design draft used Vue based on the initial direction. | The final stack is React 19 + Inertia, matching the target production stack. Frontend references, file names, and the test strategy were updated. |
| The Refersion schema examples could be read as major currency units. | The live conversions summed exactly to the affiliate totals and the magnitudes were realistic only as cents. The design treats values as integer minor units by default, with a configurable `REFERSION_COMMISSION_UNIT` override. |
| A naive eligibility check might trim or lowercase the custom-field value. | The sample data includes `true`, `TRUE ` (trailing space), `TRU`, and `TRUe`. The design uses strict, exact string comparison. |
| An initial palette pass included testimonial logo colors. | Those colors belong to customer logos, not the Refersion brand. The README and spec distinguish core brand tokens from decorative/customer colors. |
| A first README draft used submission-oriented language. | It was rewritten as a clear example-project README, per the project owner's instruction. |
| The first container test run inherited the production environment, causing CSRF and cache-isolation failures. | PHPUnit environment values now use `force="true"`, and `scripts/test-container.sh` supplies the testing/array/in-memory settings needed by the containerized suite. |
| A fresh-context final review found refresh-URL preservation, token-generation lifetime, `403` handling, token-endpoint transport handling, malformed-payload validation, stale-data presentation, and lock-duration issues. | Regression tests were added first, the implementation was corrected, and the full PHPUnit, Vitest, Docker, and build checks were rerun successfully. |
| The first implementation had no PHP static analysis. | Larastan level 7 was added; seven initial findings were fixed and the check now runs in CI. |

---

## Verification performed

- Read-only authentication against the Refersion sample account.
- Confirmed the list endpoint returns `data` and supports `page`/`limit`.
- Confirmed the custom-fields endpoint returns `data` with `label`/`value`.
- Confirmed the sample account contains 44 affiliates and several near-match values.
- Confirmed the expected top five for the sample account.
- Confirmed commission values behave as integer minor units by summing an affiliate's conversions.
- Inspected the public Refersion site's CSS variables and extracted the core palette.
- No write requests were made to the Refersion account.
- PHPUnit: 85 tests, 355 assertions.
- Vitest: 11 tests.
- Larastan level 7 reports no errors.
- `npm run build`, `npm run type-check`, ESLint, Prettier, and Pint all pass.
- Docker image builds, the container healthcheck passes, and the PHPUnit suite passes inside the container.
- Live read-only smoke test through the container returned the expected top five (documented here with fictional aliases): Avery Cole, Jordan Blake, Morgan Reed, Casey Ellis, and Riley Chen. It scanned 13 of 44 affiliates and made 20 custom-field requests.
- The automated suite uses `Http::fake()` and does not require network access or real credentials.
- A fresh-context review identified and the fix pass resolved: refresh URL preservation, persistent token generations, `403` refresh handling, token endpoint connection/`5xx` handling, malformed commission and custom-field validation, stale refresh presentation, and the leaderboard lock duration. Regression tests cover each fix.

---

## Human review

The project owner set the scope, stack direction, and presentation requirements. The implementation was generated with AI assistance and verified against the API documentation, a live read-only smoke test, the Docker runtime, and the automated test suites. The project owner remains responsible for the final code and for any changes made after this log.

---

## Security notes

- No API credentials are committed.
- No access tokens are written to logs, documentation, or Git history.
- The live API calls used for verification were read-only.
- The implementation keeps secrets in `.env` and sanitizes upstream errors before they reach the UI.
- `scripts/check-secrets.sh` fails if `.env` is tracked or a tracked file contains a non-empty credential value; CI runs it on every push and pull request.
- The Docker build context excludes `docs/`, `.env`, `README.md`, `AI_USAGE.md`, and repository metadata.
