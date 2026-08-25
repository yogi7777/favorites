<?php
/**
 * Locate APP_ROOT from the public document root.
 *
 * Resolution order:
 *   1) FAVORITES_ROOT environment variable
 *   2) parent directory (repo layout: favorites/public)
 *   3) sibling directory "favorites" (Hostpoint: favorites.itcrm.ch + favorites)
 */
declare(strict_types=1);

if (isset($_SERVER['SCRIPT_FILENAME']) && basename((string) $_SERVER['SCRIPT_FILENAME']) === '_init.php') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden.\n";
    exit(1);
}

function favorites_app_root(): string
{
    static $root = null;
    if (is_string($root)) {
        return $root;
    }

    $candidates = [];

    $env = getenv('FAVORITES_ROOT');
    if (is_string($env) && $env !== '') {
        $candidates[] = rtrim($env, "/\\");
    }

    $publicDir = __DIR__;
    $candidates[] = dirname($publicDir);
    $candidates[] = dirname($publicDir) . '/favorites';

    foreach ($candidates as $candidate) {
        if ($candidate !== '' && is_file($candidate . '/src/bootstrap.php')) {
            $root = $candidate;
            return $root;
        }
    }

    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Application root not found. Set FAVORITES_ROOT to the favorites app directory.\n";
    exit(1);
}

if (!defined('APP_ROOT')) {
    define('APP_ROOT', favorites_app_root());
}

/**
 * @return list<string>
 */
function favorites_config_candidates(): array
{
    $doc = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim((string) $_SERVER['DOCUMENT_ROOT'], "/\\") : '';
    $candidates = [
        __DIR__ . '/config.local.php',
        __DIR__ . '/config.php',
    ];
    if ($doc !== '') {
        $candidates[] = $doc . '/config.local.php';
        $candidates[] = $doc . '/config.php';
    }
    $candidates[] = APP_ROOT . '/src/config.local.php';
    $candidates[] = APP_ROOT . '/src/config.php';

    $unique = [];
    foreach ($candidates as $path) {
        if ($path !== '' && !in_array($path, $unique, true)) {
            $unique[] = $path;
        }
    }

    return $unique;
}

function favorites_locate_config(): ?string
{
    foreach (favorites_config_candidates() as $path) {
        if (is_file($path) && is_readable($path)) {
            return $path;
        }
    }

    return null;
}

function favorites_is_configured(): bool
{
    foreach (favorites_config_candidates() as $path) {
        if (basename($path) === 'config.php' && str_contains(str_replace('\\', '/', $path), '/src/config.php')) {
            continue;
        }
        if (is_file($path)) {
            return true;
        }
    }

    if (is_file(APP_ROOT . '/.env')) {
        return true;
    }

    return getenv('DB_HOST') !== false && getenv('DB_NAME') !== false;
}

/**
 * @return array<string, string>
 */
function favorites_setup_diagnostics(): array
{
    $local = APP_ROOT . '/src/config.local.php';
    $rows = [
        'APP_ROOT' => APP_ROOT,
        'Public-Ordner (_init.php)' => __DIR__,
        'DOCUMENT_ROOT' => (string) ($_SERVER['DOCUMENT_ROOT'] ?? ''),
        'open_basedir' => (string) (ini_get('open_basedir') ?: '(nicht gesetzt)'),
        'src/config.local.php' => is_file($local) ? 'gefunden' : 'FEHLT: ' . $local,
    ];

    $loaded = favorites_locate_config();
    $rows['Geladene Config'] = $loaded ?? '(keine Datei, Docker-Fallbacks)';

    if (!defined('FAVORITES_ALLOW_DB_FAIL')) {
        define('FAVORITES_ALLOW_DB_FAIL', true);
    }

    try {
        require_once APP_ROOT . '/src/bootstrap.php';
        $rows['DB_HOST'] = defined('DB_HOST') ? (string) DB_HOST : '(nicht gesetzt)';
        $rows['DB_USER'] = defined('DB_USER') ? (string) DB_USER : '(nicht gesetzt)';
        $rows['DB_NAME'] = defined('DB_NAME') ? (string) DB_NAME : '(nicht gesetzt)';
        global $pdo;
        if (isset($pdo) && $pdo instanceof PDO) {
            $rows['PDO'] = 'verbunden';
            try {
                $stmt = $pdo->query("SELECT `value` FROM system_settings WHERE `key` = 'setup_completed' LIMIT 1");
                $value = $stmt ? $stmt->fetchColumn() : false;
                $rows['setup_completed'] = $value === false || $value === null ? '(kein Datensatz)' : var_export($value, true);
            } catch (Throwable $e) {
                $rows['setup_completed'] = 'Fehler: ' . $e->getMessage();
            }
        } else {
            $rows['PDO'] = 'kein PDO-Objekt';
        }
    } catch (Throwable $e) {
        $rows['DB_HOST'] = defined('DB_HOST') ? (string) DB_HOST : '(nicht gesetzt)';
        $rows['DB_NAME'] = defined('DB_NAME') ? (string) DB_NAME : '(nicht gesetzt)';
        $rows['PDO'] = 'FEHLER: ' . $e->getMessage();
    }

    if (isset($GLOBALS['favorites_db_error']) && is_string($GLOBALS['favorites_db_error'])) {
        $rows['Letzter DB-Fehler'] = $GLOBALS['favorites_db_error'];
    }

    return $rows;
}

function favorites_mark_setup_completed(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `system_settings` (
            `key` VARCHAR(100) NOT NULL PRIMARY KEY,
            `value` TEXT NULL,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $stmt = $pdo->prepare(
        "INSERT INTO system_settings (`key`, `value`)
         VALUES ('setup_completed', '1')
         ON DUPLICATE KEY UPDATE `value` = '1', `updated_at` = CURRENT_TIMESTAMP"
    );
    $stmt->execute();
}

function favorites_is_setup_completed(): bool
{
    if (!favorites_is_configured()) {
        return false;
    }

    if (!defined('FAVORITES_ALLOW_DB_FAIL')) {
        define('FAVORITES_ALLOW_DB_FAIL', true);
    }

    try {
        require_once APP_ROOT . '/src/bootstrap.php';
        global $pdo;
        if (!isset($pdo) || !($pdo instanceof PDO)) {
            return false;
        }

        try {
            $stmt = $pdo->query("SELECT `value` FROM system_settings WHERE `key` = 'setup_completed' LIMIT 1");
            $flag = $stmt ? $stmt->fetchColumn() : false;
            if ($flag !== false && $flag !== null && (string) $flag === '1') {
                return true;
            }
        } catch (Throwable $e) {
            // Ältere DBs haben system_settings noch nicht.
        }

        $stmt = $pdo->query('SELECT id FROM users LIMIT 1');
        if ($stmt && $stmt->fetchColumn()) {
            try {
                favorites_mark_setup_completed($pdo);
            } catch (Throwable $e) {
                // Trotzdem als eingerichtet behandeln, wenn schon Benutzer existieren.
            }
            return true;
        }

        return false;
    } catch (Throwable $e) {
        $GLOBALS['favorites_db_error'] = $e->getMessage();
        return false;
    }
}
