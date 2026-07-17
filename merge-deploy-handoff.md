Merge/deploy completed for PR #460.

- Merge commit: `05ddc65b08b11c728d1147a0b5463d4ae1a87d12`
- Merged: `2026-07-17T13:45:13Z`
- Staging active: `2026-07-17 13:46:18` (Ploi log `108756589`)
- Production active: `2026-07-17 13:45:43` (Ploi log `108756574`)
- Both deploy logs pulled the merge commit and ended with `Application deployed!`.
- Production current issues: empty; fresh scheduled website/API runs healthy.
- Sentry #21370 latest observed event predates deployment; no post-deploy event observed.
- Limitations: staging authenticated MCP key unavailable; production `list_checks` timed out at the connector’s 10-second limit.

Full evidence: `docs/release-handoff.md`.
