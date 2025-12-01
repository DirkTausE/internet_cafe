# Internetcafe - Beispielprojekt

Dieses Repository enthält Beispiel-Dateien und eine Struktur für ein Internetcafé-Verwaltungssystem
(Backend: PHP + MariaDB, Clients: Python3).

Wichtige Hinweise:
- Keine echten Zugangsdaten in `config.php.example` eintragen.
- Vor dem Einsatz: `cp config.php.example config.php` und passwort/keys lokal ausfüllen.
- `storage/` enthält Laufzeitdaten (invoices, scans) und ist in `.gitignore` aufgeführt.

Verzeichnisstruktur (Kurz):
- web/        -> PHP Webanwendung (Dashboard, API, Templates)
- clients/    -> Client-Skripte (clientd, greeter)
- db/         -> SQL Schema & Seed
- docs/       -> Installationsanleitungen
- scripts/    -> Hilfsskripte
- storage/    -> Laufzeitdaten (nicht versioniert)

Siehe auch REPO_STRUCTURE.md für Details.
