# STORY-2 Package Health Components Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add package-defined health components that evaluate locally, report due heartbeats to Checkybot, and surface overall application status with worst-component-wins rollup.

**Architecture:** Extend the vendored `marin-solutions/checkybot-laravel` registry with a health-component DSL and command-side due evaluation that posts component heartbeats to the existing Checkybot component sync endpoint. On the app side, reuse existing component ingest/history and add a computed project status accessor plus Filament visibility for current application status.

**Tech Stack:** PHP 8.4, Laravel 12, Filament 4, Pest, vendored `marin-solutions/checkybot-laravel` package.

### Task 1: Package DSL and command coverage

**Files:**
- Create: `tests/Feature/Console/CheckybotCommandTest.php`
- Modify: `vendor/marin-solutions/checkybot-laravel/src/CheckRegistry.php`
- Modify: `vendor/marin-solutions/checkybot-laravel/src/Facades/Checkybot.php`
- Modify: `vendor/marin-solutions/checkybot-laravel/src/Commands/CheckybotCommand.php`
- Modify: `vendor/marin-solutions/checkybot-laravel/src/Http/CheckybotClient.php`
- Create: `vendor/marin-solutions/checkybot-laravel/src/Components/HealthComponent.php`
- Create: `vendor/marin-solutions/checkybot-laravel/src/Support/Interval.php`

**Step 1: Write the failing test**

```php
it('sends only due component heartbeats with raw metrics and computed status', function () {
    // define a numeric and boolean component in the Checkybot registry
    // bind a fake client
    // freeze time and assert only due components are posted
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Console/CheckybotCommandTest.php`
Expected: FAIL because health components and client support do not exist yet.

**Step 3: Write minimal implementation**

Add a fluent component builder, registry accessors, command-side due filtering via cache-backed timestamps, and client posting to `/api/v1/projects/{project}/components/sync`.

**Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Console/CheckybotCommandTest.php`
Expected: PASS

### Task 2: Application status UI coverage

**Files:**
- Modify: `tests/Feature/Filament/ProjectResourceTest.php`
- Modify: `app/Models/Project.php`
- Modify: `app/Filament/Resources/Projects/Tables/ProjectsTable.php`
- Modify: `app/Filament/Resources/Projects/Schemas/ProjectInfolist.php`

**Step 1: Write the failing test**

```php
test('application list and detail show worst active component status', function () {
    // create healthy and danger components and assert the project UI shows danger
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Filament/ProjectResourceTest.php`
Expected: FAIL because project status is not displayed yet.

**Step 3: Write minimal implementation**

Compute a project-level rollup from non-archived components and render it in the application list/detail views.

**Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Filament/ProjectResourceTest.php`
Expected: PASS

### Task 3: Verification and delivery

**Files:**
- Modify: `.ralph/prd.json`
- Modify: `.ralph/progress.txt`

**Step 1: Run focused verification**

Run: `php artisan test tests/Feature/Console/CheckybotCommandTest.php tests/Feature/Filament/ProjectResourceTest.php`
Expected: PASS

**Step 2: Run style fixer**

Run: `vendor/bin/pint --dirty`
Expected: No remaining style issues

**Step 3: Re-run focused verification**

Run: `php artisan test tests/Feature/Console/CheckybotCommandTest.php tests/Feature/Filament/ProjectResourceTest.php`
Expected: PASS

**Step 4: Update tracking docs and commit**

```bash
git add docs/plans/2026-03-21-story-2-package-health-components.md tests/Feature/Console/CheckybotCommandTest.php tests/Feature/Filament/ProjectResourceTest.php app/Models/Project.php app/Filament/Resources/Projects/Tables/ProjectsTable.php app/Filament/Resources/Projects/Schemas/ProjectInfolist.php .ralph/prd.json .ralph/progress.txt
git add -f vendor/marin-solutions/checkybot-laravel
git commit -m "feat: [STORY-2] - Add package health components, heartbeats, and current application status"
```
