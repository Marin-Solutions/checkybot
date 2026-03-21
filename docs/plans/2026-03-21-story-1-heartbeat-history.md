# Story 1 Heartbeat History Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add package-managed component heartbeat history, stale detection, notifications, and archived visibility for project-backed applications.

**Architecture:** Keep the existing `Project` model as the package target for now and introduce a new `ProjectComponent` domain beneath it. Components store current state and archive metadata; `ProjectComponentHeartbeat` stores the time-series history. A sync endpoint records current heartbeats and archives pruned components, while a stale-detection command converts overdue components into stale danger events and routes notifications through the existing `NotificationSetting` infrastructure.

**Tech Stack:** Laravel 12, Filament 4, Pest, Eloquent, Sanctum, queued jobs/commands, existing notification channel models

### Task 1: Data model and API contract

**Files:**
- Create: `database/migrations/*_create_project_components_table.php`
- Create: `database/migrations/*_create_project_component_heartbeats_table.php`
- Create: `app/Models/ProjectComponent.php`
- Create: `app/Models/ProjectComponentHeartbeat.php`
- Modify: `app/Models/Project.php`
- Modify: `app/Enums/WebsiteServicesEnum.php`
- Create: `app/Http/Requests/SyncProjectComponentsRequest.php`
- Create: `app/Http/Controllers/Api/V1/ProjectComponentsController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/V1/ProjectComponentSyncTest.php`

**Step 1: Write the failing test**

Cover:
- syncing components creates current component rows and heartbeat history rows
- syncing a second payload appends history instead of mutating prior records
- omitting a previously package-managed component archives it instead of deleting it

**Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Api/V1/ProjectComponentSyncTest.php`
Expected: FAIL because the models, tables, route, and controller do not exist

**Step 3: Write minimal implementation**

Implement:
- `ProjectComponent` with `project()` and `heartbeats()` relationships
- `ProjectComponentHeartbeat` with `component()` relationship
- `Project` relationships for active/all components
- authenticated sync request + controller
- service-friendly sync logic that upserts current component state, appends heartbeat records, and archives missing components

**Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Api/V1/ProjectComponentSyncTest.php`
Expected: PASS

### Task 2: Stale detection and notifications

**Files:**
- Create: `app/Services/ProjectComponentSyncService.php`
- Create: `app/Services/ProjectComponentStaleService.php`
- Create: `app/Services/ProjectComponentNotificationService.php`
- Create: `app/Console/Commands/CheckProjectComponentStaleStatus.php`
- Create: `app/Mail/ProjectComponentAlertMail.php`
- Modify: `app/Console/Kernel.php`
- Test: `tests/Feature/Api/V1/ProjectComponentSyncTest.php`
- Test: `tests/Unit/Commands/CheckProjectComponentStaleStatusTest.php`

**Step 1: Write the failing test**

Cover:
- warning and danger heartbeat events trigger existing notification settings
- overdue components are marked stale and escalated to danger
- stale detection creates a history row only once until a fresh heartbeat arrives

**Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Commands/CheckProjectComponentStaleStatusTest.php`
Expected: FAIL because the command and services do not exist

**Step 3: Write minimal implementation**

Implement:
- notification delivery through `NotificationSetting` global rules using a new inspection enum value
- stale threshold based on the declared component interval
- command that scans active components and records stale danger events

**Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Api/V1/ProjectComponentSyncTest.php tests/Unit/Commands/CheckProjectComponentStaleStatusTest.php`
Expected: PASS

### Task 3: Filament visibility for active, archived, and history views

**Files:**
- Create: `app/Filament/Resources/ProjectResource.php`
- Create: `app/Filament/Resources/ProjectResource/Pages/ListProjects.php`
- Create: `app/Filament/Resources/ProjectResource/Pages/ViewProject.php`
- Create: `app/Filament/Resources/ProjectResource/RelationManagers/ComponentsRelationManager.php`
- Create: `app/Filament/Resources/ProjectComponentResource.php`
- Create: `app/Filament/Resources/ProjectComponentResource/Pages/ViewProjectComponent.php`
- Create: `app/Filament/Resources/ProjectComponentResource/RelationManagers/HeartbeatsRelationManager.php`
- Test: `tests/Feature/Filament/ProjectResourceTest.php`

**Step 1: Write the failing test**

Cover:
- authenticated users can see project/application components in the project view
- archived components are visible in the table
- component detail pages show heartbeat history entries

**Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Filament/ProjectResourceTest.php`
Expected: FAIL because the Filament resources and relation managers do not exist

**Step 3: Write minimal implementation**

Implement:
- a project resource labeled for application usage
- component table with status, archived flag, interval, and last heartbeat
- component detail/history table with latest-first heartbeats

**Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Filament/ProjectResourceTest.php`
Expected: PASS

### Task 4: Final verification and bookkeeping

**Files:**
- Modify: `.ralph/prd.json`
- Modify: `.ralph/progress.txt`

**Step 1: Run focused verification**

Run:
- `vendor/bin/pint --dirty`
- `php artisan test tests/Feature/Api/V1/ProjectComponentSyncTest.php tests/Unit/Commands/CheckProjectComponentStaleStatusTest.php tests/Feature/Filament/ProjectResourceTest.php`

Expected: All green

**Step 2: Update Ralph tracking**

Implement:
- mark `STORY-1` as passing in `.ralph/prd.json`
- append implementation notes to `.ralph/progress.txt`
- add any reusable repo-level patterns to the top `## Codebase Patterns` section

**Step 3: Commit**

Run:
- `git add ...`
- `git commit -m "feat: [Story ID] - [Story Title]"`

Expected: Commit created only after tests and pint pass
