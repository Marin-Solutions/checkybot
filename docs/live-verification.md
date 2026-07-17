# Live verification

Verification window: 2026-07-17 22:50–22:53 UTC

## Release and deployment evidence

- Merge handoff identifies PR #461 and release commit `942aeff475c29c21e5b80228ecf83f1fdac51d2d`, merged at 22:35:47 UTC.
- The production Checkybot API is authenticated and reachable. `checkybot_me` returned app `Checkybot`, URL `https://checkybot.com`, and version `13ebe5f7be3b`. This version is the SHA-256 prefix of the workspace `composer.lock`; the app does not expose the deployed Git SHA, so the exact release commit cannot be independently confirmed through the available read-only DevOps connector.
- Read-only DevOps inspection reports server `checkybot-main` (ID 6) and site `checkybot.com` (ID 33) active, PHP 8.3, SSH-ready, but no deployment event or site audit/release record. The site and server metadata were last synchronized at 16:00:06 UTC.

## Production smoke checks

- `GET https://checkybot.com/` returned HTTP 302 to `/admin`.
- `GET https://checkybot.com/up` returned HTTP 200.
- `POST https://checkybot.com/api/v1/mcp` route check returned HTTP 405 for GET with `Allow: POST`, confirming the protected route is present.
- Authenticated Checkybot MCP/control-surface checks succeeded for project 4 (`Scrappa`): project status returned 117 checks (116 enabled), 112 healthy, 3 danger, and 1 warning; package sync was fresh and marked `synced`.
- `current_issues` with `type=website` returned an empty list.
- `recent_runs` returned active scheduled API and website runs through 22:53 UTC, with healthy website runs and normal scheduled/on-demand fields preserved.
- `list_checks` succeeded for production projects 3 (23 checks) and 9 (5 checks), returning the expected detailed check payloads. The project-4 response (117 checks) exceeded the Checkybot connector’s fixed 10-second timeout on two attempts; this is recorded as a tooling/large-response limitation, not as an HTTP error or response-shape failure.
- Playwright and Chrome DevTools browser checks were unavailable because the container has no Chromium executable at `/opt/google/chrome/chrome`; the equivalent public HTTP checks above were run with curl.

## Sentry regression check

- Issue `CHECKYBOT-6G` / #21370 (`N+1 Query`, `/api/v1/mcp`) remains unresolved but its latest event was `74069f0ff0be4e65b3e0d7204fa8ffc2`, at 2026-07-17 10:54:12 UTC, before the 22:35:47 UTC merge. No event for this issue was observed after the release handoff.
- The separate `CHECKYBOT-6S` / #23161 (`Slow DB Query`) last occurred at 21:51:17 UTC and concerns the approved `monitor_api_results` query, not the `website_log_history` query in #21370.
- Other unresolved production issues observed (`CHECKYBOT-6T` and `CHECKYBOT-6V`) last occurred at 13:45 UTC and concern deployment/bootstrap or scheduled-command failures; neither is a post-release regression.

## Result

The public app, MCP route, authenticated project status, website current-issues path, recent-runs path, and Sentry signal are healthy for the released website-history remediation. No post-merge `website_log_history` N+1 event was observed. Exact deployed Git SHA and the oversized project-4 `list_checks` response remain externally unconfirmable with the available read-only connectors.
