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

## CHANGELOG

### Security and Portability Improvements (2025-12-08)

**clientd.py:**
- Removed hardcoded API_KEY and secrets from source code
- Configuration now loaded from environment variables (API_KEY, CLIENTD_SECRET) or /etc/internetcafe-clientd.conf
- Added state normalization to handle case-insensitive state values and synonyms
- Fixed shell expansion security vulnerability - replaced os.system with subprocess.run
- Improved error handling for loginctl commands
- Added load_conf() helper function for safe config file parsing

**clientd.service:**
- Updated to use portable paths (/opt/clientd) instead of user-specific paths
- Changed service user from 'surfer' to 'clientd' for better security isolation
- Added EnvironmentFile directive to load secrets from /etc/internetcafe-clientd.conf
- Added comments for maintainer guidance

**clientd.conf:**
- Replaced hardcoded placeholder secrets with commented examples
- Empty values for secrets by default to prevent accidental commits

**install_client.sh:**
- Updated to use /opt/clientd as standard installation directory
- Creates dedicated system user 'clientd' for service isolation
- Generates and stores secrets in /etc/internetcafe-clientd.conf (only with user consent)
- Sets proper ownership (clientd:clientd) for /opt/clientd and /var/lib/clientd
- Updated systemd service template to use EnvironmentFile

**Important for maintainers:**
- Never commit real secrets or API keys to the repository
- All secrets should be provided via environment variables or /etc/internetcafe-clientd.conf
- The /etc/internetcafe-clientd.conf file is not tracked in git and is created during installation
- Test configuration with: `python3 -m py_compile clients/clientd.py`
