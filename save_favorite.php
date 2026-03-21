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
        $favicon_url = "https://www.google.com/s2/favicons?domain=" . urlencode(parse_url($url, PHP_URL_HOST));
    }

    // SSRF-Schutz: nur http/https erlauben, keine internen Adressen
    $parsed = parse_url($favicon_url);
    $scheme = strtolower($parsed['scheme'] ?? '');
    $host   = strtolower($parsed['host'] ?? '');
    $isPrivate = (
        $host === 'localhost' ||
        preg_match('/^127\./', $host) ||
        preg_match('/^10\./', $host) ||
        preg_match('/^192\.168\./', $host) ||
        preg_match('/^172\.(1[6-9]|2[0-9]|3[01])\./', $host) ||
        preg_match('/^169\.254\./', $host) ||
        $host === '::1'
    );
    if (!in_array($scheme, ['http', 'https'], true) || $isPrivate) {
        $favicon_url = "https://www.google.com/s2/favicons?domain=" . urlencode(parse_url($url, PHP_URL_HOST));
    }

    // Favorit immer zuerst speichern (mit Fallback-Favicon-URL)
    $stmt = $pdo->prepare("INSERT INTO favorites (user_id, title, category_id, url, favicon_url) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$user_id, $title, $category_id, $url, $favicon_url]);
    $favorite_id = $pdo->lastInsertId();

    // Favicon-Verzeichnis sicherstellen
    if (!file_exists('favicons')) {
        mkdir('favicons', 0755, true);
    }

    // Favicon herunterladen und lokal speichern (optional)
    $ch = curl_init($favicon_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $favicon_data = curl_exec($ch);
    $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($favicon_data && strlen($favicon_data) > 0) {
        // Dateiendung basierend auf Content-Type
        $ext = '.png'; // Standard
        if (str_contains($content_type, 'jpeg') || str_contains($content_type, 'jpg')) {
            $ext = '.jpg';
        } elseif (str_contains($content_type, 'gif')) {
            $ext = '.gif';
        }

        $favicon_path = "favicons/favicon_$favorite_id$ext";
        if (file_put_contents($favicon_path, $favicon_data) !== false) {
            // Lokalen Pfad in DB aktualisieren
            $stmt = $pdo->prepare("UPDATE favorites SET favicon_url = ? WHERE id = ?");
            $stmt->execute([$favicon_path, $favorite_id]);
        }
    }
}
?>