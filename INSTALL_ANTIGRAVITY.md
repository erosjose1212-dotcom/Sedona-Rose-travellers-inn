# Install Sedona Rose Travellers Inn — Antigravity handoff

Prepared: 19 October 2026. These instructions apply to the separate **Sedona Rose** copy.

## Property and scope

| Setting | Required value |
| --- | --- |
| Project folder | `C:\xampp\htdocs\sedona-rose-travellers-inn` |
| Hotel name | SEDONA ROSE TRAVELLERS INN |
| Address | B.S. Aquino Avenue, St, San Roque Hinukay, Baliwag, Bulacan |
| Rooms 1–8 | Classic Room |
| Rooms 9–20 | Premium Room |
| Room 12 | Premium guest room; `is_staff_quarters = false` |
| Database | This folder's `database\database.sqlite` |
| Local URL | `http://127.0.0.1:8017` |

Keep the existing legacy checkout UI. The earlier checkout redesign and inline-layout previews were **not approved or applied**.

This copy contains the complete **local** Court database available during creation: 12 accounts, 71 menu items, and 3 stays, together with its other records. It is not a download of the hotel's live website database. Existing account passwords, stay references, historical charges, and stock counts were preserved. Database upgrades apply the current tariff, including ₱100 bed sheets and the free hygiene kit.

An untouched database snapshot is saved as `database\sedona-court-original-copy.sqlite`. Leave that file as a backup. The operational database is `database\database.sqlite`.

Room 12's copied active staff stay is retained as historical data, but the **room itself is now Premium**. Rooms with retained active stays are occupied until properly checked out. Existing stay charges are not repriced merely because a room's category changed.

## 1. Open and identify the correct project

Open the Rose folder in Antigravity. Read its `AGENTS.md` before editing code. Run all commands below in **PowerShell**.

```powershell
Set-Location -LiteralPath 'C:\xampp\htdocs\sedona-rose-travellers-inn'
Get-Location
Test-Path -LiteralPath '.\artisan'
Test-Path -LiteralPath '.\database\database.sqlite'
git remote -v
```

Both file checks must return `True`. This project has a separate local Git history and no Court remote configured. Keep it separate from `C:\xampp\htdocs\sedona`, `C:\xampp\htdocs\sedona-v3`, and the Court maintenance checkout. Create a Rose-specific remote only if the user requests one.

If moving the system to another computer, copy the **whole Rose folder**, including hidden files, `.env`, `database`, and `storage`. A Git clone alone excludes the database, `.env`, dependencies, and generated assets.

## 2. Check the installed runtimes

```powershell
php --version
php -m
composer --version
node --version
npm.cmd --version
```

The verified copy uses PHP 8.3.33, Laravel 13.34.0, and the dependency versions in `composer.lock` and `package-lock.json`. PHP needs SQLite/PDO SQLite, mbstring, OpenSSL, XML/DOM, fileinfo, ZIP, and the extensions required by Composer. Use PHP 8.3 or a compatible version allowed by the lock file. Keep dependency versions locked.

Install missing runtimes from their official distributors. This copy already includes `vendor`, `node_modules`, and built frontend assets.

## 3. Verify `.env` before any database command

Preserve the existing `.env` and `APP_KEY` when moving this configured copy. Edit the database path if the destination folder changes. Confirm these values without displaying secrets:

```dotenv
APP_NAME="Sedona Rose Travellers Inn"
APP_URL=http://127.0.0.1:8017
APP_TIMEZONE=Asia/Manila
DB_CONNECTION=sqlite
DB_DATABASE=C:/xampp/htdocs/sedona-rose-travellers-inn/database/database.sqlite
SEDONA_HOTEL_NAME="SEDONA ROSE TRAVELLERS INN"
SEDONA_HOTEL_ADDRESS="B.S. Aquino Avenue, St, San Roque Hinukay, Baliwag, Bulacan"
SEDONA_PROPERTY=rose
SESSION_COOKIE=sedona_rose_session
CACHE_PREFIX=sedona_rose_
SEDONA_CONTINUOUS_SHIFT_ENABLED=true
```

Set `APP_DEBUG=false` before providing staff or network access. Keep sessions, cache, queues, storage, and the database within the Rose installation. Do not point Rose at Court's database or storage. Never display or commit the populated `.env`, database, backups, or passwords.

Only if `.env` is genuinely missing, copy `.env.example`, set the values above, and generate a new key:

```powershell
Copy-Item -LiteralPath '.\.env.example' -Destination '.\.env'
php artisan key:generate --no-interaction
```

Do not replace an existing configured `.env` with this fallback.

## 4. Restore dependencies if needed

When `vendor` and `node_modules` are present and working, skip installation. When missing or damaged, restore the locked dependencies:

```powershell
composer install --prefer-dist --no-interaction
if ($LASTEXITCODE -ne 0) { throw 'Composer installation failed.' }
npm.cmd ci --ignore-scripts
if ($LASTEXITCODE -ne 0) { throw 'Node dependency installation failed.' }
npm.cmd run build
if ($LASTEXITCODE -ne 0) { throw 'Frontend build failed.' }
```

The original dependency snapshot had missing framework/error-renderer assets. These were restored in the Rose copy. If file generation or exception pages report missing package files, restore their **locked** packages rather than editing `vendor`:

```powershell
composer reinstall laravel/framework symfony/error-handler --prefer-dist --no-interaction --no-scripts
if ($LASTEXITCODE -ne 0) { throw 'Dependency restoration failed.' }
```

## 5. Back up, migrate, and verify the copied database

First confirm the server is stopped and that `.env` points to the Rose database. Take a new backup in the Rose database folder:

```powershell
$roseBackupName = '.\database\before-install-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.sqlite'
Copy-Item -LiteralPath '.\database\database.sqlite' -Destination $roseBackupName
php artisan optimize:clear --no-interaction
if ($LASTEXITCODE -ne 0) { throw 'Cache clearing failed.' }
php artisan migrate --force --no-interaction
if ($LASTEXITCODE -ne 0) { throw 'Database migration failed.' }
php artisan migrate:status --no-interaction
php artisan config:show sedona.hotel_name
php artisan config:show sedona.property_code
```

Expected configuration: `SEDONA ROSE TRAVELLERS INN` and `rose`. All migrations should show `Ran`.

**Preserve the copied data.** Installation does not require `migrate:fresh`, `db:wipe`, transaction resets, stock resets, user reseeding, or deleting the SQLite file. These operations would discard or alter the data the user explicitly requested to keep.

The twenty-room configuration is already applied. Only if it needs to be reapplied after a verified restore, use:

```powershell
php artisan db:seed --class=SedonaRoseRoomSeeder --force --no-interaction
```

This seeder configures 1–8 Classic and 9–20 Premium, preserves existing stay records, and refuses to delete out-of-range rooms with stays, bookings, or orders. If it refuses, stop and review those references; preserve the history.

## 6. Check storage and start the application

```powershell
php artisan storage:link --no-interaction
php artisan serve --host=127.0.0.1 --port=8017 --no-interaction
```

The storage link must resolve to **this** installation's `storage\app\public`. If it already exists, verify its target. If it points elsewhere, repair the link without deleting actual stored files. On Windows, creating a link may require Developer Mode or an elevated terminal.

Keep the server terminal running, then open:

- Login: `http://127.0.0.1:8017/login`
- Lobby display: `http://127.0.0.1:8017/lobby-kiosk`

If port 8017 is occupied, check whether the existing Rose server is already running before starting another. For a different port, update `APP_URL` and the serve command together, then clear configuration.

Use the copied account credentials. No new default password was assigned. If the user needs a password reset, use the authorized account-management flow; do not expose or replace everyone's passwords.

## 7. Validate before handoff

Check the actual application:

1. Login and sidebar say **SEDONA ROSE**.
2. Lobby and room lists contain exactly 20 rooms: eight Classic and twelve Premium.
3. Room 12 is a Premium guest room, not staff quarters.
4. The lobby and printed documents show the Baliwag address above.
5. Copied users, menu, stocks, stays, and transaction history remain present.
6. Existing receipts and stay references retain their numbers; historical stays keep their recorded charges.
7. The original Court installation remains unchanged.

Run the tests against their isolated in-memory database:

```powershell
php vendor/bin/phpunit tests/Feature/SedonaRosePropertyTest.php
if ($LASTEXITCODE -ne 0) { throw 'Rose property tests failed.' }
php vendor/bin/phpunit
if ($LASTEXITCODE -ne 0) { throw 'Regression tests failed.' }
node --test tests/js/*.test.js
if ($LASTEXITCODE -ne 0) { throw 'JavaScript tests failed.' }
npm.cmd run build
if ($LASTEXITCODE -ne 0) { throw 'Frontend build failed.' }
```

The inherited regression suite explicitly uses Court's original 32-room **test fixtures** through `phpunit.xml`. That is intentional coverage of shared workflows; it does not configure the operational Rose database. `SedonaRosePropertyTest` switches to Rose and verifies the actual 20-room configuration, room 12 check-in pricing, branding, history preservation, and safe reseeding.

Completion: give the user the Rose folder path, the working login URL, test results, and any remaining installation issue. Keep production deployment and any new GitHub repository creation separate until requested.
