# AGENTS.md

## What this is

Static PWA plus small PHP endpoints: **no build step or package manager, and no automated tests**. Git remote: `EdFasano70/test-electoral-espana` (branch `main`). `index.html` holds all markup, the inline Tailwind config, CSS, JS, and the 100-proposal dataset. `api/rating.php` stores 1–5 star app ratings in `/var/lib/test-electoral-espana/ratings.json` and returns aggregate stats. `api/survey.php` keeps the global number of completed tests in `/var/lib/test-electoral-espana/surveys.json`. Supporting files are `manifest.json`, `sw.js`, `elecciones.png` (source app logo, also used in the header and welcome screen), `icon-192.png`, `icon-512.png`, `icon-maskable-512.png`, the party logos `pp.png`, `psoe.png`, `vox.png`, `frenteamplio.png`, and `assets/video-thumbnail.jpg` (credits thumbnail from the source YouTube video).

## Deploy

- Production: `https://test-electoral-espana.45.79.220.248.sslip.io/` on Linode `root@45.79.220.248` (same Apache server as `tasafy`/`saludable`).
- DocRoot `/var/www/test-electoral-espana`; vhost `deploy/test-electoral-espana.conf`; logs `test-electoral-espana-{error,access}.log`.
- `SSHPASS='...' ./deploy.sh` (or `SSHPASS_FILE=/path`). It rsyncs, installs the vhost once, reloads Apache, and runs certbot on the first deploy. Never re-upload the vhost after certbot (it would overwrite the HTTPS/redirect config). Credentials are not stored in the repo.
- `api/rating.php` and `api/survey.php` persist to `/var/lib/test-electoral-espana/{ratings,surveys}.json`, outside the DocRoot. `deploy.sh` creates that dir `www-data:www-data 775`; the server needs `mod_php` (present).

## Preview / verify

- Serve over HTTP so the service worker works: `python3 -m http.server`, then open `http://localhost:8000/`. Opening `index.html` via `file://` renders the app but `sw.js` will not register.
- `python3 -m http.server` does not execute PHP: the rating widget shows «Estadísticas no disponibles» and the survey counter keeps its «—» placeholder. PHP is not installed in this workspace, so test both endpoints on the server (or with `php -S` if available).
- No automated checks — verify in a browser. Answering and navigation use the on-screen buttons only (no keyboard shortcuts).

## Code map (app logic in `index.html`)

- `PARTIES` — keys `PP`, `PSOE`, `VOX`, `FA` plus color/bg/border/text classes and `logo` paths.
- `RAW_PROPOSALS` — 100 entries `{ id, party, category, text }`.
- `class ElectoralTestApp` — screen switching (`showScreen('welcome'|'quiz'|'results')`), persistence, scoring, rendering.
- `computeResults()` — single source of truth for scores; used by both `showResults()` and `shareResults()`.
- Rating widget in the footer: `initRating()`, `loadRatingStats()`, `submitRating()`. Endpoint `api/rating.php` (GET = stats, POST = `{ rating: 1..5, clientId }`).
- Global survey counter: `loadSurveyCount()`, `submitSurveyCompletion()`, rendered by `[data-survey-count]`. Endpoint `api/survey.php` (GET = count, POST = increments and returns the new count).

## Invariants to preserve

- Dataset must stay at **100 proposals, exactly 25 per party**, ids 1–100 unique, and every `party` value must exist in `PARTIES`. Quick mode picks 5 per party = 20.
- The quiz is blind: never render `party.logo` (or the party code/name) on `screen-quiz`; party logos are only shown on the welcome and results screens. The app logo (`elecciones.png`) may appear in the header and welcome screen.
- Scoring: `matchPercent = agree / (agree + disagree)` per party. **`skip` is neutral and excluded from the denominator**; `disagree` lowers the ratio. Don't add skips back into `totalEvaluated`.
- `shareResults()` must stay consistent with `showResults()`: build both from `computeResults()` and use dynamic counts, never a hardcoded `/25`.
- State persists in `localStorage` under `electoral_test_es_2026_state`; changing its shape breaks saved-progress resume.
- `resetTest()` (formerly `confirmReset`) resets immediately, without a confirm dialog.
- Ratings are deduped per browser via `clientId` (`localStorage` key `electoral_test_es_2026_rating_id`); the server overwrites the same id, so the count stays unique. Keep the id matching the PHP regex `^[A-Za-z0-9_-]{8,64}$`.
- The global survey counter increments **once per completed test** (`surveyCounted`, persisted in the saved state); `submitSurveyCompletion()` is guarded and called from `showResults()`. Retakes count as new surveys.

## Styling / PWA gotchas

- Tailwind comes from the CDN with an inline `tailwind.config` defining colors `pp`/`psoe`/`vox`/`fa`; classes like `bg-pp`, `text-pp-light`, `border-fa` only resolve because of that config. Add theme tokens there, not via a build.
- `manifest.json` and `sw.js` are real same-origin files — required, because service workers and manifests cannot be registered from `blob:` URLs. Bump `CACHE_NAME` in `sw.js` (currently `electoral-test-v10`) when cached assets or caching behavior change, and update the `APP_SHELL` list when adding files. Keep `/api/` out of the SW cache (dynamic JSON must not be cached).
- Interpolated dataset fields are escaped with `escapeHtml()` before `innerHTML`; keep doing that for any dynamic/user-derived text.
- UI copy is Spanish.
