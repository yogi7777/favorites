-- Hinweis: Admin-Account wird beim Setup über setup.php angelegt.
-- Manuelle Installation: am Ende dieser Datei den INSERT-Befehl
-- ausführen und den Passwort-Hash mit password_hash() generieren.
-- ============================================================

CREATE DATABASE IF NOT EXISTS favorites
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE favorites;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL
);

CREATE TABLE remember_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    device_name VARCHAR(100) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    position INT DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE tabs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(120) NOT NULL,
    icon VARCHAR(32) DEFAULT 'T',
    position INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tabs_user_slug (user_id, slug),
    UNIQUE KEY uq_tabs_user_name (user_id, name),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE category_tabs (
    category_id INT NOT NULL,
    tab_id INT NOT NULL,
    PRIMARY KEY (category_id, tab_id),
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
    FOREIGN KEY (tab_id) REFERENCES tabs(id) ON DELETE CASCADE
);

CREATE TABLE category_tab_positions (
    tab_id INT NOT NULL,
    category_id INT NOT NULL,
    position INT DEFAULT 0,
    pos_x INT DEFAULT NULL,
    pos_y INT DEFAULT NULL,
    PRIMARY KEY (tab_id, category_id),
    FOREIGN KEY (tab_id) REFERENCES tabs(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
);

CREATE TABLE favorites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    category_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    url VARCHAR(512) NOT NULL,
    favicon_url VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
);

INSERT INTO users (username, password_hash)
VALUES ('admin', '$2y$10$u2IRsBqlyw/3xRBj1t3IQeJ2vf7MojRpIQKPHI/IEzVVgtgxq7m/W');

    -- ============================================================
    -- Notes-Feature
    -- ============================================================

    CREATE TABLE notes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        title VARCHAR(100) NOT NULL DEFAULT 'Note',
        content TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    CREATE TABLE note_tabs (
        note_id INT NOT NULL,
        tab_id  INT NOT NULL,
        position INT DEFAULT 0,
        pos_x INT DEFAULT NULL,
        pos_y INT DEFAULT NULL,
        width  INT DEFAULT 360,
        height INT DEFAULT 200,
        PRIMARY KEY (note_id, tab_id),
        FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE,
        FOREIGN KEY (tab_id)  REFERENCES tabs(id)  ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- ============================================================
    -- Manuelle Installation (ohne setup.php):
    -- Passwort-Hash generieren: php -r "echo password_hash('deinPasswort', PASSWORD_BCRYPT);"
    -- INSERT INTO users (username, password_hash) VALUES ('admin', 'HASH_HIER_EINTRAGEN');
    -- ============================================================