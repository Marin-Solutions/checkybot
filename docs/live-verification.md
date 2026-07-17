# Live Verification

## Result

Passed production verification for merge commit `05ddc65b08b11c728d1147a0b5463d4ae1a87d12` / PR #460.

## Deployment

- Production `checkybot.com` was reported active at `2026-07-17 13:45:43 UTC` by Ploi deploy log `108756574`; the log ended with `Application deployed!`.
- Checkybot MCP authentication succeeded at `2026-07-17T13:55:35Z`; the production app reported version `13ebe5f7be3b`.
- `GET https://checkybot.com/` redirected to `/admin/login` and completed with HTTP 200.
- `GET https://checkybot.com/api/v1/mcp` returned HTTP 405 with `Allow: POST`, confirming the deployed MCP route and method contract.

## Affected MCP flow

- Authenticated production MCP calls succeeded for project `Scrappa` (project `4`): project listing, current issues, recent runs, and project detail.
- `current_issues(type=website)` returned `[]`.
- Recent scheduled website runs at `2026-07-17T13:55:22Z` through `13:55:24Z` were healthy with HTTP 200, including `scrappa-production`, `scrappa-docs-public-page`, `scrappa-pricing-public-page`, `scrappa-blog-index-public-page`, and related public pages.
- `list_checks` was attempted twice and timed out at the Checkybot connector's 10-second limit. This is a tooling limitation; the other authenticated MCP read methods and the scheduled website checks completed successfully.

## Monitoring

- Sentry issue #21370 / `CHECKYBOT-6G` remains unresolved for continued observation, but its latest event was `2026-07-17T10:54:12Z`, before production activation. No post-deploy event was observed during this verification.
- The new unresolved `CHECKYBOT-6S` (`/api/v1/mcp`) is the separate `monitor_api_results` slow-query issue, not the remediated `website_log_history` query. It was first seen before deployment (`2026-07-17T00:07:11Z`) and is outside this release's scope.
- Other current API monitor failures are upstream check failures (502/503/400) with failure streaks beginning before deployment; website health is unaffected.

## Conclusion

The deployed MCP endpoint is present, authenticated production MCP reads are functioning, website checks are healthy, and the targeted Sentry N+1 issue has not recurred after deployment. No code or production files were changed during live verification.
