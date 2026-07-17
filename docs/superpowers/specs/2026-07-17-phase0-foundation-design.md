# Phase 0: Foundation — Design

**Date:** 2026-07-17
**Status:** Approved

## Purpose

Establish the technical foundation everything else in tami-web-app builds on: framework, database, multi-tenancy/roles, file storage, error logging, and deployment. No feature modules (Inventory, Invoicing, Accounting, etc.) are built in this phase — this is the skeleton they'll all sit inside.

## Architecture Overview

- **Framework:** Laravel (PHP), running on the Apache + PHP setup already installed on the DigitalOcean droplet. No new web server needed.
- **UI:** Livewire for interactive, reactive components (forms, live-updating tables) without a separate heavy JavaScript build pipeline. Tailwind CSS for a responsive, mobile-friendly layout in the brand colors (#ff6600 with white/light gray), usable from a phone or tablet browser — no native app.
- **Database:** MySQL, already installed and running on the droplet (used by other sites there). Holds all transactional data — accounting entries, invoices, inventory, users, companies.
- **File storage:** Google Drive, via a dedicated **Shared Drive** inside the user's existing Google Workspace account, accessed by a Google Cloud service account. Chosen over a personal Drive folder because Shared Drives avoid service-account storage-quota problems.
- **AI:** Claude API — Haiku 4.5 by default for OCR/document extraction and the chat assistant, falling back to Sonnet 5 for low-confidence or messy scans (handwriting, dense multi-page bank statements).

## Multi-tenancy & Roles

Single Laravel install, single MySQL database, shared schema. Every tenant-owned record (invoice, inventory item, journal entry, document, etc.) carries a `company_id` foreign key scoping it to one client company. This mirrors how QuickBooks Online/Xero operate internally and is far simpler to maintain than separate databases per client.

Three roles, managed via the Spatie Laravel-Permission package:

| Role | Scope |
|---|---|
| **Admin** (firm owner) | Full access to everything, across all client companies |
| **Accountant** (firm employees) | Scoped to whichever client companies they're explicitly assigned |
| **Client** | Locked to their own company's data only. Can view accounting status, use Inventory + Invoicing modules, upload documents |

## Data & Backup Strategy

- MySQL is the single source of truth for live/transactional data — fast, and safe for concurrent double-entry postings (something Google Drive's API cannot guarantee: no multi-file transactions, no row-level locking, no real query language).
- A nightly scheduled job dumps the MySQL database and uploads a timestamped copy to the Google Drive Shared Drive, so an off-server copy always exists.
- Uploaded documents (scanned invoices, receipts, bank statements) and generated invoice PDFs are stored directly in the Shared Drive, organized into per-client-company subfolders. MySQL stores only a reference (file ID/link) to each.

## Error Logging

Standard Laravel log files on the server (`storage/logs`), no external service. Chosen for simplicity — no third-party account or cost, at the trade-off of needing to check the logs manually rather than getting a proactive alert.

## Deployment

GitHub Actions auto-deploys on every push to `main`: connects to the droplet over SSH, pulls the latest code, installs dependencies, runs any new database migrations. No manual step required after a push. One-time setup: a dedicated deploy SSH key, scoped only to this purpose.

## Repository

Local project folder (`tami-web-app`) initialized as a git repository, connected to `github.com/GjorgjiMihailov/tami-web-app` over SSH. This document is the first commit.

## Out of Scope (this phase)

- All feature modules (Inventory, Invoicing, Accounting, Documents & Reports, HR, Settings UI) — each gets its own design cycle.
- е-Фактура government integration — explicitly deferred to near the end of the project.
- The actual Google Cloud/service-account click-through setup, and the GitHub Actions deploy-key configuration — these are execution-phase tasks, not design decisions.
