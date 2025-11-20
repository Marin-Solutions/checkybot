# Pest 4 Migration Design

**Date:** 2025-11-20
**Status:** Approved
**Current State:** PHPUnit 11 with 395 tests (1264 assertions)
**Target State:** Pest 4 with all tests converted

## Overview

Migrate the entire test suite from PHPUnit to Pest 4, enabling modern testing features including browser testing, visual regression testing, and improved performance.

## Migration Strategy

### Phase 1: Upgrade Dependencies

**Upgrade Path:**
- PHPUnit 11.0.1 → 12.x (required for Pest 4)
- Pest 3.8.4 → 4.1.4
- All Pest plugins → v4 compatible versions

**New Files:**
- `Pest.php` - Global test configuration
- `tests/Pest.php` - Test suite configuration

**Configuration:**
```php
// Pest.php
uses(TestCase::class)->in('Feature');
uses(TestCase::class)->in('Unit');
```

### Phase 2: Test Conversion (Parallel Execution)

**Test Suite Organization (395 tests):**

| Category | Tests | Subagent |
|----------|-------|----------|
| Unit/Commands | ~80 | Agent 1 |
| Unit/Models | ~60 | Agent 2 |
| Unit/Enums | ~50 | Agent 3 |
| Unit/Jobs | ~40 | Agent 4 |
| Unit/Crawlers | ~20 | Agent 5 |
| Feature/Api/V1 | ~50 | Agent 6 |
| Feature | ~40 | Agent 7 |
| Unit/Services | ~30 | Agent 8 |
| Unit/Misc | ~25 | Agent 9 |

**Syntax Changes:**

```php
// BEFORE (PHPUnit)
class ProjectChecksSyncTest extends TestCase
{
    protected User $user;
    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->project = Project::factory()->create();
    }

    public function test_syncs_uptime_checks_successfully(): void
    {
        $summary = $this->syncService->syncChecks($this->project, [...]);

        $this->assertEquals([
            'created' => 1,
            'updated' => 0,
            'deleted' => 0,
        ], $summary['uptime_checks']);

        $this->assertDatabaseHas('websites', [
            'name' => 'homepage-uptime',
        ]);
    }
}

// AFTER (Pest)
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->create();
});

test('syncs uptime checks successfully', function () {
    $summary = $this->syncService->syncChecks($this->project, [...]);

    expect($summary['uptime_checks'])->toBe([
        'created' => 1,
        'updated' => 0,
        'deleted' => 0,
    ]);

    assertDatabaseHas('websites', [
        'name' => 'homepage-uptime',
    ]);
});
```

**Assertion Mapping:**

| PHPUnit | Pest |
|---------|------|
| `$this->assertEquals($a, $b)` | `expect($a)->toBe($b)` |
| `$this->assertTrue($x)` | `expect($x)->toBeTrue()` |
| `$this->assertNull($x)` | `expect($x)->toBeNull()` |
| `$this->assertCount(5, $x)` | `expect($x)->toHaveCount(5)` |
| `$this->assertDatabaseHas()` | `assertDatabaseHas()` (unchanged) |

### Phase 3: Subagent Workflow

**Per Subagent:**
1. Read existing PHPUnit test files in assigned category
2. Convert to Pest syntax:
   - Remove class declarations
   - Convert `setUp()` → `beforeEach()`
   - Convert `tearDown()` → `afterEach()`
   - Convert test methods → `test()` or `it()` functions
   - Convert PHPUnit assertions → Pest expectations
3. Run converted tests: `vendor/bin/pest --filter=CategoryName`
4. Verify all tests pass with same assertion counts
5. Commit changes: `Convert [Category] tests to Pest 4`
6. Report completion with test results

### Phase 4: Verification

**Post-Migration Checklist:**
- [ ] All 395 tests pass
- [ ] All 1264 assertions pass
- [ ] Code coverage maintained or improved
- [ ] No new warnings or deprecations
- [ ] `vendor/bin/pint` passes
- [ ] CI pipeline passes

**Verification Commands:**
```bash
vendor/bin/pest                     # All tests pass
vendor/bin/pest --coverage          # Coverage report
vendor/bin/pest --parallel          # Parallel execution
vendor/bin/pint                     # Code style
```

## Safety & Rollback

**Pre-Migration:**
- Branch: `feature/pest-4-migration`
- Baseline: Current test output saved
- Backup: Git history preserves PHPUnit tests

**During Migration:**
- Each subagent commits separately
- Fail fast per suite (not globally)
- Other subagents continue if one fails

**Rollback Plan:**
- Critical failures: `git reset --hard HEAD~N`
- Keep PHPUnit in composer.json temporarily
- Remove PHPUnit only after 100% confidence

## New Pest 4 Features (Optional)

Once migration complete, we can leverage:
- **Browser Testing:** Playwright-powered browser tests
- **Visual Testing:** Screenshot comparison with `assertScreenshotMatches()`
- **Test Sharding:** Split suite across multiple processes
- **Unified Coverage:** Combined backend + frontend coverage
- **2x Faster:** Improved type coverage engine

## Success Criteria

- ✅ All 395 tests converted to Pest syntax
- ✅ All tests pass with same assertion counts
- ✅ Code coverage maintained or improved
- ✅ CI pipeline green
- ✅ No PHPUnit references in test files
- ✅ Pest 4.1.4 installed and configured
- ✅ All subagents report successful completion

## Timeline

- **Phase 1:** Upgrade dependencies (~10 min)
- **Phase 2:** Parallel test conversion (~30 min with 9 subagents)
- **Phase 3:** Verification & cleanup (~10 min)
- **Total:** ~50 minutes

## Dependencies

- PHP 8.3.22 (current)
- PHPUnit 12.x (upgrade from 11.0.1)
- Pest 4.1.4 (upgrade from 3.8.4)
- Laravel 12.x (current)

## Notes

- Pest 4 is backward compatible with Pest 3 syntax
- PHPUnit 12 required for Pest 4
- Can coexist with PHPUnit during migration
- Remove PHPUnit after full verification
