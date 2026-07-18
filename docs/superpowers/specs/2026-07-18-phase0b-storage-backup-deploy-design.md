# Phase 0b: Storage, Backup, Database & Deploy — Design

**Date:** 2026-07-18
**Status:** Approved

## Purpose

Complete the Phase 0 foundation by wiring up the four remaining infrastructure pieces named in the original [Phase 0 design](2026-07-17-phase0-foundation-design.md): Google Drive file storage, nightly database backups, production MySQL, and automatic deployment via GitHub Actions. No feature modules are built in this phase.

## Corrections to Phase 0 Design: Personal Google Drive, No Service Account

The original Phase 0 design assumed a Google Workspace account with a Shared Drive, chosen specifically to avoid a known problem: a Google service account has no storage quota of its own, so it cannot own files directly.

**Correction 1 (superseded below):** since the user's Google account is a personal (non-Workspace) account, Shared Drives — a Workspace-only feature — are not available. The first revision proposed sharing a normal personal-Drive folder with a service account's email (Editor access), on the assumption that files the service account creates inside a shared folder would count against the folder owner's quota.

**Correction 2 (supersedes Correction 1):** that assumption was wrong, discovered while actually exercising the integration during implementation. Google's API returns this directly when a service account tries to create a file, even inside a folder shared with Editor access:

> "Service Accounts do not have storage quota. Leverage shared drives, or use OAuth delegation instead."

Sharing a folder only grants access to files someone else owns — it does not change who owns a *new* file the service account creates, and new files are always owned by their creator. A service account is therefore incapable of creating files in personal Drive under any sharing configuration; Shared Drives (Workspace-only) or OAuth delegation (also Workspace-only, via domain-wide delegation) are Google's only two ways around this, and neither is available on a personal account.

**Actual approach:** use OAuth 2.0 acting as the user's own Google account, instead of a service account. This is the standard mechanism personal (non-Workspace) Google Drive integrations use:

- A one-time interactive login (the user signs into Google once, in a browser, granting the app Drive access — the same "Sign in with Google" consent screen any app uses) produces a **refresh token**.
- That refresh token is stored in the server's `.env` and lets the app silently obtain fresh access tokens indefinitely, without repeating the login, until the user revokes access.
- Files the app creates are owned by the **user's own account** — using their real storage quota, not a service account's.
- No folder-sharing step is needed at all: the app can simply create/use a folder inside the user's own "My Drive", since it's acting as that account.
- No new subscription or cost is introduced. Trade-off versus a service account: if the user ever revokes the app's Google account access (e.g., from their Google Account security settings), the app loses Drive access until a new one-time login is performed.

## Architecture

### File Storage

- **Package:** `masbug/flysystem-google-drive-ext` — a maintained Flysystem v2/v3 adapter for Google Drive compatible with current Laravel versions (the original `nao-pon`/league adapter is unmaintained and incompatible with Flysystem 3).
- **Auth:** OAuth 2.0 "installed app" flow acting as the user's own Google account (see Corrections above) — not a service account. `Google\Client` is configured with a client ID, client secret, and a long-lived refresh token; it exchanges the refresh token for short-lived access tokens automatically on each request.
- **Disk:** A new `google` disk added to `config/filesystems.php`, configured from environment variables:
  - `GOOGLE_DRIVE_CLIENT_ID` / `GOOGLE_DRIVE_CLIENT_SECRET` — the OAuth client credentials (from a Google Cloud "OAuth client ID" of type Desktop app)
  - `GOOGLE_DRIVE_REFRESH_TOKEN` — obtained via the one-time interactive login
  - `GOOGLE_DRIVE_FOLDER_ID` — the ID of a folder in the user's own "My Drive" acting as the root (created directly, no sharing needed)
- **Folder structure inside the root folder:**
  - One subfolder per client company (named/keyed by `company_id`), holding uploaded documents (scanned invoices, receipts, bank statements) and generated invoice PDFs.
  - One `backups` subfolder for nightly database dumps.
- **MySQL's role:** stores only a reference (Drive file ID/link) to each file — never the file itself. This mirrors the existing Phase 0 multi-tenancy design (`company_id` scoping).
- **Credentials:** the OAuth client secret and refresh token live only in the server's `.env`, never committed to git. Local development can point at the same root folder, or a separate test folder — decided during execution, not a design concern.

### Nightly Database Backup

- **Package:** `spatie/laravel-backup`. Handles the MySQL dump, uploads it to the `google` disk's `backups` subfolder, and prunes backups older than the retention window automatically — no custom cleanup code required.
- **Retention:** 30 days, rolling. Older backups are deleted automatically by the package's built-in cleanup command.
- **Scheduling:** Registered on Laravel's scheduler (`routes/console.php` or `bootstrap/app.php` depending on Laravel 13's scheduling location) to run nightly. Laravel's scheduler itself depends on a single system cron entry on the droplet running `php artisan schedule:run` every minute — a one-time server setup step during execution, not a design decision.
- **Scope:** database only, per the original Phase 0 design (uploaded documents already live in Drive directly and don't need a separate backup pass in this phase).

### Production MySQL

- **Local development:** unchanged — continues using SQLite for zero-setup local work.
- **Production:** the droplet's `.env` sets `DB_CONNECTION=mysql` with real credentials (`DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`). Whether a database and dedicated user already exist on the droplet's MySQL server is unconfirmed — this is checked and created if missing during execution, not a design decision.
- **Migrations:** existing migrations from Phase 0a are already database-agnostic (no SQLite-specific syntax), so no migration changes are anticipated. This is verified during execution before the first production migration run.

### GitHub Actions Auto-Deploy

- **Trigger:** every push to `main`.
- **Mechanism:** a GitHub Actions workflow connects to the droplet over SSH using a dedicated deploy key (a new SSH key pair scoped only to this purpose — private key stored as a GitHub Actions secret, public key added to the droplet's authorized keys for the deploy user).
- **Deploy steps, run directly in the live folder (simple in-place deploy, not a release-swap):**
  1. `git pull` the latest `main`
  2. `composer install --no-dev --optimize-autoloader`
  3. `php artisan migrate --force`
  4. Build frontend assets (Tailwind/Vite)
  5. `php artisan config:cache`, `route:cache`, `view:cache` (clear then rewarm)
- **Downtime:** a few seconds per deploy while these steps run. Acceptable for a low-traffic internal accounting tool — explicitly chosen over a zero-downtime release-swap setup to avoid unnecessary operational complexity.
- **First deploy:** the droplet currently serves a static "Coming soon" placeholder at the app's domain. The first successful run of this workflow replaces that placeholder with the real Laravel app (`public/` as document root). Exact server paths, the Apache vhost update, and initial `.env` creation on the server are execution-phase tasks (the user already has SSH access to the droplet).

## Error Handling

- Failed backups: `spatie/laravel-backup` supports failure notifications (e.g. mail) out of the box. Decided during execution: left log-only for now (consistent with Phase 0's manual-check-only logging strategy) rather than wiring up real email delivery — a failed nightly backup is recorded in `storage/logs/laravel.log` but does not proactively alert anyone. Revisit if backups become critical enough to need active monitoring.
- Failed deploys: a failed GitHub Actions run blocks the workflow and leaves the previous code running in the live folder (since steps run in place, a failure mid-deploy could leave a partially-updated app — accepted trade-off of the simple deploy approach chosen above). The workflow run's logs in GitHub Actions are the source of truth for diagnosing a failed deploy.

## Testing

- File storage: a manual smoke test during execution — upload a test file through the `google` disk and confirm it appears in the user's Drive folder.
- Backup: manually trigger the backup Artisan command once during execution and confirm a dump appears in the `backups` subfolder; confirm the retention/cleanup command runs without error.
- MySQL: existing Phase 0a test suite (if any) re-run against MySQL locally or in CI to confirm no SQLite-specific assumptions broke.
- Deploy: the first push-to-main after setup is the live test — confirm the "Coming soon" placeholder is replaced by the real app and the deployed commit matches `main`.

## Out of Scope (this phase)

- All feature modules (Inventory, Invoicing, Accounting, Documents & Reports, HR, Settings UI).
- е-Фактура government integration.
- The actual Google Cloud OAuth client setup, the one-time interactive login to obtain a refresh token, creating the Drive folder, creating the MySQL database/user, and configuring the GitHub deploy key — these are execution-phase tasks handled during implementation, not design decisions.
- Backups or versioning of uploaded documents themselves (only the database is backed up nightly).
- Zero-downtime deployment tooling (release folders, symlink swaps, Envoyer/Deployer-style tooling).
