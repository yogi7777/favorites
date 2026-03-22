<?php
require_once 'config.php';
require_once 'auth.php';
require_once __DIR__ . '/functions.php';
checkAuth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_SESSION['user_id'];
    $title = $_POST['title'] ?? '';
    $category_id = $_POST['category'] ?? '';
    $url = $_POST['url'] ?? '';
    $favicon_url = $_POST['favicon_url'] ?? '';           // Custom URL vom Benutzer
    $detected_favicon_url = $_POST['detected_favicon_url'] ?? ''; // Auto-erkannte URL aus Vorschau

    // Hilfsfunktion: SSRF-Prüfung
    function isSafeFaviconUrl(string $faviconUrl): bool {
        $parsed = parse_url($faviconUrl);
        $scheme = strtolower($parsed['scheme'] ?? '');
        $host   = strtolower($parsed['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true)) return false;
        return !(
            $host === 'localhost' ||
            preg_match('/^127\./', $host) ||
            preg_match('/^10\./', $host) ||
            preg_match('/^192\.168\./', $host) ||
            preg_match('/^172\.(1[6-9]|2[0-9]|3[01])\./', $host) ||
            preg_match('/^169\.254\./', $host) ||
            $host === '::1'
        );
    }

    // Beste Favicon-Quelle bestimmen: custom > detected > Google API
    $favicon_source = '';
    if ($favicon_url && isSafeFaviconUrl($favicon_url)) {
        $favicon_source = $favicon_url;
    } elseif ($detected_favicon_url && isSafeFaviconUrl($detected_favicon_url)) {
        $favicon_source = $detected_favicon_url;
    } else {
        $favicon_source = 'https://www.google.com/s2/favicons?domain=' . urlencode(parse_url($url, PHP_URL_HOST)) . '&sz=256';
    }

    // Favorit zunächst mit Platzhalter speichern
    $stmt = $pdo->prepare("INSERT INTO favorites (user_id, title, category_id, url, favicon_url) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$user_id, $title, $category_id, $url, $favicon_source]);
    $favorite_id = $pdo->lastInsertId();

    // Favicon erkennen und lokal speichern (preferredUrl → favicon.ico → HTML → Google API)
    $local_favicon = detectAndDownloadFavicon($url, $favorite_id, $favicon_source);
    if ($local_favicon) {
        $stmt = $pdo->prepare("UPDATE favorites SET favicon_url = ? WHERE id = ?");
        $stmt->execute([$local_favicon, $favorite_id]);
    }
}
?>