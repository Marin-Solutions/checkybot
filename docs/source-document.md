# Implementation Brief: Eliminate repeated Checkybot website-history lookups

## Proposal and evidence

Sentry project `checkybot`, issue `#21370` / `CHECKYBOT-6G`, reports 17 production N+1 events on `POST /api/v1/mcp`, first seen 2026-07-09 22:08:45 UTC and last seen 2026-07-15 06:59:30 UTC. The Mimir Sentry cache did not return this issue during intake, while the supplied Sentry evidence identifies the endpoint, query, and repository. The project-to-repository mapping is confirmed as `checkybot` -> `Marin-Solutions/checkybot`.

The issue is a performance defect, not a correctness exception: MCP read latency grows with the number of package-managed websites and can amplify database load. It is distinct from approved proposal `857509dc-35f3-4770-89b9-7facf3a331e9`, which concerns `monitor_api_results`.

Repository inspection found:

- `app/Services/CheckybotControlService.php::listChecks()` eager-loads `latestLogHistory` and `latestDiagnosticLogHistory`, then maps websites through `websiteCheckPayload()`.
- `currentWebsiteIssues()` follows the same mapping pattern and eager-loads `latestScheduledLogHistory` and `latestDiagnosticLogHistory`.
- `websiteCheckPayload()` calls `ScheduledFailureStreak::websitePayload()`.
- `ScheduledFailureStreak::forWebsite()` builds a fresh `WebsiteLogHistory` query for each website. It selects the newest scheduled non-failure boundary using status and HTTP-code predicates, then runs a second per-website aggregate query for the failure streak. This is the repeated `website_log_history` lookup observed by Sentry.
- `Website::latestScheduledLogHistory()` currently only constrains `is_on_demand = false`; `latestDiagnosticLogHistory()` constrains `is_on_demand = true`. There is no eager-loaded relation for the scheduled non-failure boundary.
- `ScheduledFailureStreak::apiPayloads()` already demonstrates a bulk, eager-loaded boundary plus grouped aggregate approach that can be mirrored for websites.
- `recentWebsiteRuns()` is already a bulk `whereIn(website_id)` query and maps directly to `websiteResultPayload()`. It does not use `websiteCheckPayload()` or scheduled failure streak calculation and must remain behaviorally unchanged.

Confidence is medium-high. The code path and predicates match the supplied Sentry query; the execution agent should confirm the exact production trace against the final test call.

## Scope

Implement a local Eloquent/service optimization for website scheduled failure streak data used by MCP check and issue payloads.

1. Add a constrained one-of-many `Website` relationship for the newest scheduled non-failure history row, preserving `is_on_demand = false`, status exclusions, HTTP-code exclusions, and `created_at DESC, id DESC` tie-breaking.
2. Add a bulk website streak calculation, modeled on `ScheduledFailureStreak::apiPayloads()`, that requires the boundary relationship to be eager-loaded and performs grouped `website_id` aggregation rather than querying from a collection mapping callback.
3. Eager-load the boundary relation in `listChecks()` and `currentWebsiteIssues()`, compute streaks once per request, and pass the precomputed streak into `websiteCheckPayload()` and related serializers.
4. Keep single-check mutation/queue payloads correct; they may use the existing single-record fallback where no collection mapping is involved, but must reuse any relation already loaded.
5. Do not change `recentRuns` selection, ordering, limits, ownership scoping, payload fields, or JSON shape.
6. Do not add a migration unless implementation evidence proves an existing index is insufficient. The repository already contains `wlh_website_on_demand_created_id_idx` and related history indexes.

Out of scope: suppression or resolution of Sentry issue #21370, changes to `monitor_api_results`, changes to website history retention, API contract redesign, authentication, production data, or unrelated dashboard queries.

## Acceptance criteria

- `list_checks` and `current_issues` return byte-for-byte equivalent website payload semantics for existing fixtures, including `latest_result`, `latest_diagnostic_result`, `scheduled_failure_streak`, status, timestamps, and project/check identity.
- The selected scheduled boundary is exactly the newest row satisfying all of: the scoped website, `is_on_demand = false`, status null or not `warning`/`danger`, and HTTP status null or nonzero and below 400.
- Rows with `is_on_demand = true`, excluded statuses, HTTP status `0`, and HTTP status `>= 400` cannot become the non-failure boundary. Equal timestamps select the greatest `id`.
- Scheduled failure counts and `first_failed_at` remain identical to the current algorithm, including the no-boundary case and rows after a same-timestamp boundary.
- Parent website queries remain scoped by authenticated `created_by`, optional project, active/package-managed conditions, and default `SoftDeletes` behavior. History rows are not exposed across users or projects.
- No website-history lookup is issued from a mapping callback. For a fixed request shape, the number of website-history queries remains constant as the number of package-managed websites increases; the test must compare one website with several websites.
- `recent_runs` retains its current result set, `created_at`/`id` ordering, limit behavior, project/user scoping, and response JSON.
- The targeted Pest file passes, `vendor/bin/pint --dirty` passes, and the execution agent creates a new branch and PR as requested before any deployment.
- Sentry monitoring after deployment shows no recurrence of #21370 and MCP read latency/database query volume no longer scales linearly with website count.

## Applicable repository rules

No `AGENTS.md` or `docs/index.md` exists in this checkout. The binding repository guidance is `CLAUDE.md`, with deployment details in `DEPLOY.md`/`DEVOPS.md`.

Applicable rules quoted from `CLAUDE.md`:

> “You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, naming.”

> “Always use explicit return type declarations for methods and functions.”

> “Always use proper Eloquent relationship methods with return type hints. Prefer relationship methods over raw queries or manual joins.”

> “Generate code that prevents N+1 query problems by using eager loading.”

> “When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.”

> “You must run `vendor/bin/pint --dirty` before finalizing changes to ensure your code matches the project's expected style.”

The implementation must also preserve the task’s repository boundary: application code belongs in the target repository branch/PR, not on a deployed server. No credentials, production data, authentication state, or Sentry issue state may be mutated during remediation.

## Affected files and implementation plan

Expected primary files:

- `app/Models/Website.php`: add the constrained one-of-many scheduled non-failure boundary relation, with explicit return typing and the existing relationship conventions.
- `app/Support/ScheduledFailureStreak.php`: add a bulk website payload method and grouped aggregate logic parallel to `apiPayloads()`. Fail fast if the required boundary relation is not loaded, so future callers cannot silently reintroduce N+1 behavior.
- `app/Services/CheckybotControlService.php`: eager-load the new relation in `listChecks()` and `currentWebsiteIssues()`, compute a keyed streak map before mapping, and let `websiteCheckPayload()` consume the already-computed value. Keep direct single-website paths relation-aware and preserve existing latest-result relations.
- `tests/Feature/Api/V1/CheckybotControlApiTest.php`: add correctness fixtures and query-count coverage beside the existing constant-query API streak tests. Add or extend a recent-runs regression assertion if needed.

Preferred execution sequence:

1. Characterize the current website payload and streak semantics with mixed history fixtures, including tied timestamps.
2. Implement the constrained boundary relation and bulk streak aggregation.
3. Wire both MCP collection paths to the eager-loaded relation and keyed streak map; avoid database access in `map()` callbacks.
4. Verify single-check mutation and queued payload paths still load their required relations.
5. Run focused tests and Pint, inspect the SQL captured by the query-count test, then prepare the requested branch/PR.

## Testing and reproduction plan

Extend `tests/Feature/Api/V1/CheckybotControlApiTest.php` using existing `Project`, `Website`, and `WebsiteLogHistory` factories. Build one authenticated project with package-managed websites and for at least one website create:

- an older scheduled healthy/non-failure row;
- scheduled warning/danger and HTTP `0`/`>=400` rows that must be excluded as boundaries;
- an on-demand row that must not participate in scheduled streak data;
- two same-timestamp rows with different ids to lock the id tie-break;
- scheduled failures after the selected boundary, including a same-timestamp row after the boundary id.

Call the JSON-RPC `tools/call` endpoint for `list_checks` and `current_issues` (website type), assert the selected latest result/streak payload, and capture `DB::listen()` SQL containing `website_log_history`. Compare query counts for one website and a larger set; the count must be equal or bounded by a fixed request-level constant, and no per-website `limit 1` boundary query may appear. Assert all created website keys and streak values so the test proves both scaling and correctness.

Run:

```text
vendor/bin/pest tests/Feature/Api/V1/CheckybotControlApiTest.php
vendor/bin/pint --dirty
```

If the focused test exposes a regression in adjacent serializers, run the closest existing MCP read tests and the relevant `WebsiteLogHistory`/`ScheduledFailureStreak` unit tests. The execution agent should also run the repository’s normal test command if practical before opening the PR.

## Deploy and live verification plan

Follow `DEPLOY.md`:

1. Review the branch diff and PR; do not deploy unreviewed application changes.
2. Deploy staging first through Ploi using server `79201`, site `313325`, and the canonical `bash scripts/deploy/ploi.sh` flow. Do not run `composer update`; production/staging installs must use the committed lockfile.
3. On `https://staging.checkybot.com`, authenticate with a non-production test API key and call MCP `list_checks`, `current_issues`, and `recent_runs` for a project containing multiple package-managed websites. Confirm response JSON, mixed scheduled/on-demand semantics, and no new Sentry/database errors. Review Ploi and Laravel logs if deployment or calls fail.
4. Obtain explicit production approval before deploying site `244469`. The approval comment also requires the code to be on a new branch with a new PR.
5. After production deployment, repeat the three MCP calls against `https://checkybot.com` using an authorized read-only test identity where available. Check Sentry issue #21370, transaction/query timing, and database query counts for recurrence over the next monitoring window. Verify no unrelated queue or application errors.

No browser/UI verification is required for this backend-only change, but Playwright or the MCP HTTP surface may be used for the staging/live request checks if credentials and a safe test project are available.

## Rollback plan

The intended change is local to model relationships, service aggregation, and tests and should require no schema migration. If correctness or performance regresses, stop production rollout and revert the PR commit (or redeploy the previous known-good branch) through the normal Ploi flow. Re-run the targeted Pest test and staging MCP smoke checks after rollback. Do not manually edit production history or issue state. If a migration is unexpectedly introduced, it must have an independently reviewed backward-compatible down path before deployment; otherwise stop for approval.

## Risks and mitigations

- **Selection drift:** moving predicates into a relationship or bulk query can change null handling, excluded statuses, HTTP `0`, or same-timestamp ordering. Keep the existing predicate structure and lock every case in fixtures.
- **One-of-many limitations:** Eloquent’s grouped relation query must preserve all eligibility predicates. If it cannot express the contract safely, use a bulk boundary query keyed by `website_id` rather than weakening predicates.
- **Streak aggregation drift:** a boundary row is not itself the failure count. Preserve the existing “count failures after the newest non-failure boundary” logic, including per-website boundaries and id tie-breaking.
- **Scope leakage:** keep the authenticated user/project/active/package-managed parent query unchanged; rely on default website soft-delete filtering and key all history aggregation to those loaded website ids.
- **Hidden lazy loads:** pass the precomputed streak map into payload construction and fail tests with lazy loading prevention or query listeners so mapping callbacks cannot regress.
- **Recent-run regression:** do not route `recentWebsiteRuns()` through check payload construction; retain its direct `whereIn` history query and add a focused assertion if wiring changes touch common serializers.
- **Operational risk:** deploy staging first, monitor Sentry and SQL/query timing, and retain the small revert surface.

## Related Sentry issues

No related Sentry issue is grouped with #21370. Approved proposal `857509dc-35f3-4770-89b9-7facf3a331e9` is explicitly separate because it targets API monitor result history rather than website history.
