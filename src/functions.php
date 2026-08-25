<?php

function slugifyTabName(string $value): string {
    $value = trim(strtolower($value));
    $value = preg_replace('/[^a-z0-9]+/u', '-', $value) ?? '';
    $value = trim($value, '-');

    if ($value === '') {
        return 'tab';
    }

    return substr($value, 0, 120);
}

function uniqueTabSlug(PDO $pdo, int $userId, string $baseSlug, ?int $excludeId = null): string {
    $slug = $baseSlug;
    $suffix = 2;

    while (true) {
        $sql = 'SELECT id FROM tabs WHERE user_id = ? AND slug = ?';
        $params = [$userId, $slug];

        if ($excludeId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $excludeId;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        if (!$stmt->fetchColumn()) {
            return $slug;
        }

        $slug = substr($baseSlug, 0, 110) . '-' . $suffix;
        $suffix++;
    }
}

function ensureDefaultTab(PDO $pdo, int $userId): void {
    $stmt = $pdo->prepare('SELECT id FROM tabs WHERE user_id = ? AND slug = ? LIMIT 1');
    $stmt->execute([$userId, 'alle']);
    $tabId = $stmt->fetchColumn();

    if ($tabId) {
        return;
    }

    $stmt = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM tabs WHERE user_id = ?');
    $stmt->execute([$userId]);
    $position = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare('INSERT INTO tabs (user_id, name, slug, icon, position) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$userId, 'Alle', 'alle', 'A', $position]);
    $defaultTabId = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare('SELECT id, COALESCE(position, 0) AS position FROM categories WHERE user_id = ?');
    $stmt->execute([$userId]);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $insertMap = $pdo->prepare('INSERT IGNORE INTO category_tabs (category_id, tab_id) VALUES (?, ?)');
    $insertPos = $pdo->prepare('INSERT INTO category_tab_positions (tab_id, category_id, position) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE position = VALUES(position)');

    foreach ($categories as $category) {
        $insertMap->execute([$category['id'], $defaultTabId]);
        $insertPos->execute([$defaultTabId, $category['id'], $category['position']]);
    }
}

function extractFirstEmoji(string $name): string {
    if (preg_match('/^\S+/u', trim($name), $m)) {
        return $m[0];
    }
    return mb_substr($name, 0, 1);
}

function resolveActiveTab(array $tabs, string $requested): array {
    $activeTabSlug = trim($requested) !== '' ? trim($requested) : 'alle';
    $activeTab = null;

    foreach ($tabs as $tab) {
        if ($tab['slug'] === $activeTabSlug) {
            $activeTab = $tab;
            break;
        }
    }

    if ($activeTabSlug !== 'alle' && !$activeTab) {
        $activeTabSlug = 'alle';
    }

    return [$activeTabSlug, $activeTab];
}

/**
 * Absolute filesystem path inside the public document root.
 */
function favorites_public_file(string $relative): string {
    $relative = ltrim($relative, '/');
    $base = defined('PUBLIC_DIR') ? PUBLIC_DIR : (defined('APP_ROOT') ? APP_ROOT . '/public' : '');

    return $base . '/' . $relative;
}

/**
 * Local favicon file path, or null for empty / remote URLs.
 */
function favorites_local_favicon_file(?string $storedPath): ?string {
    if ($storedPath === null || $storedPath === '' || str_starts_with($storedPath, 'http://') || str_starts_with($storedPath, 'https://')) {
        return null;
    }

    return favorites_public_file($storedPath);
}

/**
 * Normalisiert einen Favicon-Pfad auf einen relativen Pfad
 * Externe URLs werden unverändert zurückgegeben, lokale Pfade werden relativ gemacht.
 *
 * @param string $faviconUrl Der Favicon-URL oder -Pfad aus der Datenbank
 * @return string Relativer Pfad (z.B. favicons/favicon_123.png) oder externe URL
 */
function normalizeFaviconPath(string $faviconUrl): string {
    if (empty($faviconUrl)) {
        return '';
    }

    // Externe URLs unverändert zurückgeben
    if (strpos($faviconUrl, 'http://') === 0 || strpos($faviconUrl, 'https://') === 0) {
        return $faviconUrl;
    }

    // Führende Slashes entfernen → immer relativer Pfad (funktioniert in jedem Unterordner)
    return ltrim($faviconUrl, '/');
}

/**
 * Ruft eine URL ab und prüft, ob die Antwort ein Bild ist.
 * @return array{data:string,type:string}|null
 */
function fetchFaviconImage(string $src): ?array {
    $ch = curl_init($src);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        CURLOPT_ENCODING       => '',
    ]);
    $data        = curl_exec($ch);
    $curlError   = curl_error($ch);
    $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    // Entpacke gzip, falls curl es nicht selbst tut
    if (!empty($data) && strpos($data, "\x1f\x8b") === 0) {
        $decoded = @gzdecode($data);
        if ($decoded !== false) {
            $data = $decoded;
        }
    }

    if (!$data || strlen($data) < 100 || !str_contains($contentType, 'image/')) {
        error_log("fetchFaviconImage: fehlgeschlagen für $src (HTTP $httpCode, Content-Type: $contentType, curl: $curlError)");
        return null;
    }
    return ['data' => $data, 'type' => $contentType];
}

/**
 * Speichert ein abgerufenes Favicon-Bild lokal für den gegebenen Favoriten.
 * @param array{data:string,type:string} $result
 * @return string|null Relativer Pfad (favicons/favicon_N.ext) oder null
 */
function saveFaviconFile(array $result, int $id): ?string {
    $ext = '.png';
    if (str_contains($result['type'], 'jpeg') || str_contains($result['type'], 'jpg')) $ext = '.jpg';
    elseif (str_contains($result['type'], 'gif'))  $ext = '.gif';
    elseif (str_contains($result['type'], 'svg'))  $ext = '.svg';

    $favDir = defined('FAVICONS_DIR') ? FAVICONS_DIR : favorites_public_file('favicons');
    if (!file_exists($favDir) && !mkdir($favDir, 0755, true)) {
        error_log("saveFaviconFile ($id): Verzeichnis konnte nicht erstellt werden");
        return null;
    }

    $path  = 'favicons/favicon_' . $id . $ext;
    $bytes = @file_put_contents($favDir . '/' . basename($path), $result['data']);
    if (!$bytes) {
        error_log("saveFaviconFile ($id): Schreiben fehlgeschlagen: $path");
        return null;
    }

    error_log("saveFaviconFile ($id): $bytes bytes → $path");
    return $path;
}

/**
 * Lädt ein Favicon von genau der angegebenen URL herunter, ohne Fallback.
 * Wird für vom Benutzer explizit angegebene Custom-URLs verwendet: schlägt
 * die URL fehl, soll NICHT stillschweigend ein Ersatz-Icon gesetzt werden.
 *
 * @return string|null Relativer Pfad (favicons/favicon_N.ext) oder null
 */
function downloadFaviconFromUrl(string $src, int $id): ?string {
    $result = fetchFaviconImage($src);
    if (!$result) return null;
    return saveFaviconFile($result, $id);
}

/**
 * Erkennt das Favicon einer Webseite und speichert es lokal.
 * Reihenfolge: preferredUrl → /favicon.ico → HTML-Parsing → Google API
 *
 * @param string $pageUrl      URL der Webseite
 * @param int    $id           ID des Favoriten (für Dateinamen)
 * @param string $preferredUrl Optional: bereits erkannte URL (z.B. vom JS-Modal)
 * @return string|null  Lokaler absoluter Pfad (/favicons/favicon_N.ext) oder null
 */
function detectAndDownloadFavicon(string $pageUrl, int $id, string $preferredUrl = ''): ?string {
    $parsed = parse_url($pageUrl);
    $host   = strtolower($parsed['host'] ?? '');
    $scheme = strtolower($parsed['scheme'] ?? 'https');
    if (!$host) return null;

    $baseUrl = $scheme . '://' . $host;
    $result  = null;

    // 1. Bevorzugte URL (aus JS-Erkennung)
    if ($preferredUrl && (str_starts_with($preferredUrl, 'http://') || str_starts_with($preferredUrl, 'https://'))) {
        $result = fetchFaviconImage($preferredUrl);
    }

    // 2. /favicon.ico direkt
    if (!$result) {
        $result = fetchFaviconImage($baseUrl . '/favicon.ico');
    }

    // 3. HTML-Parsing: <link rel="icon">
    if (!$result) {
        $ch = curl_init($pageUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_ENCODING       => '',
        ]);
        $html = curl_exec($ch);
        curl_close($ch);

        if (!empty($html) && strpos($html, "\x1f\x8b") === 0) {
            $decoded = @gzdecode($html);
            if ($decoded !== false) {
                $html = $decoded;
            }
        }

        if ($html) {
            $patterns = [
                '/<link[^>]+rel=["\']apple-touch-icon["\'][^>]+href=["\']([^"\']+)["\'][^>]*>/i',
                '/<link[^>]+rel=["\']icon["\'][^>]+href=["\']([^"\']+)["\'][^>]*>/i',
                '/<link[^>]+rel=["\']shortcut icon["\'][^>]+href=["\']([^"\']+)["\'][^>]*>/i',
                '/<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\']apple-touch-icon["\'][^>]*>/i',
                '/<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\']icon["\'][^>]*>/i',
                '/<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\']shortcut icon["\'][^>]*>/i',
            ];
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $html, $match)) {
                    $found = trim($match[1]);
                    if (!$found) continue;
                    if      (str_starts_with($found, 'http://') || str_starts_with($found, 'https://')) $candidate = $found;
                    elseif  (str_starts_with($found, '//'))  $candidate = $scheme . ':' . $found;
                    elseif  (str_starts_with($found, '/'))   $candidate = $baseUrl . $found;
                    else    $candidate = $baseUrl . '/' . $found;
                    $result = fetchFaviconImage($candidate);
                    if ($result) break;
                }
            }
        }
    }

    // 4. Google Favicon API als letzter Fallback
    if (!$result) {
        $result = fetchFaviconImage('https://www.google.com/s2/favicons?domain=' . urlencode($host) . '&sz=256');
    }

    if (!$result) {
        error_log("detectAndDownloadFavicon ($id): Alle Quellen fehlgeschlagen für $pageUrl");
        return null;
    }

    return saveFaviconFile($result, $id);
}
