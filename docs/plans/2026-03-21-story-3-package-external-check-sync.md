# STORY-3 Package External Check Sync Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Make the scheduled package sync command keep application-attached external checks in sync, including prune-on-removal, and surface package-managed checks on the application record in Filament.

**Architecture:** Reuse the existing project-backed application model, `CheckSyncService`, and package registry payload builders. Fix the package command so it still posts an empty external-check payload when package-managed checks were intentionally removed from code, allowing the backend prune/archive logic to run. On the Checkybot UI side, add project relation managers for package-managed websites and API monitors, including archived items, so operators can view active and pruned package-managed checks from the application record.

**Tech Stack:** Laravel 12, Filament 4, Pest, vendored `marin-solutions/checkybot-laravel` package, Eloquent soft deletes.

### Task 1: Red tests for scheduled external-check sync and prune behavior

**Files:**
- Modify: `tests/Feature/Console/CheckybotCommandTest.php`

**Step 1: Write the failing test**

Cover:
- the command sends registry-defined uptime, SSL, and API checks through the package client
- the command still calls external check sync with empty arrays when no checks remain, so backend pruning can happen

**Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Console/CheckybotCommandTest.php`
Expected: FAIL because the current command skips check sync when the payload is empty.

**Step 3: Write minimal implementation**

Implement:
- client mock-driven coverage around `checkybot:sync`
- command behavior that posts check payloads when registry/config sync is in scope, even if the counts are zero

**Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Console/CheckybotCommandTest.php`
Expected: PASS

### Task 2: Red tests for application-visible package-managed checks

**Files:**
- Modify: `tests/Feature/Filament/ProjectResourceTest.php`
- Create: `app/Filament/Resources/Projects/RelationManagers/PackageManagedWebsitesRelationManager.php`
- Create: `app/Filament/Resources/Projects/RelationManagers/PackageManagedApisRelationManager.php`
- Modify: `app/Filament/Resources/Projects/ProjectResource.php`

**Step 1: Write the failing test**

Cover:
- the application detail page shows package-managed uptime/SSL website checks and API monitors
- archived package-managed checks remain visible from the application record after pruning

**Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Filament/ProjectResourceTest.php`
Expected: FAIL because the project resource currently only exposes component relation managers.

**Step 3: Write minimal implementation**

Implement:
- relation managers backed by `packageManagedWebsites()` and `packageManagedApis()`
- soft-delete aware tables with active/archived state badges and concise status columns
- register the new relation managers on the project/application resource

**Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Filament/ProjectResourceTest.php`
Expected: PASS

### Task 3: Verification and bookkeeping

**Files:**
- Modify: `.ralph/prd.json`
- Modify: `.ralph/progress.txt`

**Step 1: Run focused verification**

Run:
- `vendor/bin/pint --dirty`
- `php artisan test tests/Feature/Console/CheckybotCommandTest.php tests/Feature/Filament/ProjectResourceTest.php`

Expected: PASS

**Step 2: Update Ralph tracking**

Implement:
- mark `STORY-3` as passing in `.ralph/prd.json`
- append implementation notes to `.ralph/progress.txt`
- add any reusable patterns discovered to `## Codebase Patterns`

**Step 3: Commit**

Run:
- `git add docs/plans/2026-03-21-story-3-package-external-check-sync.md tests/Feature/Console/CheckybotCommandTest.php tests/Feature/Filament/ProjectResourceTest.php app/Filament/Resources/Projects/ProjectResource.php app/Filament/Resources/Projects/RelationManagers/PackageManagedWebsitesRelationManager.php app/Filament/Resources/Projects/RelationManagers/PackageManagedApisRelationManager.php .ralph/prd.json .ralph/progress.txt`
- `git add -f vendor/marin-solutions/checkybot-laravel`
- `git commit -m "feat: [STORY-3] - Sync external package checks to Applications from the scheduled command"`

Expected: Commit created after focused verification succeeds.
