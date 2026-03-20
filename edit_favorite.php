<?php
require_once 'config.php';
require_once 'auth.php';
checkAuth();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id      = $_SESSION['user_id'] ?? null;
    $id           = (int)($_POST['id'] ?? 0);
    $title        = trim($_POST['title'] ?? '');
    // Support both new (tab_ids[]) and old (category) format for backward compat
    $tab_ids = array_values(array_filter(array_map('intval', (array)($_POST['tab_ids'] ?? []))));
    if (empty($tab_ids) && isset($_POST['category'])) {
        $tab_ids = array_filter([(int)$_POST['category']]);
    }
    $url          = trim($_POST['url'] ?? '');
    $favicon_url  = trim($_POST['favicon_url'] ?? '');

    // Validierung
    if (empty($user_id) || !$id || empty($title) || empty($tab_ids) || empty($url)) {
        error_log("Fehlende Daten: user_id=$user_id, id=$id, title=$title, url=$url");
        http_response_code(400);
        echo json_encode(['error' => 'Erforderliche Daten fehlen.']);
        exit;
    }
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        http_response_code(400);
        echo json_encode(['error' => 'Ungültige URL.']);
        exit;
    }

    try {
        // Bestehendes Favicon abrufen
        $stmt = $pdo->prepare("SELECT favicon_url FROM favorites WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $user_id]);
        $old_favicon = $stmt->fetchColumn();

        if ($old_favicon === false) {
            http_response_code(404);
            echo json_encode(['error' => 'Favorit nicht gefunden.']);
            exit;
        }

        // Favicon-Handling
        $new_favicon_path = null;
        if ($favicon_url && filter_var($favicon_url, FILTER_VALIDATE_URL)) {
            $ch = curl_init($favicon_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            $favicon_data = curl_exec($ch);
            $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $http_code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($favicon_data && $http_code === 200) {
                $ext = '.png';
                if (str_contains($content_type, 'jpeg') || str_contains($content_type, 'jpg')) {
                    $ext = '.jpg';
                } elseif (str_contains($content_type, 'gif')) {
                    $ext = '.gif';
                }
                $new_favicon_path = "favicons/favicon_$id$ext";
                file_put_contents($new_favicon_path, $favicon_data);
                $favicon_url = $new_favicon_path;
            } else {
                $favicon_url = $old_favicon;
            }
        } else {
            $host = parse_url($url, PHP_URL_HOST);
            if ($host) {
                $favicon_source = "https://www.google.com/s2/favicons?domain=" . urlencode($host);
                $ch = curl_init($favicon_source);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                $favicon_data = curl_exec($ch);
                $http_code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($favicon_data && $http_code === 200) {
                    $new_favicon_path = "favicons/favicon_$id.png";
                    file_put_contents($new_favicon_path, $favicon_data);
                    $favicon_url = $new_favicon_path;
                } else {
                    $favicon_url = $old_favicon;
                }
            } else {
                $favicon_url = $old_favicon;
            }
        }

        // Altes Favicon löschen, wenn ein neues gespeichert wurde
        if ($new_favicon_path && $old_favicon && file_exists($old_favicon) && $old_favicon !== $new_favicon_path) {
            unlink($old_favicon);
        }

        // Primary category for backward compat column
        $primary_cat = $tab_ids[0];

        // Update favorites row
        $stmt = $pdo->prepare("UPDATE favorites SET title = ?, tab_id = ?, url = ?, favicon_url = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([$title, $primary_cat, $url, $favicon_url, $id, $user_id]);

        // Update junction table: replace all assignments (optional, skipped if table doesn't exist yet)
        try {
            $stmt = $pdo->prepare("DELETE FROM favorite_tabs WHERE favorite_id = ?");
            $stmt->execute([$id]);
            $stmt = $pdo->prepare("INSERT IGNORE INTO favorite_tabs (favorite_id, tab_id) VALUES (?, ?)");
            foreach ($tab_ids as $cid) {
                $stmt->execute([$id, $cid]);
            }
        } catch (Exception $juncErr) {
            // Junction table doesn't exist yet → no-op, data already in favorites.tab_id
        }

        http_response_code(200);
        echo json_encode(['success' => 'Favorit erfolgreich aktualisiert.']);
    } catch (PDOException $e) {
        error_log("Datenbankfehler: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Datenbankfehler: ' . $e->getMessage()]);
        exit;
    } catch (Exception $e) {
        error_log("Allgemeiner Fehler: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Fehler: ' . $e->getMessage()]);
        exit;
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Nur POST-Anfragen erlaubt.']);
    exit;
}
?>
