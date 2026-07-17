# Implementation summary

## Scope

Remediated the website-history path behind MCP `list_checks` and website `current_issues` for Sentry issue `CHECKYBOT-6G` (`#21370`). The existing constrained eager-load and bulk streak aggregation from the approved candidate baseline were verified against Laravel's generated `ofMany` SQL; this branch additionally removes relationship-query fallbacks from the `current_issues` website mapping path.

## Changes

- `current_issues` now reads its eagerly loaded scheduled, diagnostic, and latest website history relations directly inside the website mapping path.
- Added a regression fixture proving the newest eligible scheduled row wins on equal timestamps by highest `id`, while newer warning and on-demand rows retain the scheduled-failure contract.
- Existing constant-query coverage for `list_checks` and website `current_issues` remains in place and asserts no per-website `limit 1` history lookup.

The unrelated `monitor_api_results` optimization was not changed by this implementation work. `recent_runs` remains unchanged.

## Verification

- `php artisan test tests/Feature/Api/V1/CheckybotControlApiTest.php` — 113 passed, 1,070 assertions.
- `vendor/bin/pint --dirty` — passed.
- Laravel relation SQL inspection confirmed the constrained eager load applies the eligibility predicates and the `created_at`/`id` tie-break.

## Handoff

Work is committed on branch `remediation/checkybot-21370`. This implementation stage does not push or open a PR; the downstream PR/deployment stages should use this branch and complete hosted CI and live Sentry verification.
