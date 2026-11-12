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

---

## What the AI produced

- The design specification in `docs/superpowers/specs/2026-11-12-refersion-leaderboard-design.md`.
- The project README.
- The initial palette extraction and accessibility notes.
- The proposed service/client/DTO/exception architecture.
- The test matrix and verification checklist.
- The Docker and CI strategy.

The application code, Docker configuration, and automated tests are the next implementation milestone. They were not generated in this documentation-only commit.

---

## What the AI got wrong or what needed correction

| Issue | Correction |
|---|---|
| The first design draft used Vue based on the initial direction. | The final stack is React 19 + Inertia, matching the target production stack. Frontend references, file names, and the test strategy were updated. |
| The Refersion schema examples could be read as major currency units. | The live conversions summed exactly to the affiliate totals and the magnitudes were realistic only as cents. The design treats values as integer minor units by default, with a configurable `REFERSION_COMMISSION_UNIT` override. |
| A naive eligibility check might trim or lowercase the custom-field value. | The sample data includes `true`, `TRUE ` (trailing space), `TRU`, and `TRUe`. The design uses strict, exact string comparison. |
| An initial palette pass included testimonial logo colors. | Those colors belong to customer logos, not the Refersion brand. The README and spec distinguish core brand tokens from decorative/customer colors. |
| A first README draft used submission-oriented language. | It was rewritten as a clear example-project README, per the project owner's instruction. |

---

## Verification performed during this phase

- Read-only authentication against the Refersion sample account.
- Confirmed the list endpoint returns `data` and supports `page`/`limit`.
- Confirmed the custom-fields endpoint returns `data` with `label`/`value`.
- Confirmed the sample account contains 44 affiliates and several near-match values.
- Confirmed the expected top five for the sample account.
- Confirmed commission values behave as integer minor units by summing an affiliate's conversions.
- Inspected the public Refersion site's CSS variables and extracted the core palette.
- No write requests were made to the Refersion account.

The automated test suite will verify the implementation without network access using `Http::fake()`.

---

## Human review

The project owner decides the final architecture, reviews the specification and README, and remains responsible for the implementation. AI output is treated as a draft: it is checked against the API documentation, the sample data, and the automated tests before it is accepted.

---

## Security notes

- No API credentials are committed.
- No access tokens are written to logs, documentation, or Git history.
- The live API calls used for verification were read-only.
- The final implementation must keep secrets in `.env` and sanitize all upstream errors before they reach the UI.
