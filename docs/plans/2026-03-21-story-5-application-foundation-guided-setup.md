# STORY-5 Application Foundation Guided Setup Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Let operators create an application shell with only name and environment, then show a copy-paste Laravel package setup snippet that includes the hidden application pairing id.

**Architecture:** Finish the existing `Project` Filament resource instead of introducing a parallel flow. Keep creation minimal in the resource form and create page, then generate the install snippet from the viewed `Project` record so the pairing data stays derived from the saved application.

**Tech Stack:** Laravel 12, Filament 4, Pest, existing `Project` model/resource

### Task 1: Write the failing user-flow test

**Files:**
- Create: `tests/Feature/Filament/ProjectGuidedSetupTest.php`

**Step 1: Write the failing test**

Add a Filament test that:
- authenticates a super admin with `Project` permissions
- creates an application through `CreateProject`
- asserts only `name` and `environment` are required
- visits `ViewProject` and asserts the generated setup snippet contains the package install commands plus `CHECKYBOT_APP_ID=<record id>`

**Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/Filament/ProjectGuidedSetupTest.php`

Expected: failure because the form is still empty and no guided setup snippet is rendered.

### Task 2: Implement the minimal application flow

**Files:**
- Modify: `app/Filament/Resources/Projects/Schemas/ProjectForm.php`
- Modify: `app/Filament/Resources/Projects/Schemas/ProjectInfolist.php`
- Modify: `app/Filament/Resources/Projects/Pages/CreateProject.php`
- Modify: `app/Models/Project.php`

**Step 1: Implement the minimal code**

Add the required form fields, persist `created_by` and a generated token on create, and expose a record-derived guided setup snippet in the application infolist.

**Step 2: Run the story test**

Run: `php artisan test tests/Feature/Filament/ProjectGuidedSetupTest.php`

Expected: the story flow passes.

### Task 3: Verify, record, and commit

**Files:**
- Modify: `.ralph/prd.json`
- Modify: `.ralph/progress.txt`

**Step 1: Run final verification**

Run: `vendor/bin/pint --dirty`

Run: `php artisan test tests/Feature/Filament/ProjectGuidedSetupTest.php`

Expected: both commands pass.

**Step 2: Record Ralph updates and commit**

Set `STORY-5` to passing, append implementation notes and reusable patterns if found, then commit with:

```bash
git add .
git commit -m "feat: STORY-5 - Applications foundation and guided setup snippet"
```
