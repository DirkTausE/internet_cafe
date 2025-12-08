#### CHANGELOG / NOTES — Security & portability fixes (clientd)

- 2025-12-08: Fix clientd secrets and portability
  - clients/clientd.py: no hardcoded API keys or client secrets; reads CLIENTD_SECRET and API_KEY from environment variables or /etc/internetcafe-clientd.conf. Normalizes incoming states to canonical lowercase values (starting, frei, gast, pause, wartung, stop, off).
  - clients/clientd.service: portable default paths (/opt/clientd), User=clientd, Group=clientd and EnvironmentFile=-/etc/internetcafe-clientd.conf.
  - clients/clientd.conf: removed hardcoded secret; documented that secrets must not be committed to the repo.
  - scripts/install_client.sh: updated to install to /opt/clientd, create service user 'clientd', write /etc/internetcafe-clientd.conf only on the host (not stored in repo) and enable the systemd unit.
- IMPORTANT: Do not commit secrets to the repository. Provide secrets via /etc/internetcafe-clientd.conf or Environment variables (CLIENTD_SECRET, API_KEY).