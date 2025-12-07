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


## Alpha preview
Diese Branch ist bereit für Review und enthält das initiale Projektgerüst.

## Changelog

### Security and Portability Fixes (2025-12-07)
- **Security**: Removed hardcoded API_KEY from `clients/clientd.py`. API keys and secrets must now be provided via environment variables or `/etc/internetcafe-clientd.conf`.
- **Portability**: Updated `clients/clientd.service` to use portable paths (`/opt/clientd`) and service user (`clientd`) instead of user-specific paths.
- **Bug Fix**: Fixed state handling in clientd to properly normalize states to lowercase (server sends 'stop'/'off', not 'STOP'/'OFF').
- **Bug Fix**: Fixed shell command injection vulnerability in `apply_state()` - replaced unsafe `$(whoami)` shell expansion with safe Python user detection.
- **Configuration**: Updated `clients/clientd.conf` to use empty placeholders for secrets with clear warnings not to commit real secrets.
- **Installation**: Updated `scripts/install_client.sh` to generate proper config files with API_KEY placeholders and EnvironmentFile support.

**Important**: After updating, move all secrets out of the repository and into `/etc/internetcafe-clientd.conf` with proper file permissions (chmod 600). Never commit real API keys, passwords, or tokens to version control.
