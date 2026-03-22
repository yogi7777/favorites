<?php
header('Content-Type: application/json');

require_once 'auth.php';
checkAuth();

$url = $_GET['url'] ?? '';
if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
    echo json_encode(['favicon' => '', 'source' => '']);
    exit;
}

$parsed = parse_url($url);
$scheme = strtolower($parsed['scheme'] ?? '');
$host   = strtolower($parsed['host'] ?? '');

// Nur http/https erlauben
if (!in_array($scheme, ['http', 'https'], true)) {
    echo json_encode(['error' => 'Ungültiges URL-Schema']);
    exit;
}

// SSRF-Schutz: private/interne Adressen blockieren
$isPrivate = (
    $host === 'localhost' ||
    preg_match('/^127\./', $host) ||
    preg_match('/^10\./', $host) ||
    preg_match('/^192\.168\./', $host) ||
    preg_match('/^172\.(1[6-9]|2\d|3[01])\./', $host) ||
    preg_match('/^169\.254\./', $host) ||
    $host === '::1'
);
if ($isPrivate) {
    echo json_encode(['error' => 'Private Adressen nicht erlaubt']);
    exit;
}

$baseUrl = $scheme . '://' . $host;
$faviconUrl = '';
$source = '';

// Schritt 1: HTML der Seite laden und <link rel="icon"> parsen
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_USERAGENT      => 'Mozilla/5.0',
    CURLOPT_TIMEOUT        => 5,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_MAXREDIRS      => 5,
]);
$html = curl_exec($ch);
curl_close($ch);

if ($html) {
    // Suche nach link-Tags in dieser Reihenfolge: apple-touch-icon, icon, shortcut icon
    $patterns = [
        // href kommt nach rel
        '/<link[^>]+rel=["\']apple-touch-icon["\'][^>]+href=["\']([^"\']+)["\'][^>]*>/i',
        '/<link[^>]+rel=["\']icon["\'][^>]+href=["\']([^"\']+)["\'][^>]*>/i',
        '/<link[^>]+rel=["\']shortcut icon["\'][^>]+href=["\']([^"\']+)["\'][^>]*>/i',
        // href kommt vor rel
        '/<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\']apple-touch-icon["\'][^>]*>/i',
        '/<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\']icon["\'][^>]*>/i',
        '/<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\']shortcut icon["\'][^>]*>/i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $html, $match)) {
            $found = trim($match[1]);
            if ($found) {
                // Relativen Pfad in absolute URL umwandeln
                if (strpos($found, 'http://') === 0 || strpos($found, 'https://') === 0) {
                    $faviconUrl = $found;
                } elseif (strpos($found, '//') === 0) {
                    $faviconUrl = $scheme . ':' . $found;
                } elseif (strpos($found, '/') === 0) {
                    $faviconUrl = $baseUrl . $found;
                } else {
                    $path = $parsed['path'] ?? '/';
                    $dir = dirname($path);
                    $faviconUrl = $baseUrl . rtrim($dir, '/') . '/' . $found;
                }
                $source = $faviconUrl;
                break;
            }
        }
    }
}

// Schritt 2: Fallback auf /favicon.ico
if (!$faviconUrl) {
    $testUrl = $baseUrl . '/favicon.ico';
    $ch = curl_init($testUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0',
        CURLOPT_TIMEOUT        => 3,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_NOBODY         => true,
    ]);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $faviconUrl = $testUrl;
        $source = $testUrl;
    }
}

// Schritt 3: Fallback auf Google Favicon API
if (!$faviconUrl) {
    $faviconUrl = 'https://www.google.com/s2/favicons?domain=' . urlencode($host) . '&sz=128';
    $source = 'Google Favicon API';
}

echo json_encode([
    'favicon' => $faviconUrl,
    'source'  => $source,
]);
