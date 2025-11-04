# Testing Documentation

## Overview

This document provides comprehensive information about testing in the CheckyBot application.

## Test Environment Setup

### Prerequisites

- PHP 8.3+
- Composer
- SQLite (for testing database)

### Configuration Files

- **phpunit.xml** - PHPUnit configuration with SQLite in-memory database
- **.env.testing** - Testing environment variables
- **tests/TestCase.php** - Base test case with helpful methods

### Environment Variables

The `.env.testing` file is configured for optimal testing:

```env
DB_CONNECTION=sqlite
DB_DATABASE=:memory:
CACHE_STORE=array
QUEUE_CONNECTION=sync
MAIL_MAILER=array
SESSION_DRIVER=array
TELESCOPE_ENABLED=false
HORIZON_ENABLED=false
PULSE_ENABLED=false
BCRYPT_ROUNDS=4
```

## Running Tests

### All Tests

```bash
php artisan test
```

or

```bash
vendor/bin/phpunit
```

### Specific Test Suite

```bash
# Run only unit tests
php artisan test --testsuite=Unit

# Run only feature tests
php artisan test --testsuite=Feature
```

### Specific Test File

```bash
php artisan test tests/Unit/Models/WebsiteTest.php
```

### Specific Test Method

```bash
php artisan test --filter test_website_belongs_to_user
```

### With Coverage (requires XDebug)

```bash
php artisan test --coverage
```

### With Testdox (descriptive output)

```bash
vendor/bin/phpunit --testdox
```

## Test Structure

### Directory Structure

```
tests/
├── Feature/
│   ├── Filament/
│   │   ├── WebsiteResourceTest.php
│   │   ├── ServerResourceTest.php
│   │   └── SeoCheckResourceTest.php
│   ├── Api/
│   │   └── WebsiteApiTest.php
│   └── ExampleTest.php
├── Unit/
│   ├── Models/
│   │   ├── WebsiteTest.php
│   │   ├── SeoCheckTest.php
│   │   └── ServerTest.php
│   ├── Policies/
│   │   ├── UserPolicyTest.php
│   │   └── WebsitePolicyTest.php
│   ├── Jobs/
│   │   ├── LogUptimeSslJobTest.php
│   │   └── SeoHealthCheckJobTest.php
│   └── Services/
│       ├── SeoHealthCheckServiceTest.php
│       └── SeoIssueDetectionServiceTest.php
└── TestCase.php
```

## Test Categories

### Unit Tests

Unit tests focus on individual classes and methods in isolation.

**What to test:**
- Model relationships
- Model methods and scopes
- Policy authorization logic
- Service class methods
- Enum behavior
- Value objects

**Example:**
```php
public function test_website_belongs_to_user(): void
{
    $user = User::factory()->create();
    $website = Website::factory()->create(['created_by' => $user->id]);

    $this->assertInstanceOf(User::class, $website->user);
    $this->assertEquals($user->id, $website->user->id);
}
```

### Feature Tests

Feature tests verify application behavior from the user's perspective.

**What to test:**
- Filament resource CRUD operations
- API endpoints
- Commands
- Jobs
- User workflows
- Integration between components

**Example:**
```php
public function test_super_admin_can_create_website(): void
{
    $user = $this->actingAsSuperAdmin();

    Livewire::test(CreateWebsite::class)
        ->fillForm([
            'name' => 'Test Website',
            'url' => 'https://test.com',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('websites', [
        'name' => 'Test Website',
    ]);
}
```

## Available Factories

All models have comprehensive factories with useful states:

### Core Factories

- **UserFactory** - `User::factory()`
- **WebsiteFactory** - `Website::factory()`
- **ServerFactory** - `Server::factory()`
- **MonitorApisFactory** - `MonitorApis::factory()`
  - `withCustomHeaders(array $headers)`
  - `withDataPath(string $path)`
- **SeoCheckFactory** - `SeoCheck::factory()`
  - `running()`
  - `completed()`
  - `failed()`
- **SeoCrawlResultFactory** - `SeoCrawlResult::factory()`
  - `withError()`
  - `withRedirect()`
  - `slow()`
- **SeoIssueFactory** - `SeoIssue::factory()`
  - `error()`
  - `warning()`
  - `notice()`
- **SeoScheduleFactory** - `SeoSchedule::factory()`
  - `daily()`
  - `weekly()`
  - `monthly()`
  - `inactive()`
- **ApiKeyFactory** - `ApiKey::factory()`
  - `expired()`
  - `inactive()`
  - `recentlyUsed()`

### Example Usage

```php
// Create a website with related SEO check
$website = Website::factory()->create();
$check = SeoCheck::factory()->completed()->create([
    'website_id' => $website->id,
]);

// Create SEO issues with specific severity
$errors = SeoIssue::factory()->error()->count(5)->create([
    'seo_check_id' => $check->id,
]);

$warnings = SeoIssue::factory()->warning()->count(10)->create([
    'seo_check_id' => $check->id,
]);
```

## Helper Methods in TestCase

### Authentication Helpers

```php
// Create and authenticate as super admin
$superAdmin = $this->actingAsSuperAdmin();

// Create and authenticate as admin
$admin = $this->actingAsAdmin();

// Create and authenticate as user with specific role
$user = $this->actingAsUser('Custom Role');
```

### Assertion Helpers

```php
// Assert query count for N+1 prevention
$this->assertQueryCount(5, function () {
    // Code that executes queries
});
```

## Testing Best Practices

### 1. Use Descriptive Test Names

```php
// Good
public function test_super_admin_can_delete_website(): void

// Bad
public function test_delete(): void
```

### 2. Follow AAA Pattern

```php
public function test_example(): void
{
    // Arrange
    $user = User::factory()->create();
    $website = Website::factory()->create();

    // Act
    $result = $user->websites()->save($website);

    // Assert
    $this->assertTrue($result);
}
```

### 3. Test One Thing Per Test

```php
// Good - tests one specific behavior
public function test_website_requires_url(): void
{
    $this->expectException(ValidationException::class);
    Website::factory()->create(['url' => null]);
}

// Bad - tests multiple things
public function test_website_validation(): void
{
    // Tests URL validation
    // Tests name validation
    // Tests description validation
}
```

### 4. Use Factory States

```php
// Good - using factory state
$check = SeoCheck::factory()->completed()->create();

// Less good - manually setting attributes
$check = SeoCheck::factory()->create([
    'status' => 'completed',
    'started_at' => now()->subHour(),
    'finished_at' => now(),
    'progress' => 100,
]);
```

### 5. Mock External Services

```php
public function test_api_monitor_checks_endpoint(): void
{
    Http::fake([
        'api.example.com/*' => Http::response(['status' => 'ok'], 200),
    ]);

    $monitor = MonitorApis::factory()->create([
        'url' => 'https://api.example.com/health',
    ]);

    $result = $monitor->testApi();

    $this->assertTrue($result->is_success);
}
```

### 6. Clean Up After Tests

The `RefreshDatabase` trait in `TestCase.php` automatically handles database cleanup between tests.

## Continuous Integration

### GitHub Actions

Tests run automatically on every push and pull request via GitHub Actions.

**Configuration:** `.github/workflows/tests.yml`

**What runs:**
- PHPUnit test suite
- Laravel Pint (code style)

**Workflow:**
1. Checkout code
2. Setup PHP 8.3
3. Install Composer dependencies
4. Copy `.env.testing`
5. Generate application key
6. Run migrations
7. Execute tests

### Local CI Simulation

```bash
# Run the same checks as CI
composer install
cp .env.testing .env
php artisan key:generate --env=testing
php artisan migrate --env=testing
vendor/bin/phpunit
vendor/bin/pint --test
```

## Code Coverage

### Generate Coverage Report

```bash
# HTML report
vendor/bin/phpunit --coverage-html coverage

# Text report
vendor/bin/phpunit --coverage-text
```

### Coverage Goals

- **Overall:** 70%+
- **Models:** 90%+
- **Policies:** 95%+
- **Services:** 80%+
- **Jobs:** 80%+

## Common Testing Patterns

### Testing Filament Resources

```php
use Livewire\Livewire;

public function test_can_list_records(): void
{
    $this->actingAsSuperAdmin();
    $websites = Website::factory()->count(3)->create();

    Livewire::test(ListWebsites::class)
        ->assertCanSeeTableRecords($websites);
}
```

### Testing Authorization

```php
public function test_user_needs_permission(): void
{
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(ListWebsites::class)
        ->assertForbidden();
}
```

### Testing Jobs

```php
public function test_job_processes_correctly(): void
{
    Queue::fake();

    $website = Website::factory()->create();

    dispatch(new SeoHealthCheckJob($website));

    Queue::assertPushed(SeoHealthCheckJob::class);
}
```

### Testing Commands

```php
public function test_command_executes_successfully(): void
{
    $this->artisan('seo:run-scheduled')
        ->assertSuccessful();
}
```

## Troubleshooting

### Tests Fail with "Database not found"

Make sure SQLite is installed and the test database is created:

```bash
touch database/database.sqlite
```

### Permission Errors

Ensure Shield is properly installed:

```bash
php artisan shield:install --fresh --minimal
```

### Slow Tests

- Use SQLite in-memory database (`:memory:`)
- Set `BCRYPT_ROUNDS=4` in `.env.testing`
- Disable Telescope, Horizon, and Pulse in tests

### Factory Not Found

Regenerate autoload files:

```bash
composer dump-autoload
```

## Writing New Tests

### Checklist for New Features

When adding a new feature, create tests for:

- [ ] Model relationships and methods
- [ ] Factory with useful states
- [ ] Policy authorization rules
- [ ] Filament resource CRUD operations
- [ ] Jobs and queued operations
- [ ] Commands
- [ ] Services and business logic
- [ ] API endpoints (if applicable)

### Test Template

```php
<?php

namespace Tests\Unit\Models;

use Tests\TestCase;

class NewFeatureTest extends TestCase
{
    public function test_description_of_behavior(): void
    {
        // Arrange

        // Act

        // Assert
    }
}
```

## Resources

- [Laravel Testing Documentation](https://laravel.com/docs/testing)
- [PHPUnit Documentation](https://phpunit.de/documentation.html)
- [Filament Testing](https://filamentphp.com/docs/panels/testing)
- [Pest PHP](https://pestphp.com/) (alternative testing framework)

## Contributing

When contributing tests:

1. Follow existing test structure and naming conventions
2. Ensure all tests pass locally before pushing
3. Add factories for new models
4. Document complex test scenarios
5. Maintain test isolation (no dependencies between tests)

---

**Last Updated:** November 4, 2025
**Test Coverage:** In Progress
**Total Tests:** 20+ (and growing)
