#!/usr/bin/env php
<?php
/**
 * CLI: MariaDB dump into APP_ROOT/backup (outside the public webroot), keep last 7 days.
 *
 * Cron (once per night), e.g. 02:15:
 *   php /path/to/favorites/bin/backup.php
 *
 * Usage:
 *   php bin/backup.php
 *   php bin/backup.php --force
 *
 * One dump per calendar day. Existing today's file is skipped unless --force.
 * HTTP access is denied.
 */

declare(strict_types=1);

const FAVORITES_BACKUP_KEEP = 7;
const FAVORITES_BACKUP_KEEP_MAX = 31;
const FAVORITES_BACKUP_LOCK = '.dump.lock';
const FAVORITES_BACKUP_CHUNK = 50;
const FAVORITES_BACKUP_MAX_SECONDS = 180;
const FAVORITES_BACKUP_IDENT = '/^[A-Za-z0-9_]+$/';
const FAVORITES_BACKUP_BINARY_TYPES = [
    'blob', 'tinyblob', 'mediumblob', 'longblob',
    'binary', 'varbinary', 'bit',
];

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden: CLI only.\n";
    exit(1);
}

$envRoot = getenv('FAVORITES_ROOT');
$candidates = [];
if (is_string($envRoot) && $envRoot !== '') {
    $candidates[] = rtrim($envRoot, "/\\");
}
$candidates[] = dirname(__DIR__);

$appRoot = null;
foreach ($candidates as $candidate) {
    if (is_file($candidate . '/src/bootstrap.php')) {
        $appRoot = $candidate;
        break;
    }
}
if ($appRoot === null) {
    fwrite(STDERR, "Application root not found. Set FAVORITES_ROOT.\n");
    exit(1);
}
if (!defined('APP_ROOT')) {
    define('APP_ROOT', $appRoot);
}

try {
    require APP_ROOT . '/src/bootstrap.php';
} catch (Throwable $e) {
    fwrite(STDERR, "Cannot load application config: " . $e->getMessage() . "\n");
    exit(1);
}

if (!defined('DB_HOST') || !defined('DB_USER') || !defined('DB_NAME')) {
    fwrite(STDERR, "Database configuration is incomplete.\n");
    exit(1);
}

/**
 * Dumps live in APP_ROOT/backup (outside the public webroot).
 * Override with FAVORITES_BACKUP_DIR if needed.
 */
function favorites_backup_dir(): string
{
    if (defined('BACKUP_DIR') && BACKUP_DIR !== '') {
        return BACKUP_DIR;
    }

    $env = getenv('FAVORITES_BACKUP_DIR');
    if (is_string($env) && $env !== '') {
        return rtrim($env, "/\\");
    }

    return APP_ROOT . '/backup';
}

function favorites_backup_prefix(): string
{
    $name = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) DB_NAME);
    if ($name === '') {
        $name = 'favorites';
    }

    return $name . '-';
}

function favorites_backup_today(): string
{
    return date('Y-m-d');
}

function favorites_backup_ensure_dir(string $dir): void
{
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create backup directory: ' . $dir);
    }
    if (!is_writable($dir)) {
        throw new RuntimeException('Backup directory is not writable: ' . $dir);
    }

    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "Require all denied\nDeny from all\n");
        @chmod($ht, 0640);
    }
    $index = $dir . '/index.html';
    if (!is_file($index)) {
        @file_put_contents($index, '');
        @chmod($index, 0640);
    }
}

/** @return list<string> */
function favorites_backup_list_files(string $dir): array
{
    $prefix = favorites_backup_prefix();
    $files = [];
    foreach (['sql.gz', 'sql'] as $ext) {
        $matches = glob($dir . '/' . $prefix . '*.' . $ext) ?: [];
        foreach ($matches as $path) {
            if (is_file($path) && !str_ends_with($path, '.tmp')) {
                $files[] = $path;
            }
        }
    }

    $files = array_values(array_unique($files));
    rsort($files, SORT_STRING);

    return $files;
}

function favorites_backup_todays_file(string $dir): ?string
{
    $prefix = favorites_backup_prefix();
    $date = favorites_backup_today();
    foreach (['sql.gz', 'sql'] as $ext) {
        $path = $dir . '/' . $prefix . $date . '.' . $ext;
        if (is_file($path) && filesize($path) > 0) {
            return $path;
        }
    }

    return null;
}

function favorites_backup_unlink(string $path): void
{
    if (is_file($path)) {
        @unlink($path);
    }
}

function favorites_backup_prune(string $dir, int $keep): void
{
    $files = favorites_backup_list_files($dir);
    foreach (array_slice($files, $keep) as $path) {
        favorites_backup_unlink($path);
    }

    $prefix = favorites_backup_prefix();
    foreach (glob($dir . '/' . $prefix . '*.tmp') ?: [] as $tmp) {
        favorites_backup_unlink($tmp);
    }
}

function favorites_backup_connect(): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        DB_HOST,
        DB_NAME
    );

    return new PDO(
        $dsn,
        DB_USER,
        (string) DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
            PDO::ATTR_TIMEOUT            => 30,
        ]
    );
}

function favorites_backup_ident(string $name): string
{
    if (preg_match(FAVORITES_BACKUP_IDENT, $name) !== 1) {
        throw new RuntimeException('Invalid SQL identifier: ' . $name);
    }

    return '`' . $name . '`';
}

/** @return list<string> */
function favorites_backup_tables(PDO $pdo): array
{
    $stmt = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
    if ($stmt === false) {
        throw new RuntimeException('SHOW TABLES failed.');
    }

    $tables = [];
    while (($row = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
        $name = (string) ($row[0] ?? '');
        if ($name !== '' && preg_match(FAVORITES_BACKUP_IDENT, $name) === 1) {
            $tables[] = $name;
        }
    }

    return $tables;
}

/** @return array<string, string> column => base type */
function favorites_backup_columns(PDO $pdo, string $table): array
{
    $stmt = $pdo->query('SHOW COLUMNS FROM ' . favorites_backup_ident($table));
    if ($stmt === false) {
        throw new RuntimeException('SHOW COLUMNS failed for ' . $table);
    }

    $columns = [];
    while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
        $field = (string) ($row['Field'] ?? '');
        if ($field === '' || preg_match(FAVORITES_BACKUP_IDENT, $field) !== 1) {
            continue;
        }
        $extra = strtolower((string) ($row['Extra'] ?? ''));
        if (str_contains($extra, 'generated')) {
            continue;
        }
        $type = strtolower((string) ($row['Type'] ?? ''));
        $type = (string) preg_replace('/\(.*$/', '', $type);
        $columns[$field] = $type;
    }

    return $columns;
}

function favorites_backup_sql_value(PDO $pdo, mixed $value, string $type): string
{
    if ($value === null) {
        return 'NULL';
    }

    if (in_array($type, FAVORITES_BACKUP_BINARY_TYPES, true)) {
        if ($value === '' || $value === '0') {
            return $type === 'bit' ? "b'0'" : "''";
        }

        return '0x' . bin2hex((string) $value);
    }

    if (is_bool($value)) {
        return $value ? '1' : '0';
    }

    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }

    return $pdo->quote((string) $value);
}

/** @param resource $handle */
function favorites_backup_write(mixed $handle, bool $gzip, string $chunk): void
{
    $written = $gzip ? gzwrite($handle, $chunk) : fwrite($handle, $chunk);
    if ($written === false) {
        throw new RuntimeException('Failed to write backup.');
    }
}

/** @param resource $handle */
function favorites_backup_close(mixed $handle, bool $gzip): void
{
    if ($gzip) {
        gzclose($handle);
    } else {
        fclose($handle);
    }
}

/**
 * @param resource $handle
 * @return array{0:int,1:int} tables written, row count
 */
function favorites_backup_dump_table(PDO $pdo, mixed $handle, bool $gzip, string $table): array
{
    $ident = favorites_backup_ident($table);

    $createStmt = $pdo->query('SHOW CREATE TABLE ' . $ident);
    if ($createStmt === false) {
        throw new RuntimeException('SHOW CREATE TABLE failed for ' . $table);
    }
    $createRow = $createStmt->fetch(PDO::FETCH_NUM);
    $createSql = is_array($createRow) ? (string) ($createRow[1] ?? '') : '';
    if ($createSql === '') {
        throw new RuntimeException('Empty CREATE TABLE for ' . $table);
    }

    favorites_backup_write($handle, $gzip, "-- Table {$ident}\n");
    favorites_backup_write($handle, $gzip, "DROP TABLE IF EXISTS {$ident};\n");
    favorites_backup_write($handle, $gzip, $createSql . ";\n\n");

    $columns = favorites_backup_columns($pdo, $table);
    if ($columns === []) {
        favorites_backup_write($handle, $gzip, "\n");
        return [1, 0];
    }

    $colNames = array_keys($columns);
    $colIdents = array_map('favorites_backup_ident', $colNames);
    $colList = implode(', ', $colIdents);
    $types = array_values($columns);
    $select = 'SELECT ' . $colList . ' FROM ' . $ident
        . ' LIMIT ' . FAVORITES_BACKUP_CHUNK . ' OFFSET ';

    $offset = 0;
    $rowCount = 0;

    do {
        $stmt = $pdo->query($select . $offset);
        if ($stmt === false) {
            throw new RuntimeException('SELECT failed during dump of ' . $table);
        }
        $rows = $stmt->fetchAll(PDO::FETCH_NUM);
        $fetched = count($rows);

        foreach ($rows as $row) {
            $values = [];
            foreach ($row as $i => $value) {
                $values[] = favorites_backup_sql_value($pdo, $value, $types[$i] ?? '');
            }
            favorites_backup_write(
                $handle,
                $gzip,
                'INSERT INTO ' . $ident . ' (' . $colList . ') VALUES (' . implode(', ', $values) . ");\n"
            );
            $rowCount++;
        }

        $offset += $fetched;
    } while ($fetched === FAVORITES_BACKUP_CHUNK);

    favorites_backup_write($handle, $gzip, "\n");

    return [1, $rowCount];
}

/**
 * @return array{path:string,bytes:int,tables:int,rows:int,ms:int}
 */
function favorites_backup_write_dump(string $dir, bool $gzip): array
{
    $started = microtime(true);
    $date = favorites_backup_today();
    $ext = $gzip ? 'sql.gz' : 'sql';
    $final = $dir . '/' . favorites_backup_prefix() . $date . '.' . $ext;
    $tmp = $final . '.tmp';

    favorites_backup_unlink($tmp);

    $handle = $gzip ? @gzopen($tmp, 'wb9') : @fopen($tmp, 'wb');
    if ($handle === false) {
        throw new RuntimeException('Cannot create backup temp file.');
    }
    @chmod($tmp, 0600);

    $pdo = null;
    $tableCount = 0;
    $rowCount = 0;

    try {
        $pdo = favorites_backup_connect();
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->beginTransaction();

        $createdAt = date('c');

        favorites_backup_write($handle, $gzip, "-- Favorites MariaDB dump\n");
        favorites_backup_write($handle, $gzip, "-- Created: {$createdAt}\n");
        favorites_backup_write($handle, $gzip, '-- Database: ' . DB_NAME . "\n");
        favorites_backup_write($handle, $gzip, "--\n\n");
        favorites_backup_write($handle, $gzip, "SET NAMES utf8mb4;\n");
        favorites_backup_write($handle, $gzip, "SET time_zone = '+00:00';\n");
        favorites_backup_write($handle, $gzip, "SET FOREIGN_KEY_CHECKS = 0;\n");
        favorites_backup_write($handle, $gzip, "SET UNIQUE_CHECKS = 0;\n");
        favorites_backup_write($handle, $gzip, "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");

        foreach (favorites_backup_tables($pdo) as $table) {
            [$wrote, $rows] = favorites_backup_dump_table($pdo, $handle, $gzip, $table);
            $tableCount += $wrote;
            $rowCount += $rows;
        }

        favorites_backup_write($handle, $gzip, "SET UNIQUE_CHECKS = 1;\n");
        favorites_backup_write($handle, $gzip, "SET FOREIGN_KEY_CHECKS = 1;\n");

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        favorites_backup_close($handle, $gzip);
        favorites_backup_unlink($tmp);
        throw $e;
    }

    favorites_backup_close($handle, $gzip);

    if (is_file($final)) {
        favorites_backup_unlink($final);
    }
    if (!@rename($tmp, $final)) {
        favorites_backup_unlink($tmp);
        throw new RuntimeException('Cannot move backup into place.');
    }
    @chmod($final, 0600);

    $bytes = @filesize($final);
    if ($bytes === false || $bytes < 1) {
        favorites_backup_unlink($final);
        throw new RuntimeException('Backup file is empty.');
    }

    return [
        'path'   => $final,
        'bytes'  => $bytes,
        'tables' => $tableCount,
        'rows'   => $rowCount,
        'ms'     => (int) round((microtime(true) - $started) * 1000),
    ];
}

/**
 * @return array{written:bool,skipped:bool,path:?string,bytes:?int,tables:?int,rows:?int,ms:?int}
 */
function favorites_backup_run(bool $force = false): array
{
    $dir = favorites_backup_dir();
    $gzip = function_exists('gzopen');
    $keep = max(1, min(FAVORITES_BACKUP_KEEP_MAX, FAVORITES_BACKUP_KEEP));

    favorites_backup_ensure_dir($dir);

    $existing = favorites_backup_todays_file($dir);
    if (!$force && $existing !== null) {
        return [
            'written' => false,
            'skipped' => true,
            'path'    => $existing,
            'bytes'   => (int) filesize($existing),
            'tables'  => null,
            'rows'    => null,
            'ms'      => null,
        ];
    }

    $lockPath = $dir . '/' . FAVORITES_BACKUP_LOCK;
    $lock = @fopen($lockPath, 'c+');
    if ($lock === false) {
        throw new RuntimeException('Cannot open backup lock file.');
    }

    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        throw new RuntimeException('Backup already running (lock held).');
    }

    try {
        if (!$force && favorites_backup_todays_file($dir) !== null) {
            $existing = favorites_backup_todays_file($dir);
            return [
                'written' => false,
                'skipped' => true,
                'path'    => $existing,
                'bytes'   => $existing !== null ? (int) filesize($existing) : null,
                'tables'  => null,
                'rows'    => null,
                'ms'      => null,
            ];
        }

        ignore_user_abort(true);
        if (function_exists('set_time_limit')) {
            @set_time_limit(FAVORITES_BACKUP_MAX_SECONDS);
        }

        $result = favorites_backup_write_dump($dir, $gzip);
        favorites_backup_prune($dir, $keep);

        return [
            'written' => true,
            'skipped' => false,
            'path'    => $result['path'],
            'bytes'   => $result['bytes'],
            'tables'  => $result['tables'],
            'rows'    => $result['rows'],
            'ms'      => $result['ms'],
        ];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function favorites_backup_format_bytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1024 * 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }

    return number_format($bytes / (1024 * 1024), 2) . ' MB';
}

$force = in_array('--force', $argv, true);

try {
    $result = favorites_backup_run($force);
} catch (Throwable $e) {
    fwrite(STDERR, 'Backup failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$name = $result['path'] !== null ? basename($result['path']) : '(none)';
$size = $result['bytes'] !== null ? favorites_backup_format_bytes($result['bytes']) : '?';

if ($result['skipped']) {
    fwrite(STDOUT, "Skipped: today already exists ({$name}, {$size}). Use --force to overwrite.\n");
    exit(0);
}

$tables = (int) $result['tables'];
$rows = (int) $result['rows'];
$ms = (int) $result['ms'];
fwrite(
    STDOUT,
    "Backup written: {$name} ({$size}, {$tables} tables, {$rows} rows, {$ms} ms)\n"
);
exit(0);
