# STORY-4 Package Auth Registration Handshake Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Move package traffic onto Bearer API-key auth and add an application-registration handshake that can attach to a guided setup shell or auto-create an application by identity.

**Architecture:** Keep `Project` as the current application record, add package registration metadata to it, expose a dedicated authenticated registration endpoint, and make the vendored package resolve its target project through that endpoint before syncing checks or components. Reuse the existing sync services and request authorization rules by authenticating the API key owner as the current user.

**Tech Stack:** Laravel 12, Filament 4, Pest, vendored `marin-solutions/checkybot-laravel` package

### Task 1: Write the failing backend registration tests

**Files:**
- Create: `tests/Feature/Api/V1/ProjectRegistrationTest.php`
- Modify: `tests/Feature/Api/V1/ProjectChecksSyncTest.php`
- Modify: `tests/Feature/Api/V1/ProjectComponentSyncTest.php`

**Step 1: Write the failing tests**

Add coverage for:
- Bearer API-key auth on package sync routes
- Guided setup attachment via hidden `app_id`
- Identity-based reuse or creation when no shell is supplied

**Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/Api/V1/ProjectRegistrationTest.php tests/Feature/Api/V1/ProjectChecksSyncTest.php tests/Feature/Api/V1/ProjectComponentSyncTest.php`

Expected: failures for missing registration endpoint, missing project identity fields, and current auth mismatch.

### Task 2: Implement backend registration and auth

**Files:**
- Create: `app/Http/Controllers/Api/V1/ProjectRegistrationsController.php`
- Create: `app/Http/Requests/RegisterProjectRequest.php`
- Create: `app/Services/ProjectRegistrationService.php`
- Create: `database/migrations/*_add_identity_endpoint_to_projects_table.php`
- Modify: `app/Http/Middleware/ApiKeyAuthentication.php`
- Modify: `app/Models/Project.php`
- Modify: `bootstrap/app.php`
- Modify: `routes/api.php`

**Step 1: Implement the minimal backend**

Add the new registration endpoint, switch package routes to API-key middleware, and store `identity_endpoint` on projects.

**Step 2: Run backend tests**

Run: `php artisan test tests/Feature/Api/V1/ProjectRegistrationTest.php tests/Feature/Api/V1/ProjectChecksSyncTest.php tests/Feature/Api/V1/ProjectComponentSyncTest.php`

Expected: passing tests for the registration and auth flows.

### Task 3: Write the failing package-command tests

**Files:**
- Modify: `tests/Feature/Console/CheckybotCommandTest.php`

**Step 1: Write the failing tests**

Add coverage that the command registers first, uses the returned project id for sync, attaches to a guided shell, and auto-creates when no matching identity exists.

**Step 2: Run the command tests to verify they fail**

Run: `php artisan test tests/Feature/Console/CheckybotCommandTest.php`

Expected: failures because the current client/command still depend on static `project_id`.

### Task 4: Implement the package handshake

**Files:**
- Modify: `vendor/marin-solutions/checkybot-laravel/config/checkybot-laravel.php`
- Modify: `vendor/marin-solutions/checkybot-laravel/src/CheckybotLaravelServiceProvider.php`
- Modify: `vendor/marin-solutions/checkybot-laravel/src/Commands/CheckybotCommand.php`
- Modify: `vendor/marin-solutions/checkybot-laravel/src/ConfigValidator.php`
- Modify: `vendor/marin-solutions/checkybot-laravel/src/Http/CheckybotClient.php`

**Step 1: Implement the minimal package changes**

Resolve the target project through the new registration endpoint, then sync checks and components with the returned project id.

**Step 2: Run the command tests**

Run: `php artisan test tests/Feature/Console/CheckybotCommandTest.php`

Expected: passing tests for the registration handshake and sync orchestration.

### Task 5: Verify, record, and commit

**Files:**
- Modify: `.ralph/prd.json`
- Modify: `.ralph/progress.txt`

**Step 1: Run final verification**

Run: `vendor/bin/pint --dirty`

Run: `php artisan test tests/Feature/Api/V1/ProjectRegistrationTest.php tests/Feature/Api/V1/ProjectChecksSyncTest.php tests/Feature/Api/V1/ProjectComponentSyncTest.php tests/Feature/Console/CheckybotCommandTest.php`

Expected: all selected checks pass.

**Step 2: Record Ralph updates and commit**

Append STORY-4 learnings to the Ralph progress log, flip `passes` to `true`, and commit with:

```bash
git add .
git commit -m "feat: [Story ID] - [Story Title]"
```
