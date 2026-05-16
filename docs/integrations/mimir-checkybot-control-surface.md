# Checkybot Control Surface for Mimir

This document defines the machine-facing Checkybot surface that Mimir should integrate with for package-managed API checks.

## Authentication

- All control API and MCP requests require `Authorization: Bearer <CHECKYBOT_API_KEY>`.
- API keys are stored hashed at rest. The plaintext key is only shown once when created in the Filament admin.
- Response payloads redact sensitive stored headers such as `Authorization`, `X-Api-Key`, and `*token*`.
- Example values in this document use placeholders or `[redacted]`. Do not log raw bearer tokens in clients.

## REST Endpoints

All endpoints below live under `/api/v1` and require the bearer API key.

### Identity and health

- `GET /control/me`

Example response:

```json
{
  "data": {
    "authenticated": true,
    "server_time": "2026-04-21T12:00:00Z",
    "user": {
      "id": 1,
      "name": "Mimir Agent",
      "email": "ops@example.com"
    },
    "api_key": {
      "name": "Mimir"
    },
    "app": {
      "name": "CheckyBot",
      "url": "https://checkybot.example.com",
      "version": "8a72e1530e1b"
    }
  }
}
```

### Projects

- `GET /control/projects`
- `POST /control/projects`
- `GET /control/projects/{project}`

`{project}` accepts either the numeric project id or the stable `package_key`.

`POST /control/projects` creates or updates a project by stable `key` and `environment`, scoped to the API key owner. `identity_endpoint` defaults to `base_url` when omitted, so later package sync payloads attach to the same project.

Example project creation request:

```json
{
  "key": "convertr",
  "name": "Convertr",
  "environment": "production",
  "base_url": "https://api.convertr.example",
  "repository": "Marin-Solutions/convertr"
}
```

Example project detail response:

```json
{
  "data": {
    "id": 12,
    "key": "scrappa",
    "name": "Scrappa",
    "environment": "production",
    "base_url": "https://api.scrappa.example",
    "repository": "Marin-Solutions/scrappa",
    "checks_count": 4,
    "enabled_checks_count": 3,
    "disabled_checks_count": 1,
    "created_at": "2026-04-20T08:12:00Z",
    "last_synced_at": "2026-04-21T11:59:00Z",
    "updated_at": "2026-04-21T11:59:00Z",
    "status_counts": {
      "healthy": 2,
      "warning": 1,
      "disabled": 1
    },
    "latest_failure": {
      "id": 991,
      "project": {
        "id": 12,
        "key": "scrappa"
      },
      "check": {
        "id": 55,
        "key": "maps-search",
        "name": "Maps search"
      },
      "success": false,
      "status": "warning",
      "summary": "API check is degraded with HTTP status 404.",
      "http_code": 404,
      "response_time_ms": 142,
      "transport_error_type": null,
      "transport_error_message": null,
      "transport_error_code": null,
      "failed_assertions": [],
      "request_headers": {
        "Accept": "application/json",
        "Authorization": "[redacted]"
      },
      "response_headers": {
        "content-type": "application/json",
        "x-request-id": "req_123"
      },
      "response_body": {
        "error": "not_found",
        "trace_id": "trace_123"
      },
      "checked_at": "2026-04-21T11:58:12Z",
      "created_at": "2026-04-21T11:58:12Z"
    }
  }
}
```

### Checks

- `GET /control/projects/{project}/checks`
- `PUT /control/projects/{project}/checks/{check}`
- `PATCH /control/projects/{project}/checks/{check}/disable`

`{check}` is the stable package-managed check key. Disabling never deletes the definition or result history.

`GET /control/projects/{project}/checks` returns package-managed API checks, website checks, and project component declarations in one list. Component rows use `"type": "component"` and include delivery state plus declared interval. Component health is derived from linked active API and website checks; packages do not send component heartbeat, stale, status, or metric observations. Check rows also include `supports_run`, `diagnostic_queued`, `diagnostic_queued_at`, and `latest_diagnostic_result`; API and website rows can be triggered through diagnostic run endpoints, while component rows are not directly runnable from the control API.

API checks are the default upsert type. Pass `"type": "website"` to create or update a package-managed website check. Website upserts accept `check_types` with `"uptime"`, `"ssl"`, or both; when omitted, Checkybot preserves the existing enabled website check types or defaults new website rows to uptime monitoring.

Example API upsert request:

```json
{
  "name": "Maps search",
  "method": "GET",
  "url": "/api/google-maps/search",
  "headers": {
    "Accept": "application/json",
    "Authorization": "Bearer [redacted]"
  },
  "expected_status": 200,
  "timeout_seconds": 15,
  "schedule": "every_5_minutes",
  "enabled": true,
  "assertions": [
    {
      "type": "json_path_exists",
      "path": "$.data"
    }
  ]
}
```

Example website upsert request:

```json
{
  "type": "website",
  "check_types": ["uptime", "ssl"],
  "name": "Marketing site",
  "url": "/status",
  "schedule": "10m",
  "enabled": true
}
```

Example API upsert response:

```json
{
  "message": "Check created.",
  "data": {
    "created": true,
    "check": {
      "id": 55,
      "key": "maps-search",
      "type": "api",
      "name": "Maps search",
      "url": "https://api.scrappa.example/api/google-maps/search",
      "method": "GET",
      "request_path": "/api/google-maps/search",
      "expected_status": 200,
      "timeout_seconds": 15,
      "schedule": "every_5_minutes",
      "enabled": true,
      "supports_run": true,
      "diagnostic_queued": false,
      "diagnostic_queued_at": null,
      "status": "unknown",
      "status_summary": null,
      "last_synced_at": "2026-04-21T11:59:00Z",
      "headers": {
        "Accept": "application/json",
        "Authorization": "[redacted]"
      },
      "assertions": [
        {
          "path": "data",
          "type": "exists",
          "expected_type": null,
          "comparison_operator": null,
          "expected_value": null,
          "regex_pattern": null,
          "sort_order": 1,
          "active": true
        }
      ],
      "latest_result": null,
      "latest_diagnostic_result": null,
      "updated_at": "2026-04-21T11:59:00Z"
    }
  }
}
```

### Run triggers

- `POST /control/projects/{project}/runs`
- `POST /control/projects/{project}/checks/{check}/runs`

These endpoints execute API checks synchronously and queue website checks. Stored check configuration is respected for HTTP method, timeout, expected status, and assertions. Triggered runs are appended to run history, update live status, and use `run_source=on_demand`. Components are declarations and show `supports_run: false` in `list_checks`.

Single-check run triggers accept optional `type=api` or `type=website` in the query string or JSON body. The type is required when an API check and website check share the same package key so the control API cannot trigger the wrong surface.

### Recent results and latest failures

- `GET /control/runs?project={project}&limit={1..100}`
- `GET /control/projects/{project}/runs?limit={1..100}`
- `GET /control/failures?project={project}&limit={1..100}`
- `GET /control/projects/{project}/failures?limit={1..100}`
- `GET /control/issues?project={project}&type={all|api|website|component}&limit={1..100}`

`/runs` returns recent API and website check run results. `/failures` returns recent warning or danger API and website results only.

`/issues` returns current dashboard status issues across API monitors, website checks, and components. It accepts `statuses[]=warning|danger|pending|unknown` and `exclude[]` query parameters. For example, `/control/issues?project=scrappa&type=api&exclude[]=google%20search` lists unhealthy API monitors while omitting a known work-in-progress check.

## MCP Endpoint

- `POST /api/v1/mcp`
- Auth is the same bearer API key used for the REST control API.
- Transport is authenticated JSON-RPC over HTTP POST.

### MCP Tools

- `me`
- `list_projects`
- `create_project`
- `get_project`
- `list_checks`
- `upsert_check`
- `disable_check`
- `trigger_run`
- `get_run_batch`
- `recent_runs`
- `latest_failures`
- `current_issues`
- `list_notification_channels`
- `upsert_notification_channel`
- `delete_notification_channel`
- `test_notification_channel`
- `list_notification_settings`
- `upsert_notification_setting`
- `delete_notification_setting`
- `test_notification_setting`

Arguments:

- `get_project`: `{ "project": "scrappa" }`
- `list_checks`: `{ "project": "scrappa" }`
- `create_project`: `{ "key": "convertr", "name": "Convertr", "environment": "production", "base_url": "https://api.convertr.example", "repository": "Marin-Solutions/convertr" }`
- `upsert_check`: `{ "project": "scrappa", "key": "maps-search", "name": "Maps search", "url": "/api/google-maps/search", ... }`
- `upsert_check` website: `{ "project": "scrappa", "key": "marketing-site", "type": "website", "check_types": ["uptime", "ssl"], "name": "Marketing site", "url": "/status", "schedule": "10m" }`
- `disable_check`: `{ "project": "scrappa", "check": "maps-search" }`
- `trigger_run`: `{ "project": "scrappa" }` or `{ "project": "scrappa", "check": "maps-search", "type": "api" }`
- `recent_runs`: `{ "project": "scrappa", "limit": 10 }`
- `latest_failures`: `{ "project": "scrappa", "limit": 10 }`
- `current_issues`: `{ "project": "scrappa", "type": "api", "exclude": ["google search"] }`
- `upsert_notification_channel`: `{ "title": "Ops webhook", "method": "POST", "url": "https://hooks.example.test/...", "request_body": { "message": "{message}", "description": "{description}" } }`
- `upsert_notification_setting`: `{ "inspection": "API_MONITOR", "channel_type": "WEBHOOK", "notification_channel_id": 12, "active": true }`

Example `tools/call` request:

```json
{
  "jsonrpc": "2.0",
  "id": 2,
  "method": "tools/call",
  "params": {
    "name": "upsert_check",
    "arguments": {
      "project": "scrappa",
      "key": "maps-search",
      "name": "Maps search",
      "url": "/api/google-maps/search"
    }
  }
}
```

## Mimir Configuration Example

Use Checkybot as a streamable HTTP MCP server in Mimir:

```json
{
  "mcpServers": {
    "checkybot": {
      "type": "streamable-http",
      "url": "https://checkybot.example.com/api/v1/mcp",
      "headers": {
        "Authorization": "Bearer ${CHECKYBOT_API_KEY}"
      }
    }
  }
}
```

For direct REST usage from Mimir-side code:

```bash
curl https://checkybot.example.com/api/v1/control/projects/scrappa/checks \
  -H 'Authorization: Bearer '"$CHECKYBOT_API_KEY"
```

## Known Limitations Left Intentionally for Later

- The control surface manages package-managed API checks, website checks, and component visibility. Component definitions are package-driven, but component health is derived from active child checks that Checkybot executes.
- Run triggers are synchronous HTTP calls. There is no async job dispatch or run queue surface yet.
- The MCP endpoint is a focused tool surface, not a full general-purpose Checkybot admin API.
- Result listing is limit-based only. Cursor pagination can be added later if Mimir needs deeper history windows.
