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
- `GET /control/projects/{project}`

`{project}` accepts either the numeric project id or the stable `package_key`.

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
      "summary": "API heartbeat is degraded with HTTP status 404.",
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

`GET /control/projects/{project}/checks` returns package-managed API checks, website checks, and project components in one list. Component rows use `"type": "component"` and include delivery state, declared heartbeat interval, stale threshold/timestamps, current metrics, and the latest heartbeat as `latest_result`. Check rows also include `supports_run`, `diagnostic_queued`, `diagnostic_queued_at`, and `latest_diagnostic_result`; API and website rows can be triggered through diagnostic run endpoints, while component rows report package-sent heartbeat evidence and are not directly runnable from the control API.

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
      "last_heartbeat_at": null,
      "stale_at": null,
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

These endpoints execute synchronously and return the fresh result payload immediately for API and website checks. Stored check configuration is respected for HTTP method, timeout, expected status, and assertions. Triggered runs are diagnostic: they are appended to run history, but they do not move live status, alert subscribers, or appear in latest failure feeds. Components are heartbeat-driven and show `supports_run: false` in `list_checks`.

Single-check run triggers accept optional `type=api` or `type=website` in the query string or JSON body. The type is required when an API check and website check share the same package key so the control API cannot trigger the wrong surface.

### Recent results and latest failures

- `GET /control/runs?project={project}&limit={1..100}`
- `GET /control/projects/{project}/runs?limit={1..100}`
- `GET /control/failures?project={project}&limit={1..100}`
- `GET /control/projects/{project}/failures?limit={1..100}`

`/runs` returns recent check run results. `/failures` returns recent warning or danger results only, including component warning/danger heartbeats with their metric evidence when a component is the latest failing surface.

## MCP Endpoint

- `POST /api/v1/mcp`
- Auth is the same bearer API key used for the REST control API.
- Transport is authenticated JSON-RPC over HTTP POST.

### MCP Tools

- `me`
- `list_projects`
- `get_project`
- `list_checks`
- `upsert_check`
- `disable_check`
- `trigger_run`
- `latest_failures`

Arguments:

- `get_project`: `{ "project": "scrappa" }`
- `list_checks`: `{ "project": "scrappa" }`
- `upsert_check`: `{ "project": "scrappa", "key": "maps-search", "name": "Maps search", "url": "/api/google-maps/search", ... }`
- `upsert_check` website: `{ "project": "scrappa", "key": "marketing-site", "type": "website", "check_types": ["uptime", "ssl"], "name": "Marketing site", "url": "/status", "schedule": "10m" }`
- `disable_check`: `{ "project": "scrappa", "check": "maps-search" }`
- `trigger_run`: `{ "project": "scrappa" }` or `{ "project": "scrappa", "check": "maps-search", "type": "api" }`
- `latest_failures`: `{ "project": "scrappa", "limit": 10 }`

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

- The control surface manages package-managed API checks, website checks, and component visibility. Component definitions and heartbeats are still package-driven; the control API exposes their status and evidence but does not create or manually run component heartbeats.
- Run triggers are synchronous HTTP calls. There is no async job dispatch or run queue surface yet.
- The MCP endpoint is a focused tool surface, not a full general-purpose Checkybot admin API.
- Result listing is limit-based only. Cursor pagination can be added later if Mimir needs deeper history windows.
