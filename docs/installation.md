# Installation (Kurz)

1. Server (Ubuntu-MATE): Pakete installieren (Apache2, MariaDB, PHP, wkhtmltopdf, squid, cups, python3)
2. Datenbank anlegen:
   mysql_secure_installation
   Erstelle DB und Benutzer und importiere db/schema.sql
3. Webapp: Kopiere web/ nach /var/www/internetcafe, setzte Permissions (www-data)
4. Clients: Kopiere clients/ nach /opt/internetcafe, passe API_KEY und SERVER_URL an
5. Erstelle config.php lokal aus config.php.example und setze Dateirechte
