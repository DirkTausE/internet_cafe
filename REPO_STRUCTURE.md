# Repository-Struktur: internet_cafe

Empfohlene Hauptverzeichnisse und Zweck:

web/         -> PHP Webanwendung (public + app Dateien)
clients/     -> Client-Skripte (clientd, greeter, systemd unit)
db/          -> SQL-Schema und Seed-Daten
assets/      -> Gemeinsame statische Dateien (CSS, JS)
docs/        -> Installationsanleitungen, README, Greeter-Hinweise
scripts/     -> Hilfs-Skripte
storage/     -> Laufzeitdaten (in .gitignore, nicht versionieren): invoices, scans, uploads

Wichtig: config.php aus config.php.example lokal erzeugen (nicht ins Repo committen).
