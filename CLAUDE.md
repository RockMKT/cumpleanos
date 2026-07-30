# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Single-page birthday-benefit landing page for Rock&Feller's ("R&F"). A visitor
fills a form on their birthday to claim a gift; the submission is validated,
stored in MySQL, and triggers a confirmation email (SendGrid). Served by
Nginx + PHP-FPM (deployed with **Laravel Forge**): the static page and the PHP
endpoint live in the same site — no separate backend process, no CORS. All UI
text is in Spanish (es-AR).

## Architecture

- **Web root is `public/`** (Laravel/Forge convention). Nginx serves everything
  from there; the `.env` with secrets sits one level up (project root) so it is
  never web-accessible.
- **Frontend** — `public/index.html` is the entire client: one self-contained
  file with inlined `<style>` and `<script>` (vanilla ES5-style JS, no build
  step, no framework). Assets (`hero.png`, `logo.png`, fonts, carousel images)
  sit next to it in `public/` and are referenced by relative path.
  `canvas-confetti` is loaded from a CDN.
- **Backend** — `public/submit-birthday.php` is the only endpoint. It handles
  `POST /submit-birthday.php`: validates input, enforces rate limiting, upserts
  the claim (PDO/MySQL), and sends the email (SendGrid via cURL). No Composer,
  no dependencies — a small inline `.env` loader reads the project-root `.env`.
- **Database** — MySQL, accessed via **PDO** with prepared statements. Schema
  lives in `schema.sql` (repo root) and must be applied **manually once** (Neon
  editor / phpMyAdmin / `mysql < schema.sql`) — there are no migrations. Two
  tables: `birthday_claims` (unique on `email`) and `submission_attempts`
  (rate-limit log).

### Request flow (`submit-birthday.php`)
1. Reject non-POST; parse JSON body from `php://input`.
2. **Honeypot**: if the hidden `website` field is filled, return `200 {ok:true}`
   without touching the DB (silent bot rejection).
3. Validate `nombre`, `fecha` (`YYYY-MM-DD`), `email`, `sucursal` (must be in the
   known list), and `terminos` accepted → `400 invalid_input` otherwise.
4. `isBirthdayToday` checks month+day against **America/Argentina/Buenos_Aires**
   time (server-side via `DateTime`/`DateTimeZone`) → `403 not_birthday`. The
   client repeats this check before submitting, but the server is authoritative.
5. Rate limit by IP: 5/min and 20/hour (`$RATE_LIMIT_PER_MINUTE`/`_PER_HOUR`) via
   `submission_attempts` → `429 rate_limited`.
6. Upsert into `birthday_claims` (`ON DUPLICATE KEY UPDATE` on `email`).
7. Send SendGrid confirmation email; failures are logged (`error_log`) but do
   **not** fail the request.

Client IP comes from `X-Forwarded-For` (first value) with `REMOTE_ADDR`
fallback. On Forge behind Nginx, confirm which one carries the real client IP
(if fronted by Cloudflare/proxy, `X-Forwarded-For` is correct).

### Sucursal → SendGrid group mapping
Sucursales are split into **Group A** and **Group B** (`$GROUPS` array). Each
group has its own SendGrid API key, template, and sender identity, selected per
submission by `groupForSucursal`. When adding/renaming a sucursal, update both
the `$GROUPS` list in `submit-birthday.php` **and** the `<select id="sucursal">`
options in `public/index.html` — they must stay in sync (the server rejects any
sucursal not in its list).

### Known intentional behavior
The once-per-year duplicate/eligibility check is **deliberately disabled** (never
ported from the original Node function — see the comment in `submit-birthday.php`)
so one email can claim multiple times per year (e.g. a parent claiming for
several children). The `409 already_claimed` branch still exists in the client JS
but is currently unreachable. Don't "fix" this without confirming intent.

## Environment variables

Read from the project-root `.env` (gitignored; template in `.env.example`; on
Forge, edited via the site's Environment tab):
- `DATABASE_HOST`, `DATABASE_PORT`, `DATABASE_NAME`, `DATABASE_USER`,
  `DATABASE_PASSWORD` — MySQL connection.
- Per group (`_GROUP_A` / `_GROUP_B` suffix): `SENDGRID_API_KEY_*`,
  `SENDGRID_TEMPLATE_ID_*`, `SENDGRID_SENDER_EMAIL_*`, `SENDGRID_SENDER_NAME_*`.

## Commands

There is no build, lint, or test tooling — it's static HTML + one PHP file.

- Local dev (Laragon): point the vhost **document root to `public/`**, create the
  MySQL DB, apply `schema.sql`, and fill `.env`.
- DB setup: `mysql -u USER -p DBNAME < schema.sql` (or paste into
  HeidiSQL/phpMyAdmin) — run once.
- Deploy: Laravel Forge (git push to the connected branch). Set the site's web
  directory to `/public` and load env vars in Forge's Environment tab.

## Conventions

- Frontend JS is intentionally old-school (`var`, `function` expressions, no
  modules) — match that style when editing `public/index.html`.
- PHP is plain procedural, no framework/Composer — keep it dependency-free.
- User-facing copy is Spanish (Argentina). Keep new strings consistent in tone.
- Commit messages in this repo are written in Spanish.
