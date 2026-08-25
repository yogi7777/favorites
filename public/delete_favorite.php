<?php
require_once __DIR__ . '/_init.php';
require_once APP_ROOT . '/src/app.php';
checkAuth();
verifyCsrfRequest();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_SESSION['user_id'];
    $id = $_POST['id'] ?? '';

    // Favicon-Pfad abrufen
    $stmt = $pdo->prepare("SELECT favicon_url FROM favorites WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $user_id]);
    $favicon_path = $stmt->fetchColumn();

    // Favorit löschen
    $stmt = $pdo->prepare("DELETE FROM favorites WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $user_id]);

    // Favicon-Datei löschen, falls vorhanden (nur lokale Pfade, nicht externe URLs)
    if ($favicon_path && !str_starts_with($favicon_path, 'http')) {
        $favicon_file = favorites_local_favicon_file($favicon_path);
        if ($favicon_file && file_exists($favicon_file)) {
            @unlink($favicon_file);
        }
    }
}
?>