<?php
require_once 'config.php';
require_once 'auth.php';
checkAuth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_SESSION['user_id'];
    $title = $_POST['title'] ?? '';
    $category_id = $_POST['category'] ?? '';
    $url = $_POST['url'] ?? '';
    $favicon_url = $_POST['favicon_url'] ?? '';

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

    if ($favicon_data) {
        $stmt = $pdo->prepare("INSERT INTO favorites (user_id, title, category_id, url) VALUES (?, ?, ?, ?)");
        $stmt->execute([$user_id, $title, $category_id, $url]);
        $favorite_id = $pdo->lastInsertId();

        // Dateiendung basierend auf Content-Type
        $ext = '.png'; // Standard
        if (str_contains($content_type, 'jpeg') || str_contains($content_type, 'jpg')) {
            $ext = '.jpg';
        } elseif (str_contains($content_type, 'gif')) {
            $ext = '.gif';
        }

        $favicon_path = "favicons/favicon_$favorite_id$ext";
        file_put_contents($favicon_path, $favicon_data);

        // Favicon-Pfad in DB speichern
        $stmt = $pdo->prepare("UPDATE favorites SET favicon_url = ? WHERE id = ?");
        $stmt->execute([$favicon_path, $favorite_id]);
    }
}
?>