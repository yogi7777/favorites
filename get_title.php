<?php
header('Content-Type: application/json');

$url = $_GET['url'] ?? '';
if (!$url) {
    echo json_encode(['title' => '']);
    exit;
}

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
$html = curl_exec($ch);
curl_close($ch);

$title = '';
if ($html && preg_match('/<title>(.*?)<\/title>/i', $html, $match)) {
    $title = trim($match[1]);
}

echo json_encode(['title' => $title]);
?>