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

// Hilfsfunktion: prüft ob eine URL eine gültige Bild-Antwort liefert
function checkFaviconUrl(string $url): bool {
    // Erst HEAD versuchen (schnell)
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0',
        CURLOPT_TIMEOUT        => 3,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_NOBODY         => true,
    ]);
    curl_exec($ch);
    $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    // HEAD wird von manchen Servern abgelehnt (405/501) → kleinen GET-Request versuchen
    if ($httpCode === 405 || $httpCode === 501 || $httpCode === 0) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'Mozilla/5.0',
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_RANGE          => '0-511', // nur erste 512 Bytes
        ]);
        curl_exec($ch);
        $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
    }

    if ($httpCode !== 200 && $httpCode !== 206) return false;
    // .ico-URLs auch ohne image/-Content-Type akzeptieren (viele Server senden keinen)
    $path = strtolower(parse_url($url, PHP_URL_PATH) ?? '');
    if (str_ends_with($path, '.ico') && ($httpCode === 200 || $httpCode === 206)) return true;
    return (
        str_contains($contentType, 'image/') ||
        str_contains($contentType, 'application/octet-stream') ||
        $contentType === ''
    );
}

// Schritt 1: /favicon.ico direkt prüfen (günstigste Option)
$testUrl = $baseUrl . '/favicon.ico';
if (checkFaviconUrl($testUrl)) {
    $faviconUrl = $testUrl;
    $source = $testUrl;
}

// Schritt 2: HTML der Seite laden und <link rel="icon"> parsen
if (!$faviconUrl) {
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
        $patterns = [
            '/<link[^>]+rel=["\']apple-touch-icon["\'][^>]+href=["\']([^"\']+)["\'][^>]*>/i',
            '/<link[^>]+rel=["\']icon["\'][^>]+href=["\']([^"\']+)["\'][^>]*>/i',
            '/<link[^>]+rel=["\']shortcut icon["\'][^>]+href=["\']([^"\']+)["\'][^>]*>/i',
            '/<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\']apple-touch-icon["\'][^>]*>/i',
            '/<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\']icon["\'][^>]*>/i',
            '/<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\']shortcut icon["\'][^>]*>/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $match)) {
                $found = trim($match[1]);
                if (!$found) continue;

                // Relativen Pfad in absolute URL umwandeln
                if (strpos($found, 'http://') === 0 || strpos($found, 'https://') === 0) {
                    $candidate = $found;
                } elseif (strpos($found, '//') === 0) {
                    $candidate = $scheme . ':' . $found;
                } elseif (strpos($found, '/') === 0) {
                    $candidate = $baseUrl . $found;
                } else {
                    $dir = dirname($parsed['path'] ?? '/');
                    $candidate = $baseUrl . rtrim($dir, '/') . '/' . $found;
                }

                // Prüfen, ob die URL wirklich ein Bild liefert
                if (checkFaviconUrl($candidate)) {
                    $faviconUrl = $candidate;
                    $source = $candidate;
                    break;
                }
            }
        }
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
