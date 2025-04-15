<?php
require_once 'config.php';
require_once 'auth.php';
checkAuth();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_SESSION['user_id'] ?? null;
    $id = $_POST['id'] ?? '';
    $title = $_POST['title'] ?? '';
    $category_id = $_POST['category'] ?? '';
    $url = $_POST['url'] ?? '';
    $favicon_url = $_POST['favicon_url'] ?? '';

    // Validierung
    if (empty($user_id) || empty($id) || empty($title) || empty($category_id) || empty($url)) {
        error_log("Fehlende Daten: user_id=$user_id, id=$id, title=$title, category_id=$category_id, url=$url");
        http_response_code(400);
        echo json_encode(['error' => 'Erforderliche Daten fehlen.']);
        exit;
    }
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        error_log("Ungültige URL: $url");
        http_response_code(400);
        echo json_encode(['error' => 'Ungültige URL.']);
        exit;
    }

    try {
        // Bestehendes Favicon abrufen
        $stmt = $pdo->prepare("SELECT favicon_url FROM favorites WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $user_id]);
        $old_favicon = $stmt->fetchColumn();

        // Favicon-Handling
        $new_favicon_path = null;
        if ($favicon_url && filter_var($favicon_url, FILTER_VALIDATE_URL)) {
            // Benutzerdefiniertes Favicon herunterladen
            $ch = curl_init($favicon_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5); // Timeout hinzufügen
            $favicon_data = curl_exec($ch);
            $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
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
                error_log("Fehler beim Herunterladen des benutzerdefinierten Favicons: $favicon_url");
                $favicon_url = $old_favicon; // Behalte altes Favicon
            }
        } else {
            // Standard-Favicon generieren
            $host = parse_url($url, PHP_URL_HOST);
            if ($host) {
                $favicon_url = "https://www.google.com/s2/favicons?domain=" . urlencode($host);
                $ch = curl_init($favicon_url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                $favicon_data = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($favicon_data && $http_code === 200) {
                    $new_favicon_path = "favicons/favicon_$id.png";
                    file_put_contents($new_favicon_path, $favicon_data);
                    $favicon_url = $new_favicon_path;
                } else {
                    error_log("Fehler beim Herunterladen des Standard-Favicons für URL: $url");
                    $favicon_url = $old_favicon; // Behalte altes Favicon
                }
            } else {
                error_log("Ungültiger Host für URL: $url");
                $favicon_url = $old_favicon; // Behalte altes Favicon
            }
        }

        // Altes Favicon löschen, falls ein neues gespeichert wurde
        if ($new_favicon_path && $old_favicon && file_exists($old_favicon) && $old_favicon !== $new_favicon_path) {
            unlink($old_favicon);
        }

        // Datenbank-Update
        $stmt = $pdo->prepare("UPDATE favorites SET title = ?, category_id = ?, url = ?, favicon_url = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([$title, $category_id, $url, $favicon_url, $id, $user_id]);

        // Erfolg, auch wenn keine Zeilen aktualisiert wurden (z. B. gleiche Werte)
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