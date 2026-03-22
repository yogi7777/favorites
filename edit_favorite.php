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
    $favicon_url = $_POST['favicon_url'] ?? '';           // Custom URL vom Benutzer
    $detected_favicon_url = $_POST['detected_favicon_url'] ?? ''; // Auto-erkannte URL aus Vorschau

    // SSRF-Prüfung
    function isSafeFaviconUrl(string $u): bool {
        $p = parse_url($u);
        $scheme = strtolower($p['scheme'] ?? '');
        $host   = strtolower($p['host'] ?? '');
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

        // Beste Favicon-Quelle bestimmen: custom > detected > Google API
        if ($favicon_url && isSafeFaviconUrl($favicon_url)) {
            $favicon_source = $favicon_url;
        } elseif ($detected_favicon_url && isSafeFaviconUrl($detected_favicon_url)) {
            $favicon_source = $detected_favicon_url;
        } else {
            $host = parse_url($url, PHP_URL_HOST);
            $favicon_source = $host
                ? 'https://www.google.com/s2/favicons?domain=' . urlencode($host) . '&sz=256'
                : null;
        }

        $favicon_stored = $old_favicon; // Fallback: altes Favicon behalten
        $new_favicon_path = null;

        if ($favicon_source) {
            $ch = curl_init($favicon_source);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT      => 'Mozilla/5.0',
            ]);
            $favicon_data = curl_exec($ch);
            $content_type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($favicon_data && strlen($favicon_data) > 100) {
                $ext = '.png';
                if (str_contains($content_type, 'jpeg') || str_contains($content_type, 'jpg')) $ext = '.jpg';
                elseif (str_contains($content_type, 'gif')) $ext = '.gif';
                elseif (str_contains($content_type, 'svg')) $ext = '.svg';

                if (!file_exists('favicons')) mkdir('favicons', 0755, true);

                $local_path = "favicons/favicon_$id$ext";
                if (@file_put_contents($local_path, $favicon_data) !== false) {
                    $new_favicon_path = $local_path;
                    $favicon_stored = '/' . $local_path; // Absoluten Pfad in DB speichern
                }
            } else {
                error_log("Favicon-Download fehlgeschlagen für ID $id, Quelle: $favicon_source");
            }
        }

        // Alte Favicon-Datei löschen, wenn eine neue gespeichert wurde (anderen Namen)
        if ($new_favicon_path && $old_favicon) {
            $old_file = ltrim($old_favicon, '/');
            if ($old_file !== $new_favicon_path && file_exists($old_file)) {
                @unlink($old_file);
            }
        }

        // Datenbank-Update
        $stmt = $pdo->prepare("UPDATE favorites SET title = ?, category_id = ?, url = ?, favicon_url = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([$title, $category_id, $url, $favicon_stored, $id, $user_id]);

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