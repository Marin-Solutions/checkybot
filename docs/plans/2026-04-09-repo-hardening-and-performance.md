# Repo Hardening And Performance Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Harden the Laravel app against the security findings from the audit and remove the highest-cost performance/index bottlenecks without breaking existing monitoring flows.

**Architecture:** Split the work into four largely independent tracks: route/auth hardening, API key + panel access hardening, scheduler/crawler/runtime efficiency, and widget/query/index fixes. Keep behavior changes minimal, preserve existing UX where possible, and add focused regression coverage around the highest-risk paths.

**Tech Stack:** Laravel 12, Filament 4, Pest 4, Eloquent, queues/scheduler, MySQL-style migrations.

### Task 1: Route And Authorization Hardening

**Files:**
- Modify: `routes/web.php`
- Modify: `routes/api.php`
- Modify: `app/Http/Controllers/Api/V1/ServerController.php`
- Modify: `app/Policies/ServerPolicy.php`
- Modify: `app/Models/ServerInformationHistory.php`
- Modify: `app/Models/ServerLogFileHistory.php`
- Modify: `app/Models/Backup.php`
- Modify: `app/Services/SeoReportGenerationService.php`
- Test: `tests/Feature/...` route / auth regression coverage

**Step 1: Write failing tests**
- Cover anonymous access to shell-script routes.
- Cover anonymous access to `/api/v1/servers`.
- Cover cross-user server access through policy/controller.
- Cover report download isolation.

**Step 2: Run tests to verify failure**
- Run: `php artisan test tests/Feature`

**Step 3: Implement minimal fixes**
- Require auth on sensitive web download routes and enforce ownership server-side.
- Require auth on server API routes.
- Enforce owner-aware server authorization.
- Move SEO reports to user-scoped storage paths / URLs and validate ownership on download.

**Step 4: Run focused tests**
- Run: `php artisan test tests/Feature`

### Task 2: API Key And Admin Panel Hardening

**Files:**
- Modify: `app/Models/ApiKey.php`
- Modify: `app/Http/Middleware/ApiKeyAuthentication.php`
- Modify: `app/Filament/Resources/ApiKeyResource.php`
- Modify: `app/Filament/Resources/ApiKeyResource/Pages/CreateApiKey.php`
- Modify: `app/Models/User.php`
- Modify: `app/Filament/Resources/WebsiteResource.php`
- Modify: `app/Filament/Resources/ServerResource.php`
- Add: migration for API key hashing / indexing
- Test: `tests/Feature/Filament/ApiKeyResourceTest.php`
- Test: `tests/Unit/Models/ApiKeyTest.php`

**Step 1: Write failing tests**
- Cover hashed key persistence + plain-text one-time generation path.
- Cover middleware authenticating against hashed keys.
- Cover panel/resource access denied for unprivileged users.

**Step 2: Run tests to verify failure**
- Run: `php artisan test tests/Feature/Filament/ApiKeyResourceTest.php tests/Unit/Models/ApiKeyTest.php`

**Step 3: Implement minimal fixes**
- Add hash column / backfill strategy and authenticate by hash with legacy-safe migration path if needed.
- Stop showing raw keys in list tables.
- Restrict Filament panel access to authorized users and stop bypassing resource gates.

**Step 4: Run focused tests**
- Run: `php artisan test tests/Feature/Filament/ApiKeyResourceTest.php tests/Unit/Models/ApiKeyTest.php`

### Task 3: Scheduler And Crawler Runtime Efficiency

**Files:**
- Modify: `routes/console.php`
- Modify: `app/Console/Kernel.php`
- Modify: `app/Console/Commands/RunScheduledSeoChecks.php`
- Modify: `app/Services/SeoHealthCheckService.php`
- Modify: `app/Crawlers/SeoHealthCheckCrawler.php`
- Modify: `app/Services/RobotsSitemapService.php`
- Modify: `app/Console/Commands/CheckApiMonitors.php`
- Possibly add: job for per-monitor execution if needed
- Test: `tests/Unit/Commands/RunScheduledSeoChecksTest.php`
- Test: `tests/Unit/Commands/CheckApiMonitorsTest.php`
- Test: `tests/Unit/Crawlers/SeoHealthCheckCrawlerTest.php`
- Test: `tests/Unit/Services/SeoHealthCheckServiceTest.php`
- Test: `tests/Unit/Services/RobotsSitemapServiceTest.php`

**Step 1: Write / adjust failing tests**
- Cover duplicate SEO check prevention.
- Cover crawler batching / lower write amplification where observable.
- Cover robots memoization behavior.
- Cover monitor command dispatch path if converted to queued fan-out.

**Step 2: Run tests to verify failure**
- Run: `php artisan test tests/Unit/Commands/RunScheduledSeoChecksTest.php tests/Unit/Commands/CheckApiMonitorsTest.php tests/Unit/Crawlers/SeoHealthCheckCrawlerTest.php tests/Unit/Services/SeoHealthCheckServiceTest.php tests/Unit/Services/RobotsSitemapServiceTest.php`

**Step 3: Implement minimal fixes**
- Add scheduler overlap protection.
- Atomically claim or guard SEO work.
- Cache robots/sitemap lookups per host during a crawl.
- Replace row-by-row crawl result inserts with bulk insert.
- Reduce parent-row updates / repeated count queries.
- Queue fan-out API monitor checks instead of synchronous all-at-once HTTP work if feasible within current architecture.

**Step 4: Run focused tests**
- Run the same command as Step 2.

### Task 4: Query, Widget, And Index Optimization

**Files:**
- Modify: `app/Filament/Widgets/SeoDashboardStatsWidget.php`
- Modify: `app/Filament/Widgets/ApiHealthStatsWidget.php`
- Modify: `app/Filament/Resources/MonitorApisResource/Widgets/ResponseTimeChart.php`
- Modify: `app/Filament/Resources/SeoCheckResource.php`
- Modify: `app/Filament/Resources/WebsiteResource.php`
- Modify: `app/Services/CheckSyncService.php`
- Modify: `app/Services/ProjectComponentSyncService.php`
- Add: migration for missing hot-path indexes
- Test: existing widget/resource/service tests plus new targeted coverage

**Step 1: Write failing / characterization tests**
- Cover latest-result query behavior.
- Cover user-scoped dashboard aggregation correctness.
- Cover sync services without per-item query assumptions where practical.

**Step 2: Run tests to verify failure**
- Run: `php artisan test tests/Feature/Filament tests/Unit/Services tests/Unit/Commands`

**Step 3: Implement minimal fixes**
- Replace repeated pluck/count widget patterns with grouped queries.
- Aggregate response-time chart data in SQL instead of loading raw rows.
- Remove unnecessary eager-loaded collections on resource index pages.
- Preload existing sync targets and index them in memory by name.
- Add the missing composite indexes for `seo_checks`, `monitor_api_results`, `websites`, and `monitor_apis`.

**Step 4: Run focused tests**
- Run: `php artisan test tests/Feature/Filament tests/Unit/Services tests/Unit/Commands`

### Final Verification

**Step 1: Run formatting**
- Run: `vendor/bin/pint --dirty`

**Step 2: Run targeted suite**
- Run: `php artisan test`

**Step 3: Manual sanity checks**
- Check `php artisan route:list` for protected routes.
- Check migrations compile.

