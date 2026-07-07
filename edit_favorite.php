<?php
require_once 'config.php';
require_once 'auth.php';
require_once __DIR__ . '/functions.php';
checkAuth();
verifyCsrfRequest();

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

    // Custom-URL angegeben, aber unsicher (SSRF-Filter) → sofort abbrechen, nichts speichern
    if ($favicon_url && !isSafeFaviconUrl($favicon_url)) {
        http_response_code(400);
        echo json_encode(['error' => 'Custom-Favicon-URL ist nicht erlaubt (ungültiges Schema oder interne Adresse).']);
        exit;
    }

    try {
        // Bestehendes Favicon abrufen
        $stmt = $pdo->prepare("SELECT favicon_url FROM favorites WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $user_id]);
        $old_favicon = $stmt->fetchColumn();

        $favicon_stored = $old_favicon; // Standardmässig altes Favicon behalten
        $new_favicon_path = null;

        if ($favicon_url) {
            // Benutzer hat explizit eine Custom-URL eingegeben: NUR diese versuchen,
            // kein automatischer Fallback (z.B. Google) – schlägt sie fehl, brechen wir ab,
            // damit der Benutzer die URL korrigieren oder leeren und erneut speichern kann.
            $local_favicon = downloadFaviconFromUrl($favicon_url, $id);
            if (!$local_favicon) {
                http_response_code(400);
                echo json_encode(['error' => 'Custom-Favicon-URL konnte nicht geladen werden (nicht erreichbar oder kein gültiges Bild). Bitte URL korrigieren oder Feld leeren und erneut speichern.']);
                exit;
            }
            $new_favicon_path = ltrim($local_favicon, '/');
            $favicon_stored   = $local_favicon;
        } else {
            // Keine Custom-URL: Favicon nur neu laden, wenn eine neue Remote-URL erkannt wurde
            // (URL wurde im Modal geändert). NICHT neu laden wenn detected_favicon_url ein
            // lokaler Pfad ist (URL unverändert).
            $isLocalPath = str_starts_with((string)$detected_favicon_url, '/')
                        || str_starts_with((string)$detected_favicon_url, 'favicons/');
            if (!$isLocalPath && $detected_favicon_url && isSafeFaviconUrl($detected_favicon_url)) {
                $preferred = str_contains($detected_favicon_url, 'google.com/s2/favicons') ? '' : $detected_favicon_url;
                $local_favicon = detectAndDownloadFavicon($url, $id, $preferred);
                if ($local_favicon) {
                    $new_favicon_path = ltrim($local_favicon, '/');
                    $favicon_stored   = $local_favicon;
                }
            }
        }

        // Alte Favicon-Datei löschen wenn eine neue gespeichert wurde und Name sich unterscheidet
        if ($new_favicon_path && $old_favicon && !str_starts_with($old_favicon, 'http')) {
            $old_file = ltrim($old_favicon, '/');
            if ($old_file !== $new_favicon_path) {
                $old_file_path = __DIR__ . '/' . $old_file;
                if (file_exists($old_file_path)) {
                    @unlink($old_file_path);
                }
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