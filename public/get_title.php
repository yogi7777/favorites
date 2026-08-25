<?php
header('Content-Type: application/json');

require_once __DIR__ . '/_init.php';
require_once APP_ROOT . '/src/app.php';
checkAuth();

$url = $_GET['url'] ?? '';
if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
    echo json_encode(['title' => '']);
    exit;
}

$parsed = parse_url($url);
$scheme = strtolower($parsed['scheme'] ?? '');
$host   = strtolower($parsed['host'] ?? '');

// Only allow http/https
if (!in_array($scheme, ['http', 'https'], true)) {
    echo json_encode(['error' => 'Invalid URL scheme']);
    exit;
}

// SSRF protection: block private/internal addresses
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
    echo json_encode(['error' => 'Private addresses not allowed']);
    exit;
}

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
curl_setopt($ch, CURLOPT_ENCODING, '');
$html = curl_exec($ch);
curl_close($ch);

// Fallback: manually decode gzip if curl failed to do so natively
if (!empty($html) && strpos($html, "\x1f\x8b") === 0) {
    $decoded = @gzdecode($html);
    if ($decoded !== false) {
        $html = $decoded;
    }
}

$title = '';
if ($html) {
    $libxml_previous_state = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    
    // Ensure UTF-8 parsing
    $html_utf8 = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');
    
    if (!empty($html_utf8)) {
        $dom->loadHTML($html_utf8);
        $xpath = new DOMXPath($dom);

        // 1. Check og:title
        $ogTitleNodes = $xpath->query('//meta[@property="og:title"]/@content');
        if ($ogTitleNodes->length > 0) {
            $title = $ogTitleNodes->item(0)->nodeValue;
        }

        // 2. Check <title>
        if (empty($title)) {
            $titleNodes = $dom->getElementsByTagName('title');
            if ($titleNodes->length > 0) {
                $title = $titleNodes->item(0)->textContent;
            }
        }
        
        // 3. Check meta name="title"
        if (empty($title)) {
            $metaTitleNodes = $xpath->query('//meta[@name="title"]/@content');
            if ($metaTitleNodes->length > 0) {
                $title = $metaTitleNodes->item(0)->nodeValue;
            }
        }

        // 4. Check description fallback
        if (empty($title)) {
            $descNodes = $xpath->query('//meta[@name="description"]/@content | //meta[@property="og:description"]/@content');
            if ($descNodes->length > 0) {
                $title = $descNodes->item(0)->nodeValue;
                if (mb_strlen($title) > 100) {
                    $title = mb_substr($title, 0, 97) . '...';
                }
            }
        }
    }
    
    libxml_clear_errors();
    libxml_use_internal_errors($libxml_previous_state);
    
    // Clean up title text
    if (!empty($title)) {
        $title = trim(html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        // Remove line breaks and excess spaces
        $title = preg_replace('/\s+/', ' ', $title);
    }
}

echo json_encode(['title' => $title]);
