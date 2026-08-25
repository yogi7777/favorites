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

function favorites_is_configured(): bool
{
    if (is_file(APP_ROOT . '/src/config.local.php') || is_file(APP_ROOT . '/src/config.php') || is_file(APP_ROOT . '/.env')) {
        return true;
    }

    return getenv('DB_HOST') !== false && getenv('DB_NAME') !== false;
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
        $stmt = $pdo->query("SELECT `value` FROM system_settings WHERE `key` = 'setup_completed' LIMIT 1");

        return $stmt !== false && $stmt->fetchColumn() === '1';
    } catch (Throwable $e) {
        return false;
    }
}
