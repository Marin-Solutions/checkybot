# Inventar: Check-Ausführung in Checkybot

Stand der Code-Lesung: Laravel auf `master`. Dieses Dokument beschreibt nur die drei Check-Queues, die der Go-Dienst übernimmt. Keine Kunden-URLs, keine Secrets.

## Was Go übernimmt

| Queue | Auslöser | Job | Kadenz |
|---|---|---|---|
| `log-website` | `website:log-uptime-ssl` jede Minute | `LogUptimeSslJob` | Website mit `uptime_check` und fälligem `uptime_interval` (1, 5, 10, 15, 30, 60, 360, 720, 1440 Minuten) |
| `ssl-check` | `ssl:check` jede Minute | `CheckSslExpiryDateJob` | SSL-only-Seiten nach Intervall, plus Erinnerungstage für alle `ssl_check`-Seiten |
| `api-monitor` | `monitor:check-apis` jede Minute | `RunScheduledApiMonitorJob` | aktivierte `monitor_apis`, deren `package_interval` fällig ist |

Zusätzlich manuell, gleiche Jobs bzw. `RunApiMonitorDiagnosticJob`: Filament und `CheckybotControlService` setzen `diagnostic_queued_at` und dispatchen auf dieselben Queues. Horizon: `supervisor-1` nur `default` (Timeout 60s, 3 Prozesse), `supervisor-4` nur `ssl-check` (60s, 3), `supervisor-2` nur `log-website` (60s, 3), `supervisor-3` nur `api-monitor` (Timeout 480s, 4). `ssl-check` liegt auf einem eigenen Supervisor, damit der Fallback pausiert werden kann ohne die `default`-Queue. Job-`tries` ist 1.

Der Scheduler steht in `routes/console.php`. `withoutOverlapping` gilt für `monitor:check-apis`, nicht für die beiden Website-Commands.

## Fälligkeit

Anker ist `latest_scheduled_result_at`, gesetzt beim Anlegen eines geplanten Ergebnis-Rows (`WebsiteLogHistory` bzw. `MonitorApiResult`, nicht bei `is_on_demand`). Fällig, wenn der Anker leer ist oder `Anfang der Anker-Minute + Intervall <= Anfang der aktuellen Minute`. API-Intervalle: `5m`, `2h`, `30s` (Sekunden werden auf Minuten aufgerundet) und das Legacy-Format `every_N_minutes`. Leeres oder ungültiges API-Intervall gilt als fällig, sobald der Monitor aktiv ist. Ungültige Intervalle werden geloggt, der Check läuft trotzdem.

SSL-Job zusätzlich an Erinnerungstagen: Ablaufdatum leer, bereits abgelaufen, oder noch 14, 7, 3, 2, 1 oder 0 Tage. Seiten mit Uptime-Check bekommen an diesen Tagen keinen zweiten Health-Row vom SSL-Job (`recordSslOnlyHealth` bricht ab), aber Zertifikatsdatum und Erinnerungsmail laufen dort.

## HTTP-, TLS- und DNS-Verhalten

Uptime (`LogUptimeSslJob`), nur wenn `uptime_check`:

- GET der hinterlegten URL
- Timeout 10s, Connect-Timeout 5s
- `retry(2, 1000, throw: false)`: zwei Versuche, 1s Pause. Wiederholt wird bei Verbindungsfehler und bei jedem Nicht-2xx. Der letzte HTTP-Status wird gespeichert, nicht geworfen
- TLS-Prüfung aus (`withoutVerifying`). Ein schlechtes Zertifikat macht die Seite nicht down, solange HTTP antwortet
- Redirects: Guzzle-Standard, höchstens 5. Eine Schleife endet als Transportfehler, HTTP-Code 0
- 5xx und Code 0 sind `danger`, 4xx ist `warning`, sonst `healthy`
- Transportklassen aus der cURL-Meldung: DNS (Code 6), Timeout (28), TLS (35, 51, 58, 59, 60, 77, 80, 83, 90), Connection (7, 52, 56), sonst unknown. Zusammenfassung kommt aus `UptimeTransportError::summary`, nicht aus dem HTTP-Text
- `speed` ist die gerundete Wandzeit in Millisekunden

SSL (`SslCertificateService` über Spatie, Defaults):

- TLS-Handshake, Port aus der URL oder 443, SNI an, `verify_peer` und `verify_peer_name` an, Timeout 30s
- Abgelaufenes Zertifikat, falscher Hostname oder nicht vertrauenswürdige Kette: Download schlägt fehl, Ablaufdatum bleibt leer, Status `danger` mit dem Text, dass vor dem Auslesen kein Datum gelesen werden konnte. Es wird nicht „vor N Tagen abgelaufen“ geschrieben, weil der Handshake das Zertifikat nicht hergibt
- Lesbares Datum in der Vergangenheit: `danger`. 14 Tage oder weniger: `warning`. Sonst `healthy`
- Tagesgrenzen in der App-Zeitzone (`APP_TIMEZONE`, Produktion UTC)

API (`MonitorApis::testApi`):

- Methode, Header, Body (`json`, `form`, `raw`) vom Monitor. Header und Body liegen mit Laravel `Crypt` (AES-256-CBC, `APP_KEY`) in der DB, Umschlag `{"encrypted":"..."}`. Go entschlüsselt nur zum Senden und maskiert sensible Header bevor etwas gespeichert wird
- TLS-Prüfung an (anders als Uptime)
- Timeout und Retries aus den Spalten, bei geplanten Läufen gedeckelt auf 90s und 3 Versuche (`config/monitor.php`). `retry(n)` bedeutet n Versuche insgesamt, Pause 1s, auch bei 4xx/5xx. `throw: false` behält den letzten HTTP-Status
- Redirects wie Guzzle, maximal 5
- Assertions: `expected_status`, sonst `type_check`, `value_compare` (inkl. `contains` für Keywords), `exists`, `not_exists`, `array_length`, `regex_match`, oder ein einzelner `data_path` wenn keine aktiven Assertions gespeichert sind. Pfad mit Punktnotation. Keyword fehlt bei sonst 2xx: `warning`. HTTP 0: `danger`. Weicht der Code vom erwarteten Status ab: 5xx `danger`, sonst `warning`. Antwortzeit über `max_response_time_ms`: `warning`
- Fehlerbody nur wenn `save_failed_response` und der Lauf nicht `healthy` ist

## Was geschrieben wird

Kein Incident-Insert. `incidents` im Admin ist eine Lese-Union, kein Schreibziel der Checks.

`website_log_history`: `website_id`, `ssl_expiry_date`, `http_status_code`, `speed`, `status`, `summary`, `transport_error_type`, `transport_error_message`, `transport_error_code`, `run_source` (`scheduled` oder `on_demand`), `is_on_demand`.

`websites`: `current_status`, `status_summary`, bei SSL `ssl_expiry_date` und bei Tageswechsel `ssl_expiry_reminder_sent_at = null`. Geplante Läufe setzen `latest_scheduled_result_at`. On-Demand leert `diagnostic_queued_at`. Die Status-Zeile wird unter `lockForUpdate` gelesen, damit der vorherige Status für die Benachrichtigung stimmt. Das SSL-Datum wird nur geschrieben, wenn `updated_at` und das bisherige Ablaufdatum noch zum Stand vom Job-Start passen.

`monitor_api_results`: Erfolg, Zeiten, `http_code`, `failed_assertions`, optional Body, Status, Summary, Transportfelder, maskierte Header, `run_source`, `is_on_demand`.

`monitor_apis`: `current_status`, `status_summary`, `latest_scheduled_result_at`, bei On-Demand `diagnostic_queued_at = null`. Ebenfalls unter Row-Lock.

Kombinierter Website-Status: der schlechtere Wert aus HTTP und SSL (`healthy` < `warning` < `danger`). Die Summary führt mit SSL, wenn das Zertifikat allein den schlechteren Status verursacht.

## Statuswechsel und Benachrichtigungen

Gleich für Website und API:

- neuer Status `warning` oder `danger` und verschieden vom vorherigen: Event `heartbeat`
- neuer Status `healthy` und vorher `warning` oder `danger`: Event `recovered`
- gleicher Status: keine Nachricht

`HealthEventNotificationService` prüft Snooze (`silenced_until`), sammelt Kanal-Einstellungen (Website/API, Projekt, global) für `website_check` bzw. `api_monitor` und `all_check`, und sendet Mail plus Webhook. Ein Kanalfehler wird pro Kanal geloggt. SSL-Erinnerungsmails (14/7/3/2/1/0 Tage und danach täglich, gedrosselt auf eine pro Tag, nicht während Snooze) bleiben in `CheckSslExpiryDateJob` bzw. dem ausgelagerten Reminder-Service. Fallback ohne Kanal ist die Mail an den Website-Besitzer.

### Warum Go die Zustellung nicht nachbaut

Mail, Webhook, Snooze, Kanalauswahl und die SSL-Erinnerung sind schon in Laravel getestet. Eine zweite Implementierung würde falsche oder doppelte Alarme riskieren. Go schreibt dieselben Status-Zeilen und legt in derselben Transaktion eine Zeile in `checker_outbox` an. Der Laravel-Command `checker:drain-outbox` (jede Minute, plus direkt nach dem Commit nicht nötig für die Korrektheit) ruft `notifyWebsite`, `notifyApi` und den Reminder-Service auf. Fällt der Drain aus, bleiben die Zeilen liegen und der nächste Lauf holt sie nach. Die Check-Queues müssen dafür nicht laufen.

## Doppelte Ausführung

Laravel nimmt den Check über `checker_locks` (`READ COMMITTED`, `SELECT ... FOR UPDATE`, Übernahme nur wenn abgelaufen). Go nimmt denselben Schlüssel (`uptime:{id}`, `ssl:{id}`, `api:{id}`). Wer den Lock nicht bekommt, schreibt nichts. Steht `uptime_owner`, `ssl_owner` oder `api_owner` in `checker_settings` auf `go`, dispatchen die drei Artisan-Commands nicht mehr, und ein schon wartender Job kehrt sofort zurück ohne `diagnostic_queued_at` zu leeren. Go übernimmt dann auch die manuellen Läufe. `process_mode=shadow` schreibt keine Live-Zeilen, auch wenn ein Owner schon `go` ist.

## Was in Laravel bleibt

Filament-Admin, alle CRUD- und API-Routen, Projekte, Paket-Sync, SEO, Outbound-Links, Server-Regeln, Backups, Snooze, Notification-Einstellungen, Health-Summaries, der Queue `default`, und die Zustellung von Alarmen. Horizon bleibt installiert. Nach der Umstellung werden nur die Supervisor der übernommenen Queues pausiert, nicht gelöscht, für mindestens 48 Stunden.
