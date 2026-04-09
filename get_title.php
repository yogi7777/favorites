<?php
header('Content-Type: application/json');

require_once 'auth.php';
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
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$html = curl_exec($ch);
curl_close($ch);

$title = '';
if ($html && preg_match('/<title>(.*?)<\/title>/i', $html, $match)) {
    $title = trim(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

echo json_encode(['title' => $title]);
