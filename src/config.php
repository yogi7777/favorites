<?php
# Default / Docker database configuration (environment or fallbacks).
# Hosting: setup.php writes src/config.local.php instead of changing this file.

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
