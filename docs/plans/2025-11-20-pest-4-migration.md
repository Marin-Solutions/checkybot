# Pest 4 Migration Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Migrate all 395 PHPUnit tests to Pest 4 syntax with parallel subagent execution

**Architecture:** Upgrade dependencies first (PHPUnit 11→12, Pest 3→4), then convert tests in parallel by category (Commands, Models, Enums, Jobs, etc.), verify all tests pass

**Tech Stack:** Pest 4.1.4, PHPUnit 12, Laravel 12, PHP 8.3

---

## Task 1: Upgrade Dependencies and Configure Pest

**Files:**
- Modify: `composer.json`
- Create: `Pest.php`
- Create: `tests/Pest.php`

**Step 1: Backup current test output**

Run: `php artisan test --log-junit=test-output-before.xml`
Expected: All 395 tests pass, output saved

**Step 2: Update composer.json**

Update require-dev section:
```json
"require-dev": {
    "barryvdh/laravel-debugbar": "^3.14",
    "fakerphp/faker": "^1.23",
    "laravel/boost": "^1.0",
    "laravel/pint": "^1.13",
    "laravel/sail": "^1.26",
    "mockery/mockery": "^1.6",
    "nunomaduro/collision": "^8.0",
    "pestphp/pest": "^4.1",
    "pestphp/pest-plugin-laravel": "^4.0"
}
```

**Step 3: Run composer update**

Run: `composer update pestphp/pest pestphp/pest-plugin-laravel phpunit/phpunit --with-all-dependencies`
Expected: Pest 4.1.4 installed, PHPUnit 12.x installed

**Step 4: Create root Pest.php**

Create file: `Pest.php`
```php
<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class)->in('Feature');
uses(Tests\TestCase::class, RefreshDatabase::class)->in('Unit');
```

**Step 5: Create tests/Pest.php**

Create file: `tests/Pest.php`
```php
<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/

uses(Tests\TestCase::class)->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}
```

**Step 6: Verify Pest is working**

Run: `vendor/bin/pest --version`
Expected: "Pest 4.1.4"

**Step 7: Commit dependency upgrade**

```bash
git add composer.json composer.lock Pest.php tests/Pest.php
git commit -m "chore: upgrade to Pest 4 and configure test suite

- Upgrade Pest 3.8.4 → 4.1.4
- Upgrade PHPUnit 11 → 12
- Add Pest configuration files
- Install pest-plugin-laravel

🤖 Generated with [Claude Code](https://claude.com/claude-code)

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 2: Convert Feature/Api/V1 Tests (50 tests)

**Files to Convert:**
- `tests/Feature/Api/V1/ProjectChecksSyncTest.php`
- `tests/Feature/Api/V1/SimpleHttpTest.php`
- All other tests in `tests/Feature/Api/V1/`

**Conversion Pattern:**

```php
// BEFORE (PHPUnit)
class ProjectChecksSyncTest extends TestCase
{
    protected User $user;
    protected Project $project;
    protected CheckSyncService $syncService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->project = Project::factory()->create(['created_by' => $this->user->id]);
        $this->syncService = app(CheckSyncService::class);
    }

    public function test_syncs_uptime_checks_successfully(): void
    {
        $summary = $this->syncService->syncChecks($this->project, [
            'uptime_checks' => [
                ['name' => 'homepage-uptime', 'url' => 'https://uptime-example.com', 'interval' => '5m'],
            ],
            'ssl_checks' => [],
            'api_checks' => [],
        ]);

        $this->assertEquals([
            'created' => 1,
            'updated' => 0,
            'deleted' => 0,
        ], $summary['uptime_checks']);

        $this->assertDatabaseHas('websites', [
            'project_id' => $this->project->id,
            'name' => 'homepage-uptime',
        ]);
    }
}

// AFTER (Pest)
use App\Models\Project;
use App\Models\User;
use App\Services\CheckSyncService;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->create(['created_by' => $this->user->id]);
    $this->syncService = app(CheckSyncService::class);
});

test('syncs uptime checks successfully', function () {
    $summary = $this->syncService->syncChecks($this->project, [
        'uptime_checks' => [
            ['name' => 'homepage-uptime', 'url' => 'https://uptime-example.com', 'interval' => '5m'],
        ],
        'ssl_checks' => [],
        'api_checks' => [],
    ]);

    expect($summary['uptime_checks'])->toBe([
        'created' => 1,
        'updated' => 0,
        'deleted' => 0,
    ]);

    assertDatabaseHas('websites', [
        'project_id' => $this->project->id,
        'name' => 'homepage-uptime',
    ]);
});
```

**Assertion Conversions:**
- `$this->assertEquals($a, $b)` → `expect($a)->toBe($b)`
- `$this->assertTrue($x)` → `expect($x)->toBeTrue()`
- `$this->assertFalse($x)` → `expect($x)->toBeFalse()`
- `$this->assertNull($x)` → `expect($x)->toBeNull()`
- `$this->assertNotNull($x)` → `expect($x)->not->toBeNull()`
- `$this->assertCount($n, $x)` → `expect($x)->toHaveCount($n)`
- `$this->assertInstanceOf(Class::class, $x)` → `expect($x)->toBeInstanceOf(Class::class)`
- `$this->assertDatabaseHas()` → `assertDatabaseHas()` (unchanged)
- `$this->assertDatabaseMissing()` → `assertDatabaseMissing()` (unchanged)

**Step 1: Convert all test files in Feature/Api/V1**

For each file:
1. Remove class declaration and extends TestCase
2. Convert setUp() to beforeEach()
3. Convert tearDown() to afterEach() (if exists)
4. Convert test methods to test() functions
5. Convert assertions using mapping above
6. Add use statements for models/classes at top

**Step 2: Run converted tests**

Run: `vendor/bin/pest tests/Feature/Api/V1 --verbose`
Expected: All tests pass with same assertion counts

**Step 3: Commit conversion**

```bash
git add tests/Feature/Api/V1
git commit -m "test: convert Feature/Api/V1 tests to Pest 4

- Convert ProjectChecksSyncTest to Pest syntax
- Convert SimpleHttpTest to Pest syntax
- All tests passing

🤖 Generated with [Claude Code](https://claude.com/claude-code)

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 3: Convert Unit/Commands Tests (80 tests)

**Files to Convert:**
- `tests/Unit/Commands/CheckApiMonitorsTest.php`
- `tests/Unit/Commands/CheckServerRulesTest.php`
- `tests/Unit/Commands/LogJobCheckUptimeSslTest.php`
- All other command tests in `tests/Unit/Commands/`

**Step 1: Convert all command test files**

Apply same conversion pattern from Task 2

**Step 2: Run converted tests**

Run: `vendor/bin/pest tests/Unit/Commands --verbose`
Expected: All ~80 tests pass

**Step 3: Commit conversion**

```bash
git add tests/Unit/Commands
git commit -m "test: convert Unit/Commands tests to Pest 4

🤖 Generated with [Claude Code](https://claude.com/claude-code)

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 4: Convert Unit/Models Tests (60 tests)

**Files to Convert:**
- `tests/Unit/Models/MonitorApisTest.php`
- `tests/Unit/Models/UserTest.php`
- `tests/Unit/Models/WebsiteTest.php`
- All other model tests in `tests/Unit/Models/`

**Step 1: Convert all model test files**

Apply same conversion pattern from Task 2

**Step 2: Run converted tests**

Run: `vendor/bin/pest tests/Unit/Models --verbose`
Expected: All ~60 tests pass

**Step 3: Commit conversion**

```bash
git add tests/Unit/Models
git commit -m "test: convert Unit/Models tests to Pest 4

🤖 Generated with [Claude Code](https://claude.com/claude-code)

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 5: Convert Unit/Enums Tests (50 tests)

**Files to Convert:**
- `tests/Unit/Enums/NavigationIconTest.php`
- `tests/Unit/Enums/NotificationScopesEnumTest.php`
- All other enum tests in `tests/Unit/Enums/`

**Step 1: Convert all enum test files**

Apply same conversion pattern from Task 2

**Step 2: Run converted tests**

Run: `vendor/bin/pest tests/Unit/Enums --verbose`
Expected: All ~50 tests pass

**Step 3: Commit conversion**

```bash
git add tests/Unit/Enums
git commit -m "test: convert Unit/Enums tests to Pest 4

🤖 Generated with [Claude Code](https://claude.com/claude-code)

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 6: Convert Unit/Jobs Tests (40 tests)

**Files to Convert:**
- `tests/Unit/Jobs/CheckSslExpiryDateJobTest.php`
- `tests/Unit/Jobs/LogUptimeSslJobTest.php`
- All other job tests in `tests/Unit/Jobs/`

**Step 1: Convert all job test files**

Apply same conversion pattern from Task 2

**Step 2: Run converted tests**

Run: `vendor/bin/pest tests/Unit/Jobs --verbose`
Expected: All ~40 tests pass

**Step 3: Commit conversion**

```bash
git add tests/Unit/Jobs
git commit -m "test: convert Unit/Jobs tests to Pest 4

🤖 Generated with [Claude Code](https://claude.com/claude-code)

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 7: Convert Unit/Crawlers Tests (20 tests)

**Files to Convert:**
- `tests/Unit/Crawlers/SeoHealthCheckCrawlerTest.php`
- `tests/Unit/Crawlers/WebsiteOutboundLinkCrawlerTest.php`

**Step 1: Convert all crawler test files**

Apply same conversion pattern from Task 2

**Step 2: Run converted tests**

Run: `vendor/bin/pest tests/Unit/Crawlers --verbose`
Expected: All ~20 tests pass

**Step 3: Commit conversion**

```bash
git add tests/Unit/Crawlers
git commit -m "test: convert Unit/Crawlers tests to Pest 4

🤖 Generated with [Claude Code](https://claude.com/claude-code)

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 8: Convert Feature Tests (40 tests)

**Files to Convert:**
- All tests in `tests/Feature/` (excluding Api/V1 subdirectory)

**Step 1: Convert all feature test files**

Apply same conversion pattern from Task 2

**Step 2: Run converted tests**

Run: `vendor/bin/pest tests/Feature --exclude-path=tests/Feature/Api/V1 --verbose`
Expected: All ~40 tests pass

**Step 3: Commit conversion**

```bash
git add tests/Feature
git commit -m "test: convert Feature tests to Pest 4

🤖 Generated with [Claude Code](https://claude.com/claude-code)

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 9: Convert Unit/Services Tests (30 tests)

**Files to Convert:**
- All tests in `tests/Unit/Services/`
- `tests/Unit/IntervalParserTest.php`

**Step 1: Convert all service test files**

Apply same conversion pattern from Task 2

**Step 2: Run converted tests**

Run: `vendor/bin/pest tests/Unit/Services --verbose`
Expected: All ~30 tests pass

**Step 3: Commit conversion**

```bash
git add tests/Unit/Services tests/Unit/IntervalParserTest.php
git commit -m "test: convert Unit/Services tests to Pest 4

🤖 Generated with [Claude Code](https://claude.com/claude-code)

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 10: Convert Remaining Unit Tests (25 tests)

**Files to Convert:**
- `tests/Unit/WebsiteCreationTest.php`
- `tests/Unit/ExampleTest.php`
- Any other remaining test files in `tests/Unit/`

**Step 1: Convert all remaining test files**

Apply same conversion pattern from Task 2

**Step 2: Run converted tests**

Run: `vendor/bin/pest tests/Unit/WebsiteCreationTest.php tests/Unit/ExampleTest.php --verbose`
Expected: All remaining tests pass

**Step 3: Commit conversion**

```bash
git add tests/Unit
git commit -m "test: convert remaining Unit tests to Pest 4

🤖 Generated with [Claude Code](https://claude.com/claude-code)

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 11: Final Verification and Cleanup

**Step 1: Run full test suite with Pest**

Run: `vendor/bin/pest --verbose`
Expected: All 395 tests pass, 1264+ assertions

**Step 2: Run with parallel execution**

Run: `vendor/bin/pest --parallel`
Expected: All tests pass (faster execution)

**Step 3: Generate coverage report**

Run: `vendor/bin/pest --coverage --min=80`
Expected: Coverage report generated, meets minimum threshold

**Step 4: Run code style check**

Run: `vendor/bin/pint`
Expected: All files formatted correctly

**Step 5: Update phpunit.xml to pest.xml (optional)**

If exists, rename `phpunit.xml` to `pest.xml` and update any PHPUnit-specific configurations

**Step 6: Remove PHPUnit from composer.json (optional)**

Since Pest includes PHPUnit, consider removing explicit PHPUnit dependency:
```json
"require-dev": {
    // Remove or keep for compatibility:
    // "phpunit/phpunit": "^12.0"
}
```

**Step 7: Final commit**

```bash
git add .
git commit -m "test: complete Pest 4 migration

- All 395 tests converted from PHPUnit to Pest 4
- All tests passing with Pest syntax
- Parallel execution working
- Coverage maintained

Migration summary:
- Feature/Api/V1: 50 tests
- Unit/Commands: 80 tests
- Unit/Models: 60 tests
- Unit/Enums: 50 tests
- Unit/Jobs: 40 tests
- Unit/Crawlers: 20 tests
- Feature: 40 tests
- Unit/Services: 30 tests
- Unit/Misc: 25 tests

🤖 Generated with [Claude Code](https://claude.com/claude-code)

Co-Authored-By: Claude <noreply@anthropic.com>"
```

**Step 8: Compare before/after**

Compare test-output-before.xml with current test results to verify:
- Same number of tests
- Same or more assertions
- No regressions

---

## Success Criteria

- ✅ All 395 tests converted to Pest 4 syntax
- ✅ All tests pass: `vendor/bin/pest`
- ✅ Parallel execution works: `vendor/bin/pest --parallel`
- ✅ Coverage maintained: `vendor/bin/pest --coverage`
- ✅ Code style clean: `vendor/bin/pint`
- ✅ No PHPUnit syntax remaining in test files
- ✅ All commits follow conventional commits format

## Notes

- Tasks 2-10 can be executed in parallel by different subagents
- Each task is independent and touches different test files
- Use @superpowers:verification-before-completion before claiming any task complete
- Use @superpowers:test-driven-development patterns for any new test utilities
