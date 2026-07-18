# Phase 0b: Storage, Backup, Database & Deploy — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wire up Google Drive file storage (via a shared personal-Drive folder), nightly MySQL backups to that Drive folder, production MySQL configuration, and GitHub Actions auto-deploy to the droplet — completing the Phase 0 foundation.

**Architecture:** A custom Laravel filesystem disk (`google`) wraps a Flysystem Google Drive adapter pointed at a folder shared with a service account. `spatie/laravel-backup` dumps MySQL nightly and uploads through that same disk, pruning anything older than 30 days. A GitHub Actions workflow runs the test suite against a real MySQL service container (proving migrations/config work on MySQL) and then, only on `main` and only if tests pass, SSHes into the droplet to pull and deploy in place.

**Tech Stack:** Laravel 13.20 / PHP 8.3, `masbug/flysystem-google-drive-ext` ^2.5, `spatie/laravel-backup` ^10.3, GitHub Actions (`actions/checkout@v7`, `shivammathur/setup-php@v2`, `appleboy/ssh-action@v1.2.5`).

## Global Constraints

- PHP ^8.3, Laravel ^13.8 (installed: v13.20.0) — all new packages must be compatible with these floors.
- Local development keeps `DB_CONNECTION=sqlite`; only production `.env` switches to `mysql`. Never change `phpunit.xml`'s default (sqlite, in-memory) test config.
- Google Drive storage uses a **personal Drive folder shared with the service account** — not a Shared Drive (Shared Drives require Google Workspace, which is not in use). Never reference "Shared Drive" in code, comments, or docs for this project.
- Backup retention is a rolling **30 days** — nothing older is kept.
- Deploy is a **simple in-place deploy** (git pull directly into the live folder) — no release-swap/symlink zero-downtime tooling.
- Credentials (service account key, Drive folder ID, deploy SSH key, MySQL password) live only in the droplet's `.env` or GitHub Actions secrets — never committed to git.

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
- Consumes: `config('filesystems.disks.google')` with keys `client_email`, `private_key`, `folder_id` (populated from env in production).

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
                'client_id' => '123456789fake',
                'client_email' => 'fake@example.iam.gserviceaccount.com',
                'private_key' => $this->fakePrivateKey(),
                'folder_id' => 'fake-folder-id',
            ],
        ]);

        $disk = Storage::disk('google');

        $this->assertInstanceOf(FilesystemAdapter::class, $disk);
    }

    private function fakePrivateKey(): string
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        openssl_pkey_export($resource, $pem);

        return $pem;
    }
}
```

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
            'client_email' => env('GOOGLE_DRIVE_CLIENT_EMAIL'),
            'private_key' => env('GOOGLE_DRIVE_PRIVATE_KEY'),
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
            $client->setAuthConfig([
                'type' => 'service_account',
                'client_id' => $config['client_id'],
                'client_email' => $config['client_email'],
                'private_key' => str_replace('\\n', "\n", (string) $config['private_key']),
            ]);
            $client->addScope(Drive::DRIVE);

            $adapter = new GoogleDriveAdapter(new Drive($client), null, [
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
GOOGLE_DRIVE_CLIENT_EMAIL=
GOOGLE_DRIVE_PRIVATE_KEY=
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

### Task 2 (Manual — requires your action): Create the service account and share a Drive folder

This task can't be done by an agent — it requires clicking through Google's and your own Google Drive's UI, and putting real secrets on the server. Do this with the user directly, walking through each step and waiting for confirmation before moving on.

**Files:**
- Modify: droplet's `.env` (not in git — edited directly on the server over SSH)

- [ ] **Step 1: Create a Google Cloud project**

Go to https://console.cloud.google.com/projectcreate, create a new project (e.g. "tami-web-app"). Note the project ID.

- [ ] **Step 2: Enable the Google Drive API**

In the new project, go to "APIs & Services" → "Library", search "Google Drive API", click Enable.

- [ ] **Step 3: Create a service account and key**

"APIs & Services" → "Credentials" → "Create Credentials" → "Service Account". Name it (e.g. "tami-drive-service"). After creation, open it → "Keys" tab → "Add Key" → "Create new key" → JSON. This downloads a `.json` file — treat it as a password; never commit it to git.

- [ ] **Step 4: Share a personal Drive folder with the service account**

In the user's own Google Drive (drive.google.com), create a new folder (e.g. "Tami App Storage"). Right-click → Share → paste the service account's email address (the `client_email` field from the downloaded JSON, looks like `tami-drive-service@<project-id>.iam.gserviceaccount.com`) → give it **Editor** access → Send (no notification email needed).

- [ ] **Step 5: Get the folder ID**

Open the shared folder in the browser. The URL looks like `https://drive.google.com/drive/folders/<FOLDER_ID>`. Copy `<FOLDER_ID>`.

- [ ] **Step 6: Set the droplet's `.env` values**

SSH into the droplet and edit the app's `.env` file, setting:

```
GOOGLE_DRIVE_CLIENT_ID=<client_id from the JSON key>
GOOGLE_DRIVE_CLIENT_EMAIL=<client_email from the JSON key>
GOOGLE_DRIVE_PRIVATE_KEY="<private_key from the JSON key, with real newlines replaced by literal \n>"
GOOGLE_DRIVE_FOLDER_ID=<folder ID from Step 5>
```

- [ ] **Step 7: Manually verify the disk works**

On the droplet, run:

```bash
php artisan tinker --execute="Storage::disk('google')->put('smoke-test.txt', 'hello from tami-web-app'); echo Storage::disk('google')->exists('smoke-test.txt') ? 'OK' : 'FAILED';"
```

Confirm it prints `OK`, then confirm the file `smoke-test.txt` actually appears in the shared Drive folder in the browser. Delete the test file afterward (`php artisan tinker --execute="Storage::disk('google')->delete('smoke-test.txt');"`).

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

        'name' => env('APP_NAME', 'tami-web-app'),

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

- [ ] **Step 2: Manually trigger a real backup once**

On the droplet:

```bash
php artisan backup:run --only-db
```

- [ ] **Step 3: Confirm it landed in Drive**

Check the shared Drive folder in the browser (or `php artisan tinker --execute="dd(Storage::disk('google')->allFiles());"`) for a new `.zip` backup file.

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
            cd ${{ secrets.DEPLOY_PATH }}
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
