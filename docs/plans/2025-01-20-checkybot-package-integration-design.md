# Checkybot Package Integration System - Design Document

**Date:** 2025-01-20
**Status:** Approved for Implementation

## Overview

This design document outlines the integration system that allows developers to define monitoring checks in their Laravel applications via a Composer package, which then syncs these checks to a Checkybot instance for scheduled monitoring.

## Goals

- Allow developers to define monitoring checks as code in their Laravel applications
- Support uptime monitoring, SSL checks, and API endpoint validation
- Sync check definitions to Checkybot via API, with Checkybot handling execution
- Keep check definitions in sync (create, update, delete based on code)
- Leverage existing Checkybot monitoring infrastructure

## High-Level Architecture

### System Components

**Checkybot Core Platform:**
- Central monitoring platform that executes checks
- Refactors `projects` as the primary entity for organizing monitoring
- Accepts check definitions via REST API
- Schedules and executes checks at user-defined intervals
- Stores results and provides notification management through UI

**Checkybot Laravel Package:**
- Installed in users' Laravel applications via Composer
- Provides fluent configuration in `config/checkybot.php`
- Offers `php artisan checkybot:sync` command
- Authenticates using API Key + Project ID
- Transforms config definitions into API payloads

### Key Workflow

1. User creates a Project in Checkybot UI, receives Project ID
2. User installs package in their Laravel app via Composer
3. User configures API key, Project ID, and check definitions
4. User runs `php artisan checkybot:sync` (typically in deployment pipeline)
5. Checkybot receives all checks, performs full sync with pruning
6. Checkybot schedules and runs checks at specified intervals
7. Results stored, notifications managed entirely in Checkybot

## Design Decisions

### 1. Entity Model
**Decision:** Projects are the central entity. Both websites and monitor_apis belong to projects.

**Rationale:** Aligns with how developers think - a project contains multiple URLs and APIs to monitor.

### 2. Sync Behavior
**Decision:** Full sync with pruning (package code is source of truth).

**Rationale:**
- Ensures package and Checkybot stay perfectly in sync
- Removing a check from code removes it from monitoring
- Atomic operation prevents inconsistent state

### 3. Check Intervals
**Decision:** Package-defined intervals, Checkybot respects them.

**Rationale:**
- Keeps all configuration in code
- Version controlled alongside the application
- Different environments can have different intervals

### 4. Authentication
**Decision:** API Key + Project ID.

**Rationale:**
- API Key authenticates the user
- Project ID explicitly identifies which project to sync
- Clear separation of concerns

### 5. Project Creation
**Decision:** Projects must be created in Checkybot UI first.

**Rationale:**
- Explicit project setup ensures user understands what they're creating
- Prevents accidental project creation
- Allows pre-configuration of notifications and settings

### 6. Check Types
**Decision:** Three separate check types - UptimeCheck, SslCheck, ApiCheck.

**Rationale:**
- Clear separation of concerns
- Each type has distinct configuration requirements
- Easier to document and understand

### 7. Registration Pattern
**Decision:** Config file based (`config/checkybot.php`).

**Rationale:**
- Familiar Laravel pattern
- Simple array-based configuration
- Easy to understand and maintain
- No magic, explicit definitions

### 8. Notifications
**Decision:** Configured entirely in Checkybot UI.

**Rationale:**
- Separates check definitions from notification routing
- Allows changing notification channels without code changes
- Leverages existing notification system

### 9. Check Naming
**Decision:** Names unique per project.

**Rationale:**
- Scoped uniqueness prevents conflicts
- Different projects can reuse intuitive names
- Natural identifier for updates

### 10. API Structure
**Decision:** Single bulk sync endpoint.

**Rationale:**
- Atomic operation ensures consistency
- Single HTTP request improves performance
- Simpler error handling

### 11. Assertion Capabilities
**Decision:** Expose full assertion system in package.

**Rationale:**
- Maximum flexibility for developers
- No need to switch to UI for advanced assertions
- Everything defined in code

## Database Schema Changes

### Projects Table Refactoring

The existing `projects` table will be refactored from error tracking to general monitoring:

**Keep:**
- `id` (primary key)
- `name` (project name)
- `token` (for API authentication)
- `created_by` (user ownership)
- `created_at`, `updated_at`

**Deprecate/Remove:**
- Error tracking specific fields (or keep nullable for backward compatibility)

### Monitoring Tables Enhancement

**Add to `websites` table:**
```sql
project_id BIGINT NULLABLE, FOREIGN KEY (projects.id)
source ENUM('manual', 'package') DEFAULT 'manual'
package_name VARCHAR(255) NULLABLE
package_interval VARCHAR(50) NULLABLE
```

**Add to `monitor_apis` table:**
```sql
project_id BIGINT NULLABLE, FOREIGN KEY (projects.id)
source ENUM('manual', 'package') DEFAULT 'manual'
package_name VARCHAR(255) NULLABLE (stores the check name)
package_interval VARCHAR(50) NULLABLE
```

**Indexes:**
- `(project_id, source, package_name)` for efficient lookups
- `(project_id, source)` for pruning operations

## API Specification

### Endpoint

```
POST /api/v1/projects/{project}/checks/sync
```

### Authentication

- Bearer token using Sanctum (existing API key system)
- Validates user owns the specified project
- Rate limited (10 requests/minute per key)

### Request Payload

```json
{
  "uptime_checks": [
    {
      "name": "homepage-uptime",
      "url": "https://convertr.org",
      "interval": "5m",
      "max_redirects": 10
    }
  ],
  "ssl_checks": [
    {
      "name": "homepage-ssl",
      "url": "https://convertr.org",
      "interval": "1d"
    }
  ],
  "api_checks": [
    {
      "name": "health-endpoint",
      "url": "https://api.convertr.org/health",
      "interval": "5m",
      "headers": {
        "Authorization": "Bearer xyz",
        "Accept": "application/json"
      },
      "assertions": [
        {
          "data_path": "data.status",
          "assertion_type": "exists",
          "sort_order": 1,
          "is_active": true
        },
        {
          "data_path": "data.count",
          "assertion_type": "comparison",
          "comparison_operator": ">=",
          "expected_value": "1",
          "sort_order": 2,
          "is_active": true
        }
      ]
    }
  ]
}
```

### Validation Rules

**Uptime Checks:**
- `name`: required, string, max 255
- `url`: required, valid URL, max 1000
- `interval`: required, format `/^\d+[mhd]$/` (e.g., '5m', '2h', '1d')
- `max_redirects`: optional, integer, 0-20, default 10

**SSL Checks:**
- `name`: required, string, max 255
- `url`: required, valid URL, max 1000
- `interval`: required, format `/^\d+[mhd]$/`

**API Checks:**
- `name`: required, string, max 255
- `url`: required, valid URL, max 1000
- `interval`: required, format `/^\d+[mhd]$/`
- `headers`: optional, array
- `assertions`: optional, array
- `assertions.*.data_path`: required, string
- `assertions.*.assertion_type`: required, enum (exists, type, comparison, regex)
- `assertions.*.comparison_operator`: required if type=comparison
- `assertions.*.expected_value`: required if type=comparison
- Additional validation per assertion type

**Global Limits:**
- Max 100 checks per type per sync
- Check names must be unique within their type

### Response

**Success (200):**
```json
{
  "message": "Checks synced successfully",
  "summary": {
    "uptime_checks": {
      "created": 1,
      "updated": 0,
      "deleted": 0
    },
    "ssl_checks": {
      "created": 1,
      "updated": 0,
      "deleted": 0
    },
    "api_checks": {
      "created": 1,
      "updated": 2,
      "deleted": 3
    }
  }
}
```

**Error (422):**
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "uptime_checks.0.url": ["The url field must be a valid URL."],
    "api_checks.2.interval": ["The interval format is invalid."]
  }
}
```

**Error (403):**
```json
{
  "message": "You do not have permission to manage this project."
}
```

## Sync Logic Implementation

### Processing Steps

1. **Validate Request**
   - Check authentication and project ownership
   - Validate payload structure and data
   - Ensure check name uniqueness within types

2. **Process Uptime Checks**
   - Match existing: `project_id` + `package_name` + `source='package'`
   - Upsert to `websites` table
   - Set: `uptime_check=true`, `uptime_interval`, `source='package'`

3. **Process SSL Checks**
   - Match existing: `project_id` + `package_name` + `source='package'`
   - Upsert to `websites` table
   - Set: `ssl_check=true`, `source='package'`

4. **Process API Checks**
   - Match existing: `project_id` + `title` (=package_name) + `source='package'`
   - Upsert to `monitor_apis` table
   - Sync related `monitor_api_assertions` (delete old, insert new)
   - Set: `source='package'`, `package_interval`

5. **Prune Orphaned Checks**
   - Find all checks: `project_id={id}` AND `source='package'`
   - Delete any whose `package_name` not in sync payload
   - **Critical:** Only delete package-managed checks, never touch manual ones

6. **Schedule Checks**
   - Parse interval strings ('5m' → 5 minutes, '1h' → 60 minutes, '1d' → 1440 minutes)
   - Update next run times
   - Integrate with existing scheduling system

### Transaction Handling

- Entire sync operation wrapped in database transaction
- Any error triggers rollback
- Prevents partial sync states

### Service Structure

```php
class CheckSyncService
{
    public function syncChecks(Project $project, array $payload): array
    {
        DB::transaction(function () use ($project, $payload) {
            $summary = [
                'uptime_checks' => $this->syncUptimeChecks($project, $payload['uptime_checks'] ?? []),
                'ssl_checks' => $this->syncSslChecks($project, $payload['ssl_checks'] ?? []),
                'api_checks' => $this->syncApiChecks($project, $payload['api_checks'] ?? []),
            ];

            return $summary;
        });
    }

    protected function syncUptimeChecks(Project $project, array $checks): array { /* ... */ }
    protected function syncSslChecks(Project $project, array $checks): array { /* ... */ }
    protected function syncApiChecks(Project $project, array $checks): array { /* ... */ }
    protected function pruneOrphanedChecks(Project $project, array $keepNames, string $type): int { /* ... */ }
}
```

## Security Considerations

### Authentication & Authorization
- Sanctum bearer token authentication
- Verify API key is active and not expired
- Check user owns the target project
- Rate limiting: 10 requests/minute per API key

### Input Validation
- Comprehensive form request validation
- URL validation prevents malformed URLs
- Header sanitization prevents injection
- Interval format strictly validated
- Assertion types limited to known enums

### Safeguards
- Transaction-based operations (atomic)
- Maximum limits on check counts
- Scoped deletion (only package-managed checks)
- Audit logging of all sync operations
- API response doesn't leak sensitive data

### Potential Threats & Mitigations
- **Malicious URL injection:** Validated via Laravel URL rules
- **Header injection:** Headers sanitized and validated
- **Resource exhaustion:** Rate limiting and check count limits
- **Unauthorized access:** Token + project ownership verification
- **Accidental deletions:** Only deletes `source='package'` checks

## UI/UX Considerations

### Filament Admin Panel

**Project Management:**
- Add "Sync Status" widget showing last sync time
- Display package-managed check counts

**Website/Monitor Lists:**
- Badge indicating "Package Managed" vs "Manual"
- Disable editing for package-managed checks (or show warning)
- Filter option to show only manual or package checks

**Package-Managed Check Details:**
- Read-only view with "Managed by Package" notice
- Show package check name and last sync time
- Link to documentation on how to modify in code

### User Education
- Clear documentation on package vs manual checks
- Warning when trying to edit package-managed check
- Guidance on proper workflow (edit config → sync)

## Package Developer Responsibilities

The Checkybot package developer will need to build:

### Core Package Structure
```
checkybot/laravel-package/
├── config/
│   └── checkybot.php (published config)
├── src/
│   ├── CheckybotServiceProvider.php
│   ├── Console/
│   │   └── SyncCommand.php
│   ├── Http/
│   │   └── CheckybotClient.php
│   ├── CheckDefinitions/
│   │   ├── UptimeCheck.php
│   │   ├── SslCheck.php
│   │   └── ApiCheck.php
│   └── ConfigParser.php
└── tests/
```

### Configuration File Structure
```php
// config/checkybot.php
return [
    'api_key' => env('CHECKYBOT_API_KEY'),
    'project_id' => env('CHECKYBOT_PROJECT_ID'),
    'base_url' => env('CHECKYBOT_URL', 'https://checkybot.com'),

    'checks' => [
        // Uptime Checks
        'uptime' => [
            [
                'name' => 'homepage-uptime',
                'url' => 'https://example.com',
                'interval' => '5m',
                'max_redirects' => 10,
            ],
        ],

        // SSL Checks
        'ssl' => [
            [
                'name' => 'homepage-ssl',
                'url' => 'https://example.com',
                'interval' => '1d',
            ],
        ],

        // API Checks
        'api' => [
            [
                'name' => 'health-check',
                'url' => 'https://api.example.com/health',
                'interval' => '5m',
                'headers' => [
                    'Authorization' => 'Bearer '.env('API_TOKEN'),
                ],
                'assertions' => [
                    [
                        'data_path' => 'status',
                        'assertion_type' => 'exists',
                    ],
                    [
                        'data_path' => 'database.connected',
                        'assertion_type' => 'comparison',
                        'comparison_operator' => '==',
                        'expected_value' => 'true',
                    ],
                ],
            ],
        ],
    ],
];
```

### Sync Command Implementation
```bash
php artisan checkybot:sync
php artisan checkybot:sync --dry-run (show what would be synced)
```

### HTTP Client Requirements
- Use Guzzle or Laravel HTTP client
- Handle authentication via Bearer token
- Retry logic for network failures
- Clear error messages for API errors
- Timeout configuration

### Documentation Needs
- Installation instructions
- Configuration guide
- All check types with examples
- Assertion syntax reference
- Interval format specification
- Troubleshooting guide
- CI/CD integration examples

## Testing Strategy

### Checkybot Backend Tests

**Feature Tests:**
- `test_sync_creates_new_checks()`
- `test_sync_updates_existing_checks()`
- `test_sync_deletes_removed_checks()`
- `test_sync_preserves_manual_checks()`
- `test_sync_requires_authentication()`
- `test_sync_requires_project_ownership()`
- `test_sync_validates_payload_structure()`
- `test_sync_handles_assertions_correctly()`
- `test_sync_is_transactional()`
- `test_sync_returns_correct_summary()`

**Unit Tests:**
- `IntervalParserTest` (test '5m' → minutes conversion)
- `CheckSyncServiceTest` (isolated service logic)

### Package Tests
- HTTP client mocking
- Config parsing validation
- Command output verification
- Error handling scenarios

## Implementation Checklist

### Phase 1: Database & Models
- [ ] Create migration for projects table refactoring
- [ ] Create migration for websites table (add project_id, source, package_name, package_interval)
- [ ] Create migration for monitor_apis table (add project_id, source, package_name, package_interval)
- [ ] Update Project model with relationships
- [ ] Update Website model with project relationship
- [ ] Update MonitorApis model with project relationship

### Phase 2: API Endpoint
- [ ] Create SyncProjectChecksRequest for validation
- [ ] Create ProjectChecksController with sync method
- [ ] Add route to api.php with Sanctum middleware
- [ ] Create CheckSyncService
- [ ] Implement interval parser utility
- [ ] Add proper authorization checks

### Phase 3: Sync Logic
- [ ] Implement syncUptimeChecks method
- [ ] Implement syncSslChecks method
- [ ] Implement syncApiChecks method
- [ ] Implement pruneOrphanedChecks method
- [ ] Add transaction wrapping
- [ ] Implement summary response generation

### Phase 4: Scheduling
- [ ] Update job scheduler to respect package_interval
- [ ] Create jobs for executing package-managed checks
- [ ] Ensure existing monitoring continues to work

### Phase 5: UI Updates
- [ ] Add "Package Managed" badge to Filament resources
- [ ] Disable editing of package-managed checks
- [ ] Add project sync status widget
- [ ] Add filters for manual vs package checks

### Phase 6: Testing
- [ ] Write feature tests for sync endpoint
- [ ] Write unit tests for services
- [ ] Test authorization edge cases
- [ ] Test transaction rollback scenarios
- [ ] Test interval parsing

### Phase 7: Documentation
- [ ] API endpoint documentation
- [ ] Database schema documentation
- [ ] Sync process flow diagram
- [ ] Security considerations document

## Future Enhancements (Out of Scope)

- Immediate check execution on sync (run now option)
- Check preview/validation before sync
- Sync history and audit log UI
- Support for check groups or tags
- Advanced scheduling (cron expressions)
- Check dependencies (run B after A)
- Multi-environment support in single config
- Dry-run API endpoint

## Conclusion

This design provides a robust foundation for integrating package-defined monitoring checks into Checkybot. The approach prioritizes:
- Developer experience (config-based, familiar patterns)
- Data integrity (transactions, validation)
- Security (authentication, authorization, input validation)
- Maintainability (clear separation of concerns)
- Flexibility (full assertion system support)

The implementation can proceed in phases, with the database and API endpoint forming the critical path.
