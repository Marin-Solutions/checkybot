# Vorher / Nachher

Gemessen mit `CHECKER_SSH=ploi@… bash checker/bench/measure.sh`. Keine URLs, keine Secrets. Der Go-Dienst läuft seit dem 27.09.2026 etwa 21:00 UTC. Die 60-Minuten-Fenster enthalten deshalb noch fast nur die alten Laravel-Läufe. Die kurzen Fenster darunter sind die Zeit, in der Go schon schreibt.

## Last und Ergebnisse

| Kennzahl | Vorher (Laravel, 60 min) | Nachher |
|---|---:|---:|
| Uptime-Ergebnisse | 16,9 / min (15 bis 22) | 19 / min in den letzten 3 min, 0 danger |
| API-Ergebnisse | 29,3 / min (16 bis 43) | 35 / min in den letzten 3 min (Nachholen) |
| Uptime-Verzug p50 / p95 | 6 s / 8 s | im 60-min-Fenster unverändert, Schnitt zu kurz für ein neues p95 |
| API-Verzug p50 / p95 | 11 s / 20 s | im 60-min-Fenster unverändert |
| Uptime danger | 0 % | 0 in den letzten 3 min |
| API danger / warning | 4,3 % / 3,5 % | 3,8 % / 6,6 % in den letzten 3 min |
| Uptime überfällig | 0 | 0 bei aktiven Seiten |
| Queue-Tiefe | 0, 0, 0, 0 | 0, 0, 0, 0 |
| Offene Outbox | - | 0 |

API-Warnungen lagen schon vorher im normalen Bereich. Ein Shadow-Unterschied (healthy gegen warning) betraf einen Monitor, der in den Tagen davor schon warning war.

## Speicher

| | Vorher | Nachher |
|---|---:|---:|
| Horizon-Worker | etwa 7 bis 8 Prozesse, 623 bis 682 MiB RSS | 4 Prozesse, 341 MiB RSS |
| Go-Checker | keiner | 1 Prozess, 21 MiB RSS, etwa 3 % CPU |
| RAM available | 8452 MB | 8337 MB |

`supervisor-2` (Uptime), `supervisor-3` (API) und `supervisor-4` (SSL) sind pausiert, nicht gelöscht. `supervisor-1` bedient weiter nur `default`.

## Shadow

| Art | gleich | abweichend |
|---|---:|---:|
| Uptime | 25 | 0 |
| SSL | 6 | 0 |
| API | 38 | 1 |

## Rollback

Siehe `checker/docs/rollback.md`. Kurz: `uptime_owner`, `ssl_owner` und `api_owner` auf `laravel`, `process_mode` auf `shadow`, dann `horizon:continue-supervisor` für supervisor-4, supervisor-2 und supervisor-3. Tabellen nicht löschen.

Beim ersten API-Versuch fehlte die Spalte `consecutive_count` in der Produktion. Es wurde nichts gespeichert, Laravel hat die API-Checks wieder übernommen, der Insert wurde korrigiert, danach liefen die API-Checks über Go.
