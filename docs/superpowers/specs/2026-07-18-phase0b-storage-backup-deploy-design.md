# Phase 0b: Storage, Backup, Database & Deploy — Design

**Date:** 2026-07-18
**Status:** Approved

## Purpose

Complete the Phase 0 foundation by wiring up the four remaining infrastructure pieces named in the original [Phase 0 design](2026-07-17-phase0-foundation-design.md): Google Drive file storage, nightly database backups, production MySQL, and automatic deployment via GitHub Actions. No feature modules are built in this phase.

## Correction to Phase 0 Design: Personal Google Drive, Not a Shared Drive

The original Phase 0 design assumed a Google Workspace account with a Shared Drive, chosen specifically to avoid a known problem: a Google service account has no storage quota of its own, so it cannot own files directly.

The user's Google account is a personal (non-Workspace) account, so Shared Drives — a Workspace-only feature — are not available. The revised approach solves the same underlying problem differently:

- A normal folder is created in the user's personal Google Drive.
- That folder is shared with the service account's email address, granting Editor access — the same mechanism as sharing a folder with a coworker.
- Files the app creates inside that folder count against the personal Drive's storage quota (the folder owner's), not the service account's, which is why this works without Workspace.
- No new subscription or cost is introduced.

## Architecture

### File Storage

- **Package:** `masbug/flysystem-google-drive-ultimate` — a maintained Flysystem v3 adapter for Google Drive compatible with current Laravel versions (the original `nao-pon`/league adapter is unmaintained and incompatible with Flysystem 3).
- **Disk:** A new `google` disk added to `config/filesystems.php`, configured from environment variables:
  - `GOOGLE_DRIVE_CLIENT_EMAIL` / `GOOGLE_DRIVE_PRIVATE_KEY` (or a path to the service account JSON key file)
  - `GOOGLE_DRIVE_FOLDER_ID` — the ID of the shared personal-Drive folder acting as the root
- **Folder structure inside the shared folder:**
  - One subfolder per client company (named/keyed by `company_id`), holding uploaded documents (scanned invoices, receipts, bank statements) and generated invoice PDFs.
  - One `backups` subfolder for nightly database dumps.
- **MySQL's role:** stores only a reference (Drive file ID/link) to each file — never the file itself. This mirrors the existing Phase 0 multi-tenancy design (`company_id` scoping).
- **Credentials:** the service account JSON key and folder ID live only in the server's `.env`, never committed to git. Local development can point at the same shared folder and service account, or a separate test folder — decided during execution, not a design concern.

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

- Failed backups: `spatie/laravel-backup` supports failure notifications (e.g. mail) out of the box; whether to wire up a notification channel is left to execution/implementation, since Phase 0's logging strategy is deliberately manual-check-only (no external error service).
- Failed deploys: a failed GitHub Actions run blocks the workflow and leaves the previous code running in the live folder (since steps run in place, a failure mid-deploy could leave a partially-updated app — accepted trade-off of the simple deploy approach chosen above). The workflow run's logs in GitHub Actions are the source of truth for diagnosing a failed deploy.

## Testing

- File storage: a manual smoke test during execution — upload a test file through the `google` disk and confirm it appears in the shared Drive folder.
- Backup: manually trigger the backup Artisan command once during execution and confirm a dump appears in the `backups` subfolder; confirm the retention/cleanup command runs without error.
- MySQL: existing Phase 0a test suite (if any) re-run against MySQL locally or in CI to confirm no SQLite-specific assumptions broke.
- Deploy: the first push-to-main after setup is the live test — confirm the "Coming soon" placeholder is replaced by the real app and the deployed commit matches `main`.

## Out of Scope (this phase)

- All feature modules (Inventory, Invoicing, Accounting, Documents & Reports, HR, Settings UI).
- е-Фактура government integration.
- The actual Google Cloud service-account click-through setup, generating/sharing the Drive folder, creating the MySQL database/user, and configuring the GitHub deploy key — these are execution-phase tasks handled during implementation, not design decisions.
- Backups or versioning of uploaded documents themselves (only the database is backed up nightly).
- Zero-downtime deployment tooling (release folders, symlink swaps, Envoyer/Deployer-style tooling).
