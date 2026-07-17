# Release handoff

## Release

- Repository: `Marin-Solutions/checkybot`
- PR: [#461](https://github.com/Marin-Solutions/checkybot/pull/461)
- PR title: Eliminate MCP website history lookup fallbacks
- Merge method: squash
- Merge timestamp: `2026-07-17T22:35:47Z`
- Release commit: `942aeff475c29c21e5b80228ecf83f1fdac51d2d`
- Remote `master` verification: `refs/heads/master` points to the release commit

## CI evidence

Post-merge push workflow [Tests #29618358674](https://github.com/Marin-Solutions/checkybot/actions/runs/29618358674) completed successfully at `2026-07-17T22:39:41Z` for the release commit.

- PHP 8.3: success, completed `2026-07-17T22:38:02Z`
- Laravel Pint: success, completed `2026-07-17T22:39:38Z`

The PR-gate runs for PHP 8.3, Laravel Pint, and Claude review also passed before merge.

## Deployment evidence

- DevOps connection: connected; last synchronized `2026-07-17T16:00:19Z`.
- Production server: `checkybot-main` (server ID `6`), active, PHP 8.3, SSH-ready; last synchronized `2026-07-17T16:00:06Z`.
- Production site: `checkybot.com` (site ID `33`), active Laravel site on `checkybot-main`; last synchronized `2026-07-17T16:00:06Z`.
- Live HTTP smoke check at `2026-07-17T22:38:03Z`: `https://checkybot.com/` returned HTTP 302 over HTTPS and redirected to `/admin`.

The available DevOps connector does not expose a deployment event, deployed release SHA, or application version for site 33, and no separate deployment workflow appeared in the repository's post-merge workflow list. Therefore the code deployment itself is not independently confirmed by this stage; live verification should confirm the running application contains commit `942aeff…` and exercise `/api/v1/mcp`.

## Handoff

Merge and post-merge CI passed. Downstream live verification owns confirmation of the production release contents and Sentry/query behavior.
