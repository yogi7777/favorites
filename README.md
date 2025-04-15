# Favorites Manager

Ein einfaches, benutzerfreundliches System zum Verwalten von Favoriten (Bookmarks) mit Kategorien, Dark-Mode-Design und Drag-and-Drop-Funktionalität direkt in die jeweilge Kategorie. Entwickelt mit PHP, MySQL und Bootstrap 5.

## Funktionen

- **Edge Browser**: rechte Maustaste, "open in Sidebar" 😱

- **Authentifizierung**: Login, Profilbearbeitung (Username/E-Mail und Passwort).
- **Vertrauenswürdige Geräte**: http secure cookie damit die Session länger bestehen bleibt.
- **Sicherheit**: Authentifizierung, Session-Management, Schutz vor SQL-Injection durch Prepared Statements.
- **Favoriten**: Hinzufügen per Drag-and-Drop oder URL-Eingabe, Bearbeiten, Löschen, mit automatischem oder benutzerdefiniertem Favicon-Download.
- **Kategorien**: Anlegen, Bearbeiten, Löschen und Reihenfolge per Drag-and-Drop ändern, mit alphabetischer Standardsortierung bei gleicher Position.
- **Ansichten**: View-Modus (nur Anzeige), Edit-Modus (Bearbeitung und Drag-and-Drop), Categories-Modus (Kategorienverwaltung).
- **Responsives Design**: Optimiert für Desktop, Sidebar und Mobile mit Bootstrap 5.3.
- **Dark Mode**: Konsistentes Design mit data-bs-theme="dark".
- **Import Export**: Export in JSON oder HTML Format (letzeres für Chrome, Firefox, Edge). Import JSON.

## Voraussetzungen
- **PHP**: 8.3 oder höher (mit pdo_mysql und curl aktiviert)
- **MySQL**: 5.7 oder höher, oder MariaDB 11.3 oder höher
- **Webserver**: Apache oder Nginx
- **Browser**: JavaScript-Unterstützung muss eingeschaltet sein
- **Ordner favicons/** im Root-Verzeichnis und Schreibrechte (chmod 755).

**Admin-Panel** für User-Management.
>UserID 1 ist der Admin.



