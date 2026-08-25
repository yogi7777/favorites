<?php
/**
 * Load environment, config, PDO and shared helpers.
 * Does not start a session (see src/auth.php / src/app.php).
 */
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

// bootstrap.php wird auch aus Funktionen included; $pdo muss global bleiben.
global $pdo;

function favorites_load_env(string $file): void
{
    if (!is_file($file) || !is_readable($file)) {
        return;
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, " \t\n\r\0\x0B\"'");
        if ($key === '') {
            continue;
        }
        if (getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

favorites_load_env(APP_ROOT . '/.env');

$configFile = function_exists('favorites_locate_config')
    ? favorites_locate_config()
    : (is_file(APP_ROOT . '/src/config.local.php')
        ? APP_ROOT . '/src/config.local.php'
        : (is_file(APP_ROOT . '/src/config.php') ? APP_ROOT . '/src/config.php' : null));

if (is_string($configFile) && is_file($configFile)) {
    require_once $configFile;
}

if (!defined('DB_HOST')) {
    define('DB_HOST', getenv('DB_HOST') ?: 'db');
}
if (!defined('DB_USER')) {
    define('DB_USER', getenv('DB_USER') ?: 'favorites');
}
if (!defined('DB_PASS')) {
    define('DB_PASS', getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : 'yourpassword');
}
if (!defined('DB_NAME')) {
    define('DB_NAME', getenv('DB_NAME') ?: 'favorites');
}

if (!defined('PUBLIC_DIR')) {
    $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    if (is_string($docRoot) && $docRoot !== '' && is_dir($docRoot)) {
        define('PUBLIC_DIR', rtrim($docRoot, "/\\"));
    } else {
        define('PUBLIC_DIR', APP_ROOT . '/public');
    }
}

if (!defined('FAVICONS_DIR')) {
    define('FAVICONS_DIR', PUBLIC_DIR . '/favicons');
}

if (!defined('BACKUP_DIR')) {
    $envBackup = getenv('FAVORITES_BACKUP_DIR');
    define(
        'BACKUP_DIR',
        (is_string($envBackup) && $envBackup !== '')
            ? rtrim($envBackup, "/\\")
            : APP_ROOT . '/backup'
    );
}

if (!defined('SQL_DIR')) {
    define('SQL_DIR', APP_ROOT . '/sql');
}

if (isset($pdo) && $pdo instanceof PDO) {
    $GLOBALS['pdo'] = $pdo;
}

if (!isset($GLOBALS['pdo']) || !($GLOBALS['pdo'] instanceof PDO)) {
    try {
        $GLOBALS['pdo'] = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            (string) DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } catch (PDOException $e) {
        if (defined('FAVORITES_ALLOW_DB_FAIL') && FAVORITES_ALLOW_DB_FAIL) {
            throw $e;
        }
        die('Datenbankverbindung fehlgeschlagen: ' . $e->getMessage());
    }
}
$pdo = $GLOBALS['pdo'];

require_once APP_ROOT . '/src/functions.php';
