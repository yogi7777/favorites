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

        $favicon_stored = $old_favicon; // Standardmässig altes Favicon behalten
        $new_favicon_path = null;

        // Favicon nur neu laden wenn:
        // (a) Benutzer eine eigene URL angegeben hat, ODER
        // (b) Eine neue Remote-URL erkannt wurde (URL wurde im Modal geändert)
        // NICHT neu laden wenn detected_favicon_url ein lokaler Pfad ist (URL unverändert)
        $isLocalPath = str_starts_with((string)$detected_favicon_url, '/');
        $needsDownload = false;
        $favicon_sources = [];

        if ($favicon_url && isSafeFaviconUrl($favicon_url)) {
            // Benutzer hat explizit eine Custom-URL eingegeben
            $favicon_sources[] = $favicon_url;
            $needsDownload = true;
        } elseif (!$isLocalPath && $detected_favicon_url && isSafeFaviconUrl($detected_favicon_url)) {
            // URL wurde geändert → neue Remote-URL erkannt
            $favicon_sources[] = $detected_favicon_url;
            $needsDownload = true;
        }
        // sonst: URL unverändert → vorhandenes lokales Favicon behalten

        if ($needsDownload) {
            // Google API immer als letzter Fallback
            $host = parse_url($url, PHP_URL_HOST);
            if ($host) {
                $favicon_sources[] = 'https://www.google.com/s2/favicons?domain=' . urlencode($host) . '&sz=256';
            }

            if (!file_exists('favicons')) mkdir('favicons', 0755, true);

            foreach ($favicon_sources as $src) {
                $ch = curl_init($src);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT        => 5,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_USERAGENT      => 'Mozilla/5.0',
                ]);
                $favicon_data = curl_exec($ch);
                $content_type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
                curl_close($ch);

                // Nur echte Bilder akzeptieren
                if (!$favicon_data || strlen($favicon_data) < 100 || !str_contains($content_type, 'image/')) {
                    error_log("edit_favorite: Download fehlgeschlagen oder kein Bild für ID $id, Quelle: $src (Content-Type: $content_type)");
                    continue;
                }

                $ext = '.png';
                if (str_contains($content_type, 'jpeg') || str_contains($content_type, 'jpg')) $ext = '.jpg';
                elseif (str_contains($content_type, 'gif')) $ext = '.gif';
                elseif (str_contains($content_type, 'svg')) $ext = '.svg';

                $local_path = "favicons/favicon_$id$ext";
                if (@file_put_contents($local_path, $favicon_data) !== false) {
                    $new_favicon_path = $local_path;
                    $favicon_stored = '/' . $local_path;
                }
                break; // Erfolg
            }
        }

        // Alte Favicon-Datei löschen wenn eine neue gespeichert wurde und Name sich unterscheidet
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