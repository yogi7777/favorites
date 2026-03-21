# Favorites Manager

**Favorites Manager** Open-source bookmark manager with dark mode, categories and drag & drop – self-hosted.

## Features

- **Authentication**: Login and profile management (username/email and password).
- **Trusted Devices**: Secure HTTP cookie to maintain long-lasting sessions.
- **Security**: Authentication, session management, protection against SQL injection via prepared statements.
- **Bookmarks**: Add via drag-and-drop direct into the categorie, or URL input, edit, delete, with automatic or custom favicon download.
- **Categories**: Create, edit, delete and reorder via drag-and-drop, with alphabetical sorting for equal positions.
- **Views**: View mode (read-only), Edit mode, and Categories mode (manage categories).
- **Responsive Design**: Optimized for desktop, sidebar view, and mobile using Bootstrap 5.3.
- **Dark Mode**: Consistent theme using `data-bs-theme="dark"`.
- **Import/Export**: Export as JSON or HTML (compatible with Chrome, Firefox, Edge). Import via JSON.

## Requirements

- **PHP**: Version 8.3 or higher (with `pdo_mysql` and `curl` enabled)
- **MySQL**: Version 5.7 or higher, or MariaDB 11.3 or higher
- **Web Server**: Apache or Nginx
- **Browser**: JavaScript must be enabled
- **Folder Permissions**: `favicons/` folder in the root directory must exist and be writable (chmod 755)

## Docker (Recommended for Easy Setup)

If you want the easiest installation path, use Docker Compose.
This starts:

- **Nginx** (web server)
- **PHP-FPM 8.3** (app runtime with `pdo_mysql`)
- **MariaDB 11.4** (database)

### Quick Start

1. Start stack with defaults:

```bash
docker compose up -d --build
```

2. Open setup wizard:

```
http://localhost:8080/setup.php
```

3. In the setup form, defaults are already prefilled for Docker:
  - Host: `db`
  - Database: `favorites`
  - User: `favorites`
  - Password: `favorites`

4. Create your admin user, finish setup, then login.

### Optional Configuration

You usually do not need to edit `docker-compose.yml`.
If you want custom ports/passwords, create a `.env` file from the template:

```bash
cp .env.docker.example .env
```

Then edit only values in `.env` (for example `APP_PORT`, `DB_PASS`, `MARIADB_ROOT_PASSWORD`).

### Useful Commands

```bash
# start
docker compose up -d

# logs
docker compose logs -f

# stop
docker compose down

# stop + remove DB data volume (fresh reinstall)
docker compose down -v
```

## Admin Features

- User management via the integrated admin panel.
- The first registered user (ID 1) is automatically granted admin rights.  
  - _Yes, I know – will improve this later 😎_

## 💡 Tip for Microsoft Edge Users

If you're using Favorites Manager in **Microsoft Edge**, you can open it directly in the sidebar:

> **Right-click** → **"Open in sidebar"** 😱

## Chrome/Edge Plugin

If you want, there is a Chrome plugin in the browser-addon-sidebar folder that you can load manually into your browser (activate Developer Mode -> Load Unpacked in the Extension Manager).
This allows you to open the sidebar with a single mouse click.

## Pictures

### Browser

![image](https://github.com/user-attachments/assets/2b867e47-57ab-4043-9a60-bea2393d478d)



### Sidebar

![image](https://github.com/user-attachments/assets/76fcc581-2429-4253-aee7-25ace10c4cf6)



### Mobile

![image](https://github.com/user-attachments/assets/b9ca4527-3b83-4962-bae2-50d14280567c)


