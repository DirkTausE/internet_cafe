Initial project skeleton and examples

This PR adds an initial project skeleton and example files to the alpha-preview branch to provide a working development foundation. No production secrets are included. Please create a local config.php from config.php.example and never commit real passwords or keys.

Files added / updated (high level):
- .gitignore
- config.php.example
- README.md
- REPO_STRUCTURE.md
- web/ (index.php, api.php, db.php, header.php, footer.php, assets...)
- clients/ (clientd.py, greeter.py, greeter_minimal.py, clientd.service)
- db/ (schema.sql, seed_data.sql)
- docs/ (installation.md)
- server/etc/apache2/sites-available/internetcafe.conf
- scripts/ (organize.sh)
- Note: server/files.zip was intentionally ignored/removed if large; do not add large binaries to repo.

Security & operational notes:
- Do NOT commit config.php or any credentials. Create config.php locally from config.php.example:
  cp config.php.example config.php
  Edit DB credentials, BASE_URL and API_KEY locally, and ensure file permissions: sudo chown www-data:www-data config.php && sudo chmod 640 config.php
- If any secrets were accidentally committed, revoke them immediately and remove them from history.
- Large binaries (>50 MB) must be handled outside Git (release asset or Git LFS).