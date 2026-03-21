# Favorites Manager

**Favorites Manager** Open-source bookmark manager with dark mode, categories and drag & drop – self-hosted.

## Features

- **Authentication**: Login and profile management (username/email and password).
- **Trusted Devices**: Secure HTTP cookie to maintain long-lasting sessions.
- **Security**: Authentication, session management, protection against SQL injection via prepared statements.
- **Bookmarks**: Add via drag-and-drop direct into the categorie, or URL input, edit, delete, with automatic or custom favicon download.
- **Categories**: Create, edit, delete and reorder via drag-and-drop, with alphabetical sorting for equal positions.
- **Notes**: Sticky-note tiles per tab – freely positionable on a canvas (desktop), resizable, with Markdown rendering and live-save.
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

Docker is the easiest way to run Favorites Manager — no PHP or MySQL installation needed on your host.
The stack starts three containers:

| Container | Image | Role |
|---|---|---|
| `favorites-app` | PHP-FPM 8.3 Alpine | App runtime (`pdo_mysql` + `curl`) |
| `favorites-web` | Nginx 1.27 Alpine | Web server, port 8080 by default |
| `favorites-db` | MariaDB 11.4 | Database, data persisted in a named volume |

### Prerequisites

- [Docker](https://docs.docker.com/get-docker/) installed (Desktop or Engine)
- Docker Compose v2 (`docker compose`, not the old `docker-compose`)

### Option A – Command Line (Docker Compose)

**1. Clone the repository**

```bash
git clone https://github.com/yogi7777/favorites.git
cd favorites
```

**2. (Optional) Customize ports and passwords**

By default the app runs on port `8080` with the password `favorites` for the database.
If you want to change anything, create a `.env` file first:

```bash
cp .env.docker.example .env
```

Then open `.env` and edit the values you want to change:

```ini
APP_PORT=8080                   # Host port → http://localhost:8080
TZ=Europe/Berlin                # Timezone for PHP and MariaDB

DB_HOST=db
DB_NAME=favorites
DB_USER=favorites
DB_PASS=favorites               # ← change this in production!

MARIADB_ROOT_PASSWORD=rootpass  # ← change this in production!
```

> **Note:** If you skip this step, the defaults from `docker-compose.yml` are used automatically.

**3. Build and start the stack**

```bash
docker compose up -d --build
```

**4. Run the setup wizard**

Open your browser and go to:

```
http://localhost:8080/setup.php
```

The form is pre-filled with the Docker defaults — just click through, create your admin user and you're done.

**5. Log in**

```
http://localhost:8080/
```

---

### Option B – Portainer (GUI)

If you manage your containers with [Portainer](https://www.portainer.io/), you can deploy the stack via the **Stacks** feature:

1. In Portainer, go to **Stacks → Add stack**.
2. Give the stack a name (e.g. `favorites`).
3. Choose **Repository** and enter the repository URL, or switch to **Web editor** and paste the contents of `docker-compose.yml` directly.
4. Under **Environment variables**, add any variables you want to override (e.g. `APP_PORT`, `DB_PASS`, `MARIADB_ROOT_PASSWORD`).
5. Click **Deploy the stack**.
6. Once all three containers are green/running, open `http://<your-host>:8080/setup.php` to finish setup.

> **Tip:** You can also bind-mount the project folder as a volume in Portainer to make the code editable without rebuilding.

---

### Useful Commands

```bash
# Start stack (+ rebuild app image if code changed)
docker compose up -d --build

# View live logs of all containers
docker compose logs -f

# View logs of a specific container
docker compose logs -f app

# Stop stack (data volume is kept)
docker compose down

# Stop stack AND delete the database volume (fresh reinstall)
docker compose down -v

# Open a shell inside the PHP container
docker exec -it favorites-app sh

# Restart only the web container (e.g. after nginx config change)
docker compose restart web
```

### Upgrading

```bash
git pull
docker compose up -d --build
```

## Notes

Each tab has its own board of **sticky-note tiles**:

- **Create & manage** notes via the *Notes Manager* (`notes_manage.php`) – assign a note to one or more tabs.
- **Free-canvas layout** (desktop ≥ 992 px, non-"all" tab): drag notes anywhere on the board, resize them with the custom resize handle.
- **Markdown rendering** in View mode – write with Markdown syntax, see the formatted result instantly.
- **Live-save** in Edit mode – content is auto-saved 800 ms after you stop typing, no save button needed.
- On mobile / the "all" tab, notes are shown inline among the bookmark categories.

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


