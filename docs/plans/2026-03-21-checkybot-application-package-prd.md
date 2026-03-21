# PRD: Application-First Checkybot Laravel Package

### Problem Statement
Checkybot already has an early Laravel package integration path, but the current experience is incomplete and inconsistent for real-world adoption. Today, the package can define uptime, SSL, and API checks and sync them to Checkybot, but the product model is still centered around projects, not installed applications. The setup flow is unclear, the ownership model is not obvious to users, and the current documentation does not provide a reliable, end-to-end onboarding path from the Checkybot web app to a working package installation in another Laravel application.

From the user perspective, installing the package should be simple: create or start from an application in Checkybot, copy a setup snippet, install the package, set `.env` values, define checks in `routes/checkybot.php`, add one scheduler line, and have the application register itself automatically into the correct Checkybot account. The same package should also support application-reported health data, not just externally executed uptime/API/SSL checks. Users need to define component health locally with a fluent DSL, report raw metrics and computed status to Checkybot, and view everything in one application dashboard. Checkybot should then remain the central admin and notification surface for all monitored Laravel applications.

### Solution
Introduce `Applications` as the new primary monitoring object in Checkybot and evolve the Laravel package into an application-first integration layer. Applications belong to a Checkybot account, are created manually through a guided setup flow in the Checkybot web app or automatically through package-first bootstrap, and are identified operationally by `identity_endpoint + environment`. The guided flow creates an application shell with `name` and `environment`, then generates a full copy-paste installation snippet containing the Checkybot base URL, account API key, and a hidden `CHECKYBOT_APP_ID` for first-install pairing. Package-first bootstrap remains supported for users who only install the package and configure `.env`.

The Laravel package remains source-of-truth for package-managed monitoring definitions in `routes/checkybot.php`. It continues to support external `uptime`, `ssl`, and `api` checks, and adds a new application-health DSL for named components such as `database`, `queue`, and `proxies`. Each component can emit numeric or boolean metrics, evaluate local rules into `healthy`, `warning`, or `danger`, and send raw metrics plus computed status to Checkybot. A single scheduled package command runs every minute, automatically registers the application if needed, syncs external checks and component schema, sends heartbeats only for due items, and allows Checkybot to detect stale components when expected heartbeats stop arriving. Overall application status rolls up using worst-component-wins.

### User Stories
1. As an operator, I want to create an application shell in Checkybot with only a name and environment, so that setup stays fast and low-friction.
2. As an operator, I want Checkybot to generate a full copy-paste setup snippet, so that I do not have to assemble install steps from multiple docs.
3. As a Laravel developer, I want to install the package, set `.env` values, and have the app auto-register into my Checkybot account, so that onboarding works even without manual backend setup.
4. As a Laravel developer, I want Checkybot package traffic to authenticate with a normal account API key, so that credential management is straightforward.
5. As a platform owner, I want guided setup to include a hidden application ID for first install, so that package registration attaches to the intended application shell without duplicate records.
6. As a platform owner, I want package-first bootstrap to auto-create an application when none exists, so that Checkybot is still easy to adopt from code-first workflows.
7. As a developer, I want application identity to default to `APP_URL + APP_ENV`, with an override env var available, so that identity is stable but flexible.
8. As a developer, I want external uptime, SSL, and API checks to stay supported, so that the new model extends the current package instead of replacing useful features.
9. As a developer, I want all package-managed monitoring definitions to live in `routes/checkybot.php`, so that the Checkybot integration remains discoverable and version-controlled.
10. As a developer, I want to define named health components in the package, so that I can report separate states for things like database, proxies, queue workers, or third-party dependencies.
11. As a developer, I want components to send raw metrics and computed status, so that Checkybot can show evidence, not just a color.
12. As a developer, I want a fluent threshold DSL like warning and danger rules, so that I can express health boundaries directly in code.
13. As a developer, I want numeric and boolean metrics in v1, so that common operational signals are easy to model.
14. As a developer, I want threshold evaluation to stay focused on one primary metric at a time in v1, so that the DSL remains simple and predictable.
15. As an operator, I want components to have their own intervals, so that expensive or slow-changing checks do not need to run as often as critical ones.
16. As an operator, I want one package command to run every minute and send only due items, so that host-app scheduling is simple.
17. As an operator, I want Checkybot to mark components stale and escalate them to danger when heartbeats stop arriving, so that silent failures do not look healthy.
18. As an operator, I want stale detection to be derived from the declared interval, so that timeout behavior is automatic in v1.
19. As an operator, I want the overall application status to reflect the worst component status, so that the dashboard highlights the most urgent reality.
20. As an operator, I want package-managed checks and components attached to the application record, so that all health data is visible in one place.
21. As an operator, I want Checkybot to keep time-series history for metrics and statuses, so that I can review trends and incidents over time.
22. As an operator, I want normal Checkybot notification rules to apply to warning, danger, and stale events, so that routing remains centralized.
23. As a developer, I want package-managed items removed from code to be pruned automatically from the active Checkybot config, so that code stays the source of truth.
24. As an operator, I want history for pruned items to remain archived and viewable, so that deleting a component from code does not erase operational history.
25. As an operator, I want an endpoint change to create a new application identity, so that identity remains deterministic and avoids silent takeover of an old record.

### Implementation Decisions
- Introduce a new `Application` domain model as the primary monitored entity and attach package-managed external checks plus package-managed health components to it.
- Preserve the current package capabilities for uptime, SSL, and API sync, but move the product model from project-centric to application-centric.
- Keep package definitions in `routes/checkybot.php`; extend the existing fluent API rather than introducing a second configuration style for health components.
- Add a guided setup flow in the Checkybot web app that creates an application shell with required fields `name` and `environment` only.
- Generate a setup snippet in Checkybot containing Composer install steps, publish steps, required `.env` values, and one scheduler line for `routes/console.php` or `Kernel`.
- Use account API keys as the package credential for registration, sync, and heartbeats.
- Unify package authentication so the runtime contract actually matches the documented Bearer API-key flow. The current mismatch between package docs and backend route middleware must be resolved as part of this work.
- Support two bootstrap paths:
- Guided flow: Checkybot pre-creates the application shell and issues a hidden `CHECKYBOT_APP_ID` used for first package registration.
- Package-first flow: the package auto-creates the application when no match exists for `identity_endpoint + environment`.
- Application identity defaults to `APP_URL + APP_ENV` and supports an override env var for the identity endpoint.
- Treat `identity_endpoint + environment` as the long-term matching key for auto-registration and reconciliation.
- If an application’s identity endpoint changes, create a new application record rather than mutating the old identity in place.
- Introduce a package-managed health component model beneath applications.
- Each component has a name, interval, primary metric, raw metric payload, computed severity, optional human-readable summary, and timestamps for last heartbeat and stale evaluation.
- Heartbeat intervals are per component; the package command runs every minute and sends only due checks/components.
- The package evaluates health locally and sends both raw metrics and computed status to Checkybot.
- Severity model in v1 is fixed to `healthy`, `warning`, and `danger`.
- DSL support in v1 covers numeric thresholds and boolean true/false rules against one primary metric at a time.
- Overall application status rolls up automatically using worst-component-wins.
- Removing a package-managed check or component from code prunes it from the active Checkybot schema, but archives its history rather than hard-deleting it.
- Schema/API changes should include:
- New application-centric sync and heartbeat endpoints or a unified application sync contract.
- Application registration contract including `app_id` fallback, `identity_endpoint`, `environment`, display metadata, and package version.
- Component heartbeat payload including component key, interval, severity, metric values, summary, and observed timestamp.
- External check sync contract updated so checks belong to applications instead of projects.
- Data-model changes should include:
- Application table/entity replacing or superseding project-as-package-target behavior.
- Package-managed component definitions.
- Time-series heartbeat/result storage for component metrics and status history.
- Archived state for deleted package-managed items whose history remains viewable.
- Major modules to build or modify:
- Checkybot application setup UI and install snippet generator.
- Backend API-key auth path for package traffic.
- Application registration and reconciliation service.
- Package sync service refactored from project-centric to application-centric behavior.
- Health component ingest, stale detection, and rollup services.
- Package DSL, scheduler command, due-item evaluator, and transport client.
- Application dashboard UI for current state, per-component health, checks, history, and archived items.

### Testing Decisions
- A good test asserts external behavior only: what the package sends, what Checkybot stores, what status is shown, when notifications trigger, and how stale/pruned items appear.
- Package tests should cover:
- guided bootstrap configuration generation assumptions where relevant to package inputs;
- application auto-registration;
- idempotent reconciliation against an existing application;
- identity matching with `APP_URL + APP_ENV` and endpoint overrides;
- external check sync during the scheduled heartbeat command;
- per-component due scheduling;
- local threshold evaluation for numeric and boolean metrics;
- heartbeat payload generation;
- pruning of removed checks/components from active schema.
- Backend tests should cover:
- guided setup flow and install snippet generation;
- API-key authorization for package endpoints;
- application creation from guided flow and package-first flow;
- correct matching by hidden `app_id` on first install and by `identity_endpoint + environment` afterward;
- attachment of external checks and components to applications;
- worst-component-wins rollup;
- stale detection derived from declared interval;
- archived history visibility after pruning;
- notification triggering through existing Checkybot rules.
- UI tests should cover:
- application creation screen;
- generated install snippet visibility;
- application dashboard current status;
- component list and status history;
- archived package-managed items;
- distinction between active and pruned items.
- Prior art already exists in the current codebase and should be reused where practical:
- project/package sync tests in `tests/Feature/Api/V1/ProjectChecksSyncTest.php`;
- current sync orchestration in `app/Services/CheckSyncService.php`;
- existing package sync command and fluent builders in `marin-solutions/checkybot-laravel`;
- existing API key management UI under Filament settings.

### Out of Scope
- Custom user-defined severity levels beyond `healthy`, `warning`, and `danger`.
- A general-purpose expression engine for cross-metric rules in v1.
- String metrics as first-class rule inputs in v1.
- Package-controlled notification routing or contact definitions.
- A zero-step fully automatic scheduler registration that requires no host-app schedule line.
- Preserving endpoint identity across endpoint changes; changed identity creates a new application.
- Full hard-delete of historical data for pruned package-managed items.
- Non-Laravel package ecosystems.
- Reworking all unrelated monitoring domains in Checkybot outside what is required to support application-first package integration.
