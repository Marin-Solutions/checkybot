# Deploy

This repository is deployed through Ploi. The repo currently does not contain a `.ploi.json`, so agents should deploy by explicit Ploi server and site ids instead of relying on project auto-detection.

Keep this file up to date when deployment environments, Ploi site ids, database ids, or production safety rules change.

## Environments

| Environment | URL | Ploi server id | Ploi site id | Database |
| --- | --- | ---: | ---: | --- |
| Staging | `https://staging.checkybot.com` | `79201` | `313325` | `staging` / id `249720` |
| Production | `https://checkybot.com` | `79201` | `244469` | `checkybot` / id `199162` |

## Standard Deployment Flow

1. Inspect the local diff and confirm the intended branch is ready.
2. Run the relevant local checks before deploying:
   - `composer test` if available, otherwise `vendor/bin/pest`
   - `npm run build`
3. Deploy staging first through Ploi MCP:
   - server id: `79201`
   - site id: `313325`
4. After staging deployment completes, verify the staging site:
   - Load `https://staging.checkybot.com`.
   - Check the specific user flows affected by the change.
   - Check deployment logs if Ploi reports a non-success status.
   - If subagents are available, use an independent verification pass for browser checks or focused regression testing.
5. Ask for explicit approval before deploying production.
6. Deploy production through Ploi MCP only after approval:
   - server id: `79201`
   - site id: `244469`
7. After production deployment completes, verify `https://checkybot.com` and check logs if anything looks unhealthy.

## Ploi MCP Commands

Use these Ploi MCP operations when available:

- Check sites: `list_sites` with server id `79201`.
- Check databases: `list_databases` with server id `79201`.
- Deploy staging: `deploy_site` with server id `79201` and site id `313325`.
- Deploy production: `deploy_site` with server id `79201` and site id `244469`.
- Check deployment/site logs: `get_site_logs` with server id `79201` and the relevant site id.

Do not deploy production automatically when the user asks for a generic deploy. Generic deploy requests should go to staging first unless the user explicitly says production.

Keep Ploi webhook tokens out of repository files.

## Database Safety

- Production database id: `199162`, name `checkybot`.
- Staging database id: `249720`, name `staging`.
- Prefer read-only production database access for inspection, exports, and diagnostics.
- Do not run destructive production database commands manually.
- Production migrations should only run as part of the reviewed Ploi deployment flow.
- If a future task requires production data in staging, create a backup or copy first, use read-only production access where possible, and get explicit approval before replacing staging data.
