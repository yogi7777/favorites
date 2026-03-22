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
 * Normalisiert einen Favicon-Pfad auf einen absoluten Pfad
 * Konvertiert sowohl relative als auch absolute Pfade zu absoluten
 * 
 * @param string $faviconUrl Der Favicon-URL oder -Pfad aus der Datenbank
 * @return string Der normalisierte absolute Pfad (z.B. /favicons/favicon_123.png)
 */
function normalizeFaviconPath(string $faviconUrl): string {
    if (empty($faviconUrl)) {
        return '';
    }

    // Prüfe, ob es eine vollständige URL ist (http/https)
    if (strpos($faviconUrl, 'http://') === 0 || strpos($faviconUrl, 'https://') === 0) {
        return $faviconUrl;
    }

    // Prüfe, ob es bereits ein absoluter Pfad ist
    if (strpos($faviconUrl, '/') === 0) {
        return $faviconUrl;
    }

    // Konvertiere relative Pfade (favicons/favicon_123.png) zu absolut (/favicons/favicon_123.png)
    if (strpos($faviconUrl, 'favicons/') === 0) {
        return '/' . $faviconUrl;
    }

    // Fallback für andere Fälle
    return '/' . $faviconUrl;
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

    // Hilfsfunktion: URL abrufen, auf Bild-Content-Type prüfen
    $fetchImage = function(string $src): ?array {
        $ch = curl_init($src);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0',
        ]);
        $data        = curl_exec($ch);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        if (!$data || strlen($data) < 100 || !str_contains($contentType, 'image/')) return null;
        return ['data' => $data, 'type' => $contentType];
    };

    $result = null;

    // 1. Bevorzugte URL (aus JS-Erkennung oder Custom-URL)
    if ($preferredUrl && (str_starts_with($preferredUrl, 'http://') || str_starts_with($preferredUrl, 'https://'))) {
        $result = $fetchImage($preferredUrl);
    }

    // 2. /favicon.ico direkt
    if (!$result) {
        $result = $fetchImage($baseUrl . '/favicon.ico');
    }

    // 3. HTML-Parsing: <link rel="icon">
    if (!$result) {
        $ch = curl_init($pageUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0',
            CURLOPT_MAXREDIRS      => 5,
        ]);
        $html = curl_exec($ch);
        curl_close($ch);

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
                    $result = $fetchImage($candidate);
                    if ($result) break;
                }
            }
        }
    }

    // 4. Google Favicon API als letzter Fallback
    if (!$result) {
        $result = $fetchImage('https://www.google.com/s2/favicons?domain=' . urlencode($host) . '&sz=256');
    }

    if (!$result) {
        error_log("detectAndDownloadFavicon ($id): Alle Quellen fehlgeschlagen für $pageUrl");
        return null;
    }

    $ext = '.png';
    if (str_contains($result['type'], 'jpeg') || str_contains($result['type'], 'jpg')) $ext = '.jpg';
    elseif (str_contains($result['type'], 'gif'))  $ext = '.gif';
    elseif (str_contains($result['type'], 'svg'))  $ext = '.svg';

    $favDir = __DIR__ . '/favicons';
    if (!file_exists($favDir) && !mkdir($favDir, 0755, true)) {
        error_log("detectAndDownloadFavicon ($id): Verzeichnis konnte nicht erstellt werden");
        return null;
    }

    $path  = 'favicons/favicon_' . $id . $ext;
    $bytes = @file_put_contents(__DIR__ . '/' . $path, $result['data']);
    if (!$bytes) {
        error_log("detectAndDownloadFavicon ($id): Schreiben fehlgeschlagen: $path");
        return null;
    }

    error_log("detectAndDownloadFavicon ($id): $bytes bytes → $path");
    return '/' . $path;
}
