# Release Handoff

## Result

PR [#460](https://github.com/Marin-Solutions/checkybot/pull/460) was merged successfully. The change is deployed to both staging and production, and the live read-only smoke checks completed without a new application error.

## Release evidence

- Repository: `Marin-Solutions/checkybot`
- Source branch: `fix/checkybot-website-history-n-plus-one`
- PR head: `1b09ee67f6a1c46c124c5bca46dc0015b4454023`
- Merge commit: `05ddc65b08b11c728d1147a0b5463d4ae1a87d12`
- PR merged: `2026-07-17T13:45:13Z`
- Hosted checks before merge: PHP 8.3, Laravel Pint, Claude review, and Cubic review passed.

## Deployment evidence

- Staging site `313325` (`staging.checkybot.com`) became active at `2026-07-17 13:46:18`.
- Staging deploy log `108756589` pulled `master` at `05ddc65b`, completed the asset build, cache rebuild, migration check, queue restart, Horizon termination, and ended with `Application deployed!`.
- Production site `244469` (`checkybot.com`) became active at `2026-07-17 13:45:43` through the repository’s automatic post-merge deployment.
- Production deploy log `108756574` pulled `master` at `05ddc65b`, completed the same deployment flow, and ended with `Application deployed!`.
- Both sites responded over HTTPS; `/` redirected to `/admin`, and `GET /api/v1/mcp` returned `405` with `Allow: POST`, confirming the deployed route is present.

## Live verification

- The configured production Checkybot MCP identity authenticated successfully.
- Production project `Scrappa` (project `4`, 117 checks) returned `current_issues: []` for website checks.
- Production `recent_runs` returned fresh scheduled healthy website and API runs, including website runs at `2026-07-17T13:47:22Z` and `2026-07-17T13:47:23Z`.
- `list_checks` for project `4` timed out at the connector’s 10-second limit; this is recorded as an infrastructure/tooling limitation, not a failed application response.
- No staging API key was available in this run, so authenticated staging MCP fixture calls could not be performed.
- Sentry issue `#21370` / `CHECKYBOT-6G` remained unresolved for monitoring. Its latest observed event was `2026-07-17T10:54:12Z`, before the merge and deployments; no post-deploy event was returned.

No source changes were made during merge/deploy, and no cleanup was performed.
