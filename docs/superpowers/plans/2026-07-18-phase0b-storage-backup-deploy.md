# Phase 0b: Storage, Backup, Database & Deploy — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wire up Google Drive file storage (via OAuth acting as the user's own Google account), nightly MySQL backups to that Drive folder, production MySQL configuration, and GitHub Actions auto-deploy to the droplet — completing the Phase 0 foundation.

**Architecture:** A custom Laravel filesystem disk (`google`) wraps a Flysystem Google Drive adapter authenticated via OAuth 2.0 (client ID/secret + a long-lived refresh token) acting as the user's own Google account — not a service account, which cannot own files on a personal (non-Workspace) Drive under any configuration. `spatie/laravel-backup` dumps MySQL nightly and uploads through that same disk, pruning anything older than 30 days. A GitHub Actions workflow runs the test suite against a real MySQL service container (proving migrations/config work on MySQL) and then, only on `main` and only if tests pass, SSHes into the droplet to pull and deploy in place.

**Tech Stack:** Laravel 13.20 / PHP 8.3, `masbug/flysystem-google-drive-ext` ^2.5, `spatie/laravel-backup` ^10.3, GitHub Actions (`actions/checkout@v7`, `shivammathur/setup-php@v2`, `actions/setup-node@v4`, `appleboy/ssh-action@v1.2.5`).

## Global Constraints

- PHP ^8.3, Laravel ^13.8 (installed: v13.20.0) — all new packages must be compatible with these floors.
- Local development keeps `DB_CONNECTION=sqlite`; only production `.env` switches to `mysql`. Never change `phpunit.xml`'s default (sqlite, in-memory) test config.
- Google Drive storage uses **OAuth 2.0 acting as the user's own Google account** — not a service account (confirmed via Google's own API: "Service Accounts do not have storage quota... use OAuth delegation instead") and not a Shared Drive (Workspace-only, not available). Never reference "Shared Drive" or "service account" as the auth mechanism in code, comments, or docs for this project.
- Backup retention is a rolling **30 days** — nothing older is kept.
- Deploy is a **simple in-place deploy** (git pull directly into the live folder) — no release-swap/symlink zero-downtime tooling.
- Credentials (OAuth client secret, refresh token, Drive folder ID, deploy SSH key, MySQL password) live only in the droplet's `.env` or GitHub Actions secrets — never committed to git.

---

### Task 1: Register the Google Drive filesystem disk

**Files:**
- Modify: `composer.json` (add `masbug/flysystem-google-drive-ext`)
- Create: `app/Providers/GoogleDriveServiceProvider.php`
- Modify: `bootstrap/providers.php`
- Modify: `config/filesystems.php` (add a `google` disk entry, alongside the existing `s3` disk)
- Modify: `.env.example` (add `GOOGLE_DRIVE_*` vars)
- Test: `tests/Unit/GoogleDriveDiskTest.php`

**Interfaces:**
- Produces: a Laravel `Storage` disk named `google` — usable anywhere as `Storage::disk('google')`, returning an `Illuminate\Filesystem\FilesystemAdapter`. Later tasks (backup) read/write through this same disk name.
- Consumes: `config('filesystems.disks.google')` with keys `client_id`, `client_secret`, `refresh_token`, `folder_id` (populated from env in production).

**Auth note (read before implementing):** `Google\Client::setAccessToken()` requires an `access_token` key to be present (throws `InvalidArgumentException` otherwise) — but it does NOT make a network call itself. Passing a stub empty `access_token` alongside the real `refresh_token` is safe and makes `isAccessTokenExpired()` correctly report "expired," which makes the library's own `authorize()` method (confirmed by reading `vendor/google/apiclient/src/Client.php:493-507`) automatically perform the real token refresh over the network, lazily, the first time an actual Drive API call is made — not when the disk is merely resolved. Do NOT call `$client->refreshToken(...)` directly in the service provider — that method (`Client.php:365`) performs the token exchange immediately and unconditionally, which would make every `Storage::disk('google')` resolution a blocking network call (bad for performance) and would break this task's unit test (which uses fake credentials and must not touch the network).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GoogleDriveDiskTest extends TestCase
{
    public function test_google_disk_resolves_to_a_filesystem_adapter(): void
    {
        config([
            'filesystems.disks.google' => [
                'driver' => 'google',
                'client_id' => 'fake-client-id.apps.googleusercontent.com',
                'client_secret' => 'fake-client-secret',
                'refresh_token' => 'fake-refresh-token',
                'folder_id' => 'fake-folder-id',
            ],
        ]);

        $disk = Storage::disk('google');

        $this->assertInstanceOf(FilesystemAdapter::class, $disk);
    }
}
```

This test uses fake credentials and must complete instantly with no network access — if it makes a real HTTP request, the service provider is calling `refreshToken()` eagerly instead of using `setAccessToken()` (see the Auth note above); that's a bug, fix the provider, don't add network mocking to the test.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=GoogleDriveDiskTest`
Expected: FAIL — `Driver [google] is not supported.` (no driver registered yet)

- [ ] **Step 3: Require the Google Drive Flysystem adapter**

Run: `composer require masbug/flysystem-google-drive-ext:^2.5`

- [ ] **Step 4: Add the `google` disk to `config/filesystems.php`**

Add this entry to the `disks` array, after the existing `s3` entry:

```php
        'google' => [
            'driver' => 'google',
            'client_id' => env('GOOGLE_DRIVE_CLIENT_ID'),
            'client_secret' => env('GOOGLE_DRIVE_CLIENT_SECRET'),
            'refresh_token' => env('GOOGLE_DRIVE_REFRESH_TOKEN'),
            'folder_id' => env('GOOGLE_DRIVE_FOLDER_ID'),
        ],
```

- [ ] **Step 5: Create the service provider that registers the disk driver**

```php
<?php

namespace App\Providers;

use Google\Client;
use Google\Service\Drive;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;
use Masbug\Flysystem\GoogleDriveAdapter;

class GoogleDriveServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Storage::extend('google', function ($app, array $config) {
            $client = new Client();
            $client->setClientId($config['client_id']);
            $client->setClientSecret($config['client_secret']);
            // Stub access_token + a real refresh_token: satisfies
            // setAccessToken()'s validation without making a network call.
            // The client library refreshes it lazily, automatically, the
            // first time a real Drive API request is made.
            $client->setAccessToken([
                'access_token' => '',
                'refresh_token' => $config['refresh_token'],
            ]);
            $client->addScope(Drive::DRIVE);

            $adapter = new GoogleDriveAdapter(new Drive($client), null, [
                // "sharedFolderId" is just the adapter's option name for "root
                // folder ID" — it works identically for a folder this OAuth
                // user owns outright, not only ones shared with them.
                'sharedFolderId' => $config['folder_id'],
            ]);

            return new FilesystemAdapter(new Filesystem($adapter), $adapter, $config);
        });
    }
}
```

- [ ] **Step 6: Register the provider**

In `bootstrap/providers.php`, add the new provider to the array:

```php
<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\GoogleDriveServiceProvider::class,
    App\Providers\VoltServiceProvider::class,
];
```

- [ ] **Step 7: Document the env vars**

Add to `.env.example`, near the existing `AWS_*` block:

```
GOOGLE_DRIVE_CLIENT_ID=
GOOGLE_DRIVE_CLIENT_SECRET=
GOOGLE_DRIVE_REFRESH_TOKEN=
GOOGLE_DRIVE_FOLDER_ID=
```

- [ ] **Step 8: Run test to verify it passes**

Run: `php artisan test --filter=GoogleDriveDiskTest`
Expected: PASS

- [ ] **Step 9: Commit**

```bash
git add composer.json composer.lock app/Providers/GoogleDriveServiceProvider.php bootstrap/providers.php config/filesystems.php .env.example tests/Unit/GoogleDriveDiskTest.php
git commit -m "Add Google Drive filesystem disk"
```

---

### Task 2 (Manual — requires your action): Get OAuth credentials and a Drive folder

This task can't be done by an agent alone — it requires clicking through Google's UI and a one-time interactive login as the user. Walk through each step with the user directly, waiting for confirmation before moving on. Where the controller has local Bash access on the same machine as the user's browser, the controller can run the token-exchange script itself instead of asking the user to run commands.

**Files:**
- Modify: local `.env` (for the smoke test) and, later in Task 6/8/9, the droplet's `.env` (not in git either way)

- [ ] **Step 1: Create a Google Cloud project** (skip if already done)

Go to https://console.cloud.google.com/projectcreate, create a new project (e.g. "tami-web-app").

- [ ] **Step 2: Enable the Google Drive API** (skip if already done)

"APIs & Services" → "Library" → search "Google Drive API" → Enable.

- [ ] **Step 3: Create an OAuth client ID**

"APIs & Services" → "Credentials" → "Create Credentials" → "OAuth client ID". If prompted to configure an OAuth consent screen first, choose "External" user type, fill in just the required fields (app name, support email), and add the Google account as a "test user" if asked — this app will only ever be used by that one account, so it never needs Google's app-verification review. For the client ID itself, set Application type to **Desktop app**, name it (e.g. "tami-web-app-drive"), and create it. Note the `client_id` and `client_secret` shown.

- [ ] **Step 4: Create a Drive folder**

In the user's own Google Drive (drive.google.com), create a folder (e.g. "Tami App Storage"). Open it and copy the folder ID from the URL (`https://drive.google.com/drive/folders/<FOLDER_ID>`).

- [ ] **Step 5: Obtain a refresh token via one-time login**

This is the one part of the whole plan that needs an actual interactive browser login as the Google account. The controller runs a short-lived local script (via Bash, on the same machine as the user's browser) that:
1. Starts a temporary local HTTP listener on a loopback port (e.g. `127.0.0.1:8090`).
2. Builds a Google OAuth authorization URL for scope `https://www.googleapis.com/auth/drive`, with `redirect_uri=http://127.0.0.1:8090`, `access_type=offline`, and `prompt=consent`.
3. Gives the user that URL to open in their own browser and approve.
4. Captures the `?code=` query parameter Google sends to the local listener after approval.
5. Exchanges that code for tokens via a POST to `https://oauth2.googleapis.com/token`, extracting `refresh_token` from the response.

The client_id/client_secret from Step 3 are needed for this exchange — treat client_secret like a password (same handling as Task 1 originally used for the service account key: read it from a file path, don't paste it into chat).

- [ ] **Step 6: Set the local `.env` values for the smoke test**

```
GOOGLE_DRIVE_CLIENT_ID=<from Step 3>
GOOGLE_DRIVE_CLIENT_SECRET=<from Step 3>
GOOGLE_DRIVE_REFRESH_TOKEN=<from Step 5>
GOOGLE_DRIVE_FOLDER_ID=<from Step 4>
```

- [ ] **Step 7: Manually verify the disk works**

```bash
php artisan tinker --execute="Storage::disk('google')->put('smoke-test.txt', 'hello from tami-web-app'); echo Storage::disk('google')->exists('smoke-test.txt') ? 'OK' : 'FAILED';"
```

Confirm it prints `OK`, then confirm `smoke-test.txt` actually appears in the Drive folder in the browser. Delete the test file afterward (`php artisan tinker --execute="Storage::disk('google')->delete('smoke-test.txt');"`).

These same four values get set on the droplet's `.env` later, once the app is actually deployed there (Task 8/9) — not before, since there's nowhere to put them until then.

---

### Task 3: Configure nightly database backups

**Files:**
- Modify: `composer.json` (add `spatie/laravel-backup`)
- Create: `config/backup.php` (published, then edited)
- Modify: `routes/console.php` (schedule the backup + cleanup commands)
- Test: `tests/Feature/DatabaseBackupTest.php`

**Interfaces:**
- Consumes: the `google` disk from Task 1 (`Storage::disk('google')`), by name only — production `config/backup.php` points its destination at `'google'`.
- Produces: two Artisan commands wired into the scheduler — `backup:run --only-db` (nightly at 02:00) and `backup:clean` (nightly at 03:00, after the backup finishes).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DatabaseBackupTest extends TestCase
{
    public function test_backup_run_creates_a_database_dump_on_the_configured_disk(): void
    {
        Storage::fake('backup-test');
        config(['backup.backup.destination.disks' => ['backup-test']]);

        Artisan::call('backup:run', ['--only-db' => true, '--disable-notifications' => true]);

        $zipFiles = array_filter(
            Storage::disk('backup-test')->allFiles(),
            fn (string $file) => str_ends_with($file, '.zip'),
        );

        $this->assertNotEmpty($zipFiles);
    }

    public function test_backup_clean_command_runs_without_error(): void
    {
        Storage::fake('backup-test');
        config(['backup.backup.destination.disks' => ['backup-test']]);

        $exitCode = Artisan::call('backup:clean', ['--disable-notifications' => true]);

        $this->assertSame(0, $exitCode);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=DatabaseBackupTest`
Expected: FAIL — command `backup:run` does not exist yet.

- [ ] **Step 3: Require and publish the package**

```bash
composer require spatie/laravel-backup:^10.3
php artisan vendor:publish --provider="Spatie\Backup\BackupServiceProvider"
```

This creates `config/backup.php` with Spatie's defaults.

- [ ] **Step 4: Edit `config/backup.php`**

Change these keys from their published defaults (leave everything else as published):

```php
    'backup' => [

        'name' => 'tami-web-app',

        'source' => [
            'databases' => [
                config('database.default'),
            ],
        ],

        'destination' => [
            'disks' => [
                'google',
            ],
        ],

        // ... (leave 'filename_prefix', 'temporary_directory' etc. as published)
    ],
```

**Why `'name'` is a fixed string, not `env('APP_NAME', ...)`:** `spatie/laravel-backup` namespaces every backup under a subfolder named after `backup.name` inside the destination disk (e.g. `{name}/2026-07-18-....zip`). Discovered live on the droplet: the `masbug/flysystem-google-drive-ext` adapter's `listContents()` throws `UnableToReadFile`/`UnableToListContents` for a subfolder that doesn't exist yet, instead of returning an empty list like local/S3 disks do — which breaks `BackupDestination::isReachable()` on the very first backup run. Using `env('APP_NAME', ...)` is doubly risky here: `APP_NAME` can contain spaces or change between environments, and either way the target subfolder must be pre-created in Drive once before the first `backup:run` (see Task 4's Step 2, updated below) since the adapter cannot create it implicitly the way it can for the local/S3 disks the package was primarily built around.

And under the top-level `cleanup` key, replace the `default_strategy` array with a flat 30-day rolling window (no tiered daily/weekly/monthly retention):

```php
    'cleanup' => [
        'strategy' => \Spatie\Backup\Tasks\Cleanup\Strategies\DefaultStrategy::class,

        'default_strategy' => [
            'keep_all_backups_for_days' => 30,
            'keep_daily_backups_for_days' => 0,
            'keep_weekly_backups_for_weeks' => 0,
            'keep_monthly_backups_for_months' => 0,
            'keep_yearly_backups_for_years' => 0,
            'delete_oldest_backups_when_using_more_megabytes_than' => 5000,
        ],
    ],
```

- [ ] **Step 5: Schedule the commands**

Add to `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('backup:run --only-db')->dailyAt('02:00');
Schedule::command('backup:clean')->dailyAt('03:00');
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=DatabaseBackupTest`
Expected: PASS

- [ ] **Step 7: Write a unit test confirming the schedule is registered**

```php
<?php

namespace Tests\Unit;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class BackupScheduleTest extends TestCase
{
    public function test_backup_commands_are_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->map(fn ($event) => $event->command);

        $this->assertTrue($events->contains(fn ($command) => str_contains((string) $command, 'backup:run')));
        $this->assertTrue($events->contains(fn ($command) => str_contains((string) $command, 'backup:clean')));
    }
}
```

Run: `php artisan test --filter=BackupScheduleTest`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add composer.json composer.lock config/backup.php routes/console.php tests/Feature/DatabaseBackupTest.php tests/Unit/BackupScheduleTest.php
git commit -m "Add nightly database backup to Google Drive with 30-day retention"
```

---

### Task 4 (Manual — requires your action): Enable the scheduler on the droplet and verify a real backup

**Files:**
- None (server configuration only)

- [ ] **Step 1: Add the Laravel scheduler cron entry**

SSH into the droplet. Run `crontab -e` for the user that owns the app files, and add:

```
* * * * * cd /path/to/tami-web-app && php artisan schedule:run >> /dev/null 2>&1
```

(Replace `/path/to/tami-web-app` with the actual deploy path on the droplet.)

- [ ] **Step 2: Pre-create the backup subfolder in Drive**

`spatie/laravel-backup` writes into a subfolder named after `config('backup.backup.name')` (`tami-web-app`) inside the `google` disk's root. The Google Drive adapter can't implicitly create this the way local/S3 disks can — it must already exist before the first `backup:run`, or the backup fails with `UnableToReadFile`/`UnableToListContents`. Create it once via tinker:

```bash
php artisan tinker --execute="Storage::disk('google')->makeDirectory('tami-web-app'); var_dump(Storage::disk('google')->exists('tami-web-app'));"
```

Confirm it prints `bool(true)`.

- [ ] **Step 3: Manually trigger a real backup once**

On the droplet:

```bash
php artisan backup:run --only-db
```

- [ ] **Step 4: Confirm it landed in Drive**

Check the Drive folder in the browser (or `php artisan tinker --execute="dd(Storage::disk('google')->allFiles());"`) for a new `.zip` backup file.

---

### Task 5: Add a CI job that tests against real MySQL

**Files:**
- Create: `phpunit.ci.xml`
- Create: `.github/workflows/deploy.yml` (this task adds the `test` job only; Task 7 adds the `deploy` job to the same file)

**Interfaces:**
- Produces: a `test` job that Task 7's `deploy` job will depend on via `needs: test`.

- [ ] **Step 1: Create the CI-specific PHPUnit config**

`phpunit.ci.xml` — identical to `phpunit.xml` except the database points at the CI MySQL service container:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
>
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Feature">
            <directory>tests/Feature</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>app</directory>
        </include>
    </source>
    <php>
        <env name="APP_ENV" value="testing"/>
        <env name="APP_MAINTENANCE_DRIVER" value="file"/>
        <env name="BCRYPT_ROUNDS" value="4"/>
        <env name="BROADCAST_CONNECTION" value="null"/>
        <env name="CACHE_STORE" value="array"/>
        <env name="DB_CONNECTION" value="mysql"/>
        <env name="DB_HOST" value="127.0.0.1"/>
        <env name="DB_PORT" value="3306"/>
        <env name="DB_DATABASE" value="tami_test"/>
        <env name="DB_USERNAME" value="root"/>
        <env name="DB_PASSWORD" value="root"/>
        <env name="MAIL_MAILER" value="array"/>
        <env name="QUEUE_CONNECTION" value="sync"/>
        <env name="SESSION_DRIVER" value="array"/>
        <env name="PULSE_ENABLED" value="false"/>
        <env name="TELESCOPE_ENABLED" value="false"/>
        <env name="NIGHTWATCH_ENABLED" value="false"/>
    </php>
</phpunit>
```

- [ ] **Step 2: Create the workflow file with the `test` job**

`.github/workflows/deploy.yml`:

```yaml
name: Test and Deploy

on:
  push:
    branches: [main]

jobs:
  test:
    runs-on: ubuntu-latest

    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_ALLOW_EMPTY_PASSWORD: false
          MYSQL_ROOT_PASSWORD: root
          MYSQL_DATABASE: tami_test
        ports:
          - 3306:3306
        options: >-
          --health-cmd="mysqladmin ping"
          --health-interval=10s
          --health-timeout=5s
          --health-retries=5

    steps:
      - name: Checkout code
        uses: actions/checkout@v7

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: mbstring, zip, pdo_mysql

      - name: Copy .env
        run: cp .env.example .env

      - name: Install Composer dependencies
        run: composer install --no-interaction --no-progress --prefer-dist

      - name: Setup Node
        uses: actions/setup-node@v4
        with:
          node-version: '20'

      - name: Install NPM dependencies
        run: npm ci

      - name: Build frontend assets
        run: npm run build

      - name: Generate app key
        run: php artisan key:generate

      - name: Wait for MySQL
        run: |
          until mysqladmin ping -h127.0.0.1 -P3306 -uroot -proot --silent; do
            sleep 2
          done

      - name: Run migrations against MySQL
        run: php artisan migrate --force
        env:
          DB_CONNECTION: mysql
          DB_HOST: 127.0.0.1
          DB_PORT: 3306
          DB_DATABASE: tami_test
          DB_USERNAME: root
          DB_PASSWORD: root

      - name: Run test suite against MySQL
        run: vendor/bin/phpunit -c phpunit.ci.xml
```

- [ ] **Step 3: Verify the job runs**

```bash
git add phpunit.ci.xml .github/workflows/deploy.yml
git commit -m "Add CI job testing against real MySQL"
git push origin main
gh run watch
```

Expected: the `test` job completes with a green checkmark (this is the automated proof that migrations and app config work against real MySQL, not just SQLite).

**Why the npm/build steps are required here (not just in Task 7's deploy job):** several Feature tests render Blade views that use the `@vite()` directive (the Breeze/Livewire auth pages' guest/app layouts). Without a built `public/build/manifest.json`, those views throw `Illuminate\Foundation\ViteManifestNotFoundException` and the tests fail with a 500 response — this isn't a MySQL-specific problem, it would fail identically against SQLite in a fresh checkout with no prior local `npm run build`. Confirmed by an actual failed CI run before this fix was added (`gh run view` showed exactly this exception across multiple auth-page tests).

---

### Task 6 (Manual — requires your action): Confirm production MySQL exists and switch the droplet's `.env`

**Files:**
- Modify: droplet's `.env` (not in git)

- [ ] **Step 1: Check for an existing database and user**

SSH into the droplet and run:

```bash
mysql -u root -p -e "SHOW DATABASES;"
```

Look for a database that looks like it belongs to this app (e.g. `tami_web_app`). If none exists:

```bash
mysql -u root -p -e "CREATE DATABASE tami_web_app CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER 'tami_app'@'localhost' IDENTIFIED BY '<a strong generated password>'; GRANT ALL PRIVILEGES ON tami_web_app.* TO 'tami_app'@'localhost'; FLUSH PRIVILEGES;"
```

- [ ] **Step 2: Update the droplet's `.env`**

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=tami_web_app
DB_USERNAME=tami_app
DB_PASSWORD=<the password from Step 1>
```

---

### Task 7: Add the GitHub Actions deploy job

**Files:**
- Modify: `.github/workflows/deploy.yml` (add the `deploy` job, depending on `test`)

**Interfaces:**
- Consumes: the `test` job from Task 5 via `needs: test`.
- Consumes: GitHub Actions secrets created in Task 8 (`DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_SSH_KEY`, `DEPLOY_PATH`).

- [ ] **Step 1: Add the `deploy` job**

Append to `.github/workflows/deploy.yml` (same file as Task 5), after the `test` job:

```yaml
  deploy:
    needs: test
    runs-on: ubuntu-latest
    if: github.ref == 'refs/heads/main'

    steps:
      - name: Deploy over SSH
        uses: appleboy/ssh-action@v1.2.5
        with:
          host: ${{ secrets.DEPLOY_HOST }}
          username: ${{ secrets.DEPLOY_USER }}
          key: ${{ secrets.DEPLOY_SSH_KEY }}
          script: |
            cd "${{ secrets.DEPLOY_PATH }}"
            git pull origin main
            composer install --no-dev --optimize-autoloader --no-interaction
            php artisan migrate --force
            npm ci
            npm run build
            php artisan config:clear
            php artisan config:cache
            php artisan route:cache
            php artisan view:cache
```

- [ ] **Step 2: Commit**

```bash
git add .github/workflows/deploy.yml
git commit -m "Add GitHub Actions deploy job"
```

(Do not push yet — Task 8 must create the secrets this job depends on first, or the workflow will fail on the next push.)

---

### Task 8 (Manual — requires your action): Create the deploy key and GitHub secrets

**Files:**
- None (GitHub repo settings + droplet SSH config)

- [ ] **Step 1: Generate a dedicated deploy key pair**

On your own machine (not the droplet):

```bash
ssh-keygen -t ed25519 -f ./tami-deploy-key -C "tami-web-app-deploy" -N ""
```

This creates `tami-deploy-key` (private) and `tami-deploy-key.pub` (public).

- [ ] **Step 2: Add the public key to the droplet**

SSH into the droplet using your existing access, then append the contents of `tami-deploy-key.pub` to `~/.ssh/authorized_keys` for the user that should own the deploy (the same user with permission to write to the app's folder and run `composer`/`npm`/`artisan`).

- [ ] **Step 3: Make sure the app is actually cloned on the droplet**

If this is the very first deploy (the "Coming soon" placeholder is still live), clone the repo into place manually once over SSH:

```bash
git clone git@github.com:GjorgjiMihailov/tami-web-app.git /path/to/deploy/tami-web-app
cd /path/to/deploy/tami-web-app
cp .env.example .env
# then edit .env with the real production values from Tasks 2 and 6
php artisan key:generate
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
```

Update the Apache vhost's document root to point at `/path/to/deploy/tami-web-app/public`, then reload Apache (`sudo systemctl reload apache2`).

- [ ] **Step 4: Add the GitHub Actions secrets**

In the GitHub repo → Settings → Secrets and variables → Actions → New repository secret, add:

- `DEPLOY_HOST` — the droplet's IP or hostname
- `DEPLOY_USER` — the SSH user from Step 2
- `DEPLOY_SSH_KEY` — the full contents of the **private** key file `tami-deploy-key`
- `DEPLOY_PATH` — the absolute path from Step 3 (e.g. `/path/to/deploy/tami-web-app`)

Delete the local `tami-deploy-key` / `tami-deploy-key.pub` files once added (or keep the private key only somewhere secure — never commit either to git).

**Windows/Git Bash gotcha, discovered live:** if setting secrets via `gh secret set` from Git Bash on Windows, any value that looks like an absolute Unix path (starts with `/`, e.g. `DEPLOY_PATH`) gets silently mangled by MSYS's automatic path conversion before it ever reaches `gh.exe` — `/var/www/portal` became `C:/Program Files/Git/var/www/portal`, which then broke `cd` on the remote server ("too many arguments", because of the space in "Program Files"). Fix: prefix the command with `MSYS_NO_PATHCONV=1` (e.g. `MSYS_NO_PATHCONV=1 gh secret set DEPLOY_PATH --repo ... --body "/var/www/portal"`). This only affects path-shaped values passed as CLI arguments to native (non-MSYS) executables — `DEPLOY_HOST`/`DEPLOY_USER` (not path-shaped) and `DEPLOY_SSH_KEY` (piped via stdin) are unaffected. The workflow's `cd "${{ secrets.DEPLOY_PATH }}"` is also quoted defensively so a future space-containing value fails loudly instead of corrupting the command.

---

### Task 9 (Manual/verification — requires your action): First live deploy

- [ ] **Step 1: Push to trigger the full pipeline**

```bash
git push origin main
gh run watch
```

- [ ] **Step 2: Confirm success**

Both the `test` and `deploy` jobs should complete green. Visit the app's domain in a browser and confirm the real Laravel app (not the "Coming soon" page) loads.

- [ ] **Step 3: Confirm the deployed commit matches**

```bash
gh api repos/GjorgjiMihailov/tami-web-app/commits/main --jq .sha
```

Compare against what's showing on the live site (e.g. check a footer/version marker if one exists, or simply confirm recently-added Phase 0 pages like the Companies list are reachable and working).
