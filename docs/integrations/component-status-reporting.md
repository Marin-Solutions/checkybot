# Package Component Status Reporting

The Laravel package declaration sync and runtime aggregate status reporting are separate operations.

Consumers must install:

```bash
composer require marin-solutions/checkybot-laravel:^0.2.0
```

## Endpoint

`POST /api/v1/projects/{projectId}/components/{componentKey}/status`

The request must use the project API key as a Bearer token and a JSON body with exactly these fields:

```json
{
  "status": "healthy|warning|failure",
  "observed_at": "2026-07-31T12:34:56+00:00",
  "message": "A short safe status message.",
  "metrics": {"due": 12}
}
```

`projectId` must resolve to the API-key owner's project. `componentKey` must match an active, package-sourced component already created by `/components/sync`; the status endpoint does not create or declare components.

`observed_at` must be RFC3339 UTC (`Z` or `+00:00`) and cannot be in the future. Messages are non-empty, control-character-free, and at most 500 bytes. Metrics contain at most 20 scalar values using the allowlist `active`, `configured_pairs`, `count`, `coverage_percent`, `due`, `duration_ms`, `error_count`, `failed`, `failure_count`, `failure_streak`, `healthy`, `latency_ms`, `missing_pairs`, `oldest_overdue_age_minutes`, `overdue`, `stale_claims`, `success_count`, `total`, `unique_keywords`, and `warning`. Numeric values are non-negative integers or finite numbers no greater than 1,000,000,000; booleans are also accepted.

The server stores each accepted observation in `project_component_heartbeats` with `event=status`, updates the latest component snapshot, and maps client `failure` to Checkybot `danger`. Older observations remain history but cannot overwrite a newer live snapshot.

`/api/v1/projects/{projectId}/components/sync` remains declaration-only. Runtime `status`, `message`, `metrics`, and `observed_at` fields continue to be rejected there.
