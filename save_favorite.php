<?php
require_once 'config.php';
require_once 'auth.php';
checkAuth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id     = $_SESSION['user_id'];
    $title       = trim($_POST['title'] ?? '');
    $category_ids = array_values(array_filter(array_map('intval', (array)($_POST['category_ids'] ?? []))));
    $url         = trim($_POST['url'] ?? '');
    $favicon_url = trim($_POST['favicon_url'] ?? '');

    if (!$title || !$url || empty($category_ids)) {
        http_response_code(400);
        exit;
    }

    // Primary category stored on the row for backward compat
    $primary_cat = $category_ids[0];

    // Standard-Favicon von Google, falls nichts angegeben
    if (!$favicon_url) {
        $favicon_url = "https://www.google.com/s2/favicons?domain=" . parse_url($url, PHP_URL_HOST);
    }

    // Favicon herunterladen
    $ch = curl_init($favicon_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $favicon_data = curl_exec($ch);
    $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    // Always insert favorite (even if favicon fails)
    $stmt = $pdo->prepare("INSERT INTO favorites (user_id, title, category_id, url) VALUES (?, ?, ?, ?)");
    $stmt->execute([$user_id, $title, $primary_cat, $url]);
    $favorite_id = $pdo->lastInsertId();

    if ($favicon_data) {
        $ext = '.png';
        if (str_contains($content_type, 'jpeg') || str_contains($content_type, 'jpg')) {
            $ext = '.jpg';
        } elseif (str_contains($content_type, 'gif')) {
            $ext = '.gif';
        }
        $favicon_path = "favicons/favicon_$favorite_id$ext";
        file_put_contents($favicon_path, $favicon_data);

        $stmt = $pdo->prepare("UPDATE favorites SET favicon_url = ? WHERE id = ?");
        $stmt->execute([$favicon_path, $favorite_id]);
    }

    // Insert category assignments into junction table
    $stmt = $pdo->prepare("INSERT IGNORE INTO favorite_categories (favorite_id, category_id) VALUES (?, ?)");
    foreach ($category_ids as $cid) {
        $stmt->execute([$favorite_id, $cid]);
    }
}
?>