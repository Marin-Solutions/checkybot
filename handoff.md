Live verification completed for PR #461 / release commit `942aeff475c29c21e5b80228ecf83f1fdac51d2d`.

- Production health checks passed: `/` redirected to `/admin`, `/up` returned 200, and `/api/v1/mcp` is present and POST-only.
- Authenticated Checkybot checks passed for Scrappa: project sync is fresh, website `current_issues` is empty, and recent scheduled website/API runs are healthy.
- Sentry issue #21370 / `CHECKYBOT-6G` last occurred at 2026-07-17 10:54:12 UTC, before the 22:35:47 UTC merge; no post-merge event was observed.
- The separate #23161 / `CHECKYBOT-6S` monitor-api slow query was not treated as this remediation’s regression.
- Full project-4 `list_checks` exceeded the connector’s fixed 10-second limit because the project has 117 checks; smaller production projects passed. The available DevOps connector exposes no deployed SHA, so exact release-content confirmation is limited to the authenticated live behavior and app version evidence.

See [docs/live-verification.md](docs/live-verification.md) for the complete evidence.
