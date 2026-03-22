<?php
/**
 * Favorites Export/Import Module
 * 
 * Enthält Funktionen zum Exportieren und Importieren von Favorites-Daten
 */

/**
 * Exportiert Favorites und Kategorien eines Benutzers als JSON
 * 
 * @param int $user_id Die ID des Benutzers
 * @param PDO $pdo PDO-Datenbankverbindung
 * @return string JSON-String mit den exportierten Daten
 */
function exportFavoritesData($user_id, $pdo) {
    // Kategorien abrufen
    $stmt = $pdo->prepare("SELECT id, user_id, name, position FROM categories WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Favorites abrufen
    $stmt = $pdo->prepare("SELECT id, user_id, category_id, title, url, favicon_url, created_at FROM favorites WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $favorites = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Tabs abrufen
    $stmt = $pdo->prepare("SELECT id, user_id, name, slug, icon, position, created_at FROM tabs WHERE user_id = ? ORDER BY position ASC, name ASC");
    $stmt->execute([$user_id]);
    $tabs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Kategorie-Tab-Mapping abrufen
    $stmt = $pdo->prepare(
        "SELECT ct.category_id, ct.tab_id
         FROM category_tabs ct
         JOIN categories c ON c.id = ct.category_id
         JOIN tabs t ON t.id = ct.tab_id
         WHERE c.user_id = ? AND t.user_id = ?"
    );
    $stmt->execute([$user_id, $user_id]);
    $categoryTabs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Tab-spezifische Positionen abrufen
    $stmt = $pdo->prepare(
        "SELECT ctp.tab_id, ctp.category_id, ctp.position
         FROM category_tab_positions ctp
         JOIN tabs t ON t.id = ctp.tab_id
         JOIN categories c ON c.id = ctp.category_id
         WHERE t.user_id = ? AND c.user_id = ?"
    );
    $stmt->execute([$user_id, $user_id]);
    $categoryTabPositions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Notes abrufen
    $stmt = $pdo->prepare("SELECT id, user_id, title, content, created_at FROM notes WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Note-Tab-Mapping abrufen
    $stmt = $pdo->prepare(
        "SELECT nt.note_id, nt.tab_id, nt.position, nt.pos_x, nt.pos_y, nt.width, nt.height
         FROM note_tabs nt
         JOIN notes n ON n.id = nt.note_id
         JOIN tabs t ON t.id = nt.tab_id
         WHERE n.user_id = ? AND t.user_id = ?"
    );
    $stmt->execute([$user_id, $user_id]);
    $noteTabs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Daten in einem Array zusammenfassen
    $data = [
        'categories' => $categories,
        'favorites' => $favorites,
        'tabs' => $tabs,
        'category_tabs' => $categoryTabs,
        'category_tab_positions' => $categoryTabPositions,
        'notes' => $notes,
        'note_tabs' => $noteTabs,
        'export_date' => date('Y-m-d H:i:s'),
        'version' => '3.0'
    ];
    
    // In JSON umwandeln
    return json_encode($data, JSON_PRETTY_PRINT);
}

/**
 * Exportiert Favorites als Browser-Lesezeichen im HTML-Format
 * 
 * @param int $user_id Die ID des Benutzers
 * @param PDO $pdo PDO-Datenbankverbindung
 * @return string HTML-String mit den exportierten Lesezeichen
 */
function exportBrowserBookmarks($user_id, $pdo) {
    // Kategorien abrufen
    $stmt = $pdo->prepare("SELECT id, name, position FROM categories WHERE user_id = ? ORDER BY position ASC, name ASC");
    $stmt->execute([$user_id]);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Aktuelles Datum für den Export
    $date = date('Y-m-d H:i:s');
    $dateFormatted = date('D, d M Y H:i:s');
    
    // HTML-Header erstellen
    $html = "<!DOCTYPE NETSCAPE-Bookmark-file-1>\n";
    $html .= "<!-- This is an automatically generated file.\n";
    $html .= "     It will be read and overwritten.\n";
    $html .= "     DO NOT EDIT! -->\n";
    $html .= "<META HTTP-EQUIV=\"Content-Type\" CONTENT=\"text/html; charset=UTF-8\">\n";
    $html .= "<TITLE>Bookmarks</TITLE>\n";
    $html .= "<H1>Bookmarks</H1>\n";
    $html .= "<DL><p>\n";
    $html .= "    <DT><H3 ADD_DATE=\"" . time() . "\" LAST_MODIFIED=\"" . time() . "\" PERSONAL_TOOLBAR_FOLDER=\"true\">Exported Favorites</H3>\n";
    $html .= "    <DL><p>\n";
    
    // Für jede Kategorie die Favoriten hinzufügen
    foreach ($categories as $category) {
        // Kategorie-Header
        $html .= "        <DT><H3 ADD_DATE=\"" . time() . "\" LAST_MODIFIED=\"" . time() . "\">" . htmlspecialchars($category['name']) . "</H3>\n";
        $html .= "        <DL><p>\n";
        
        // Favoriten für diese Kategorie abrufen
        $stmt = $pdo->prepare("SELECT title, url, created_at FROM favorites WHERE user_id = ? AND category_id = ? ORDER BY title ASC");
        $stmt->execute([$user_id, $category['id']]);
        $favorites = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Favoriten zur Kategorie hinzufügen
        foreach ($favorites as $favorite) {
            $addDate = strtotime($favorite['created_at']);
            if (!$addDate) $addDate = time(); // Fallback wenn das Datum ungültig ist
            
            $html .= "            <DT><A HREF=\"" . htmlspecialchars($favorite['url']) . "\" ADD_DATE=\"" . $addDate . "\">" . htmlspecialchars($favorite['title']) . "</A>\n";
        }
        
        // Kategorie abschließen
        $html .= "        </DL><p>\n";
    }
    
    // HTML abschließen
    $html .= "    </DL><p>\n";
    $html .= "</DL><p>\n";
    
    return $html;
}

/**
 * Lädt ein Favicon von einer URL herunter und speichert es
 * 
 * @param string $url Die URL, von der das Favicon geholt werden soll
 * @param int $id Die ID des Favoriten
 * @return string Der Pfad zum gespeicherten Favicon oder null im Fehlerfall
 */
function downloadFavicon($url, $id) {
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host) {
        return null;
    }
    
    $favicon_url = "https://www.google.com/s2/favicons?domain=" . urlencode($host) . "&sz=256";
    
    // Versuche mit curl zu downloaden (wenn verfügbar)
    if (function_exists('curl_init')) {
        $ch = curl_init($favicon_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
        $favicon_data = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        // Fallback auf file_get_contents wenn curl nicht verfügbar
        $favicon_data = @file_get_contents($favicon_url);
        $http_code = $favicon_data !== false ? 200 : 0;
    }

    if ($favicon_data && strlen($favicon_data) > 100 && in_array($http_code, [0, 200], true)) {
        // Stelle sicher, dass das Favicon-Verzeichnis existiert
        if (!file_exists('favicons')) {
            mkdir('favicons', 0755, true);
        }
        
        $new_favicon_path = "favicons/favicon_$id.png";
        $bytes_written = file_put_contents($new_favicon_path, $favicon_data);
        if ($bytes_written !== false && $bytes_written > 0) {
            return $new_favicon_path;
        }
    }
    
    return null;
}

/**
 * Aktualisiert alle Favicons eines Benutzers ohne Import.
 *
 * @param int $user_id Die ID des Benutzers
 * @param PDO $pdo PDO-Datenbankverbindung
 * @return array Ergebnis mit Statistik
 */
function refreshAllUserFavicons($user_id, $pdo) {
    $stmt = $pdo->prepare("SELECT id, url, favicon_url FROM favorites WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $favorites = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total = count($favorites);
    $updated = 0;
    $failed = 0;

    if ($total === 0) {
        return [
            'success' => true,
            'message' => 'Keine Favoriten vorhanden.',
            'stats' => ['total' => 0, 'updated' => 0, 'failed' => 0]
        ];
    }

    $updateStmt = $pdo->prepare("UPDATE favorites SET favicon_url = ? WHERE id = ? AND user_id = ?");

    foreach ($favorites as $favorite) {
        $favoriteId = (int)$favorite['id'];
        $url = (string)($favorite['url'] ?? '');
        if ($url === '') {
            $failed++;
            continue;
        }

        $newFavicon = downloadFavicon($url, $favoriteId);
        if (!$newFavicon) {
            $failed++;
            continue;
        }

        if ($newFavicon !== ($favorite['favicon_url'] ?? '')) {
            $updateStmt->execute([$newFavicon, $favoriteId, $user_id]);
        }
        $updated++;
    }

    return [
        'success' => true,
        'message' => 'Favicon-Aktualisierung abgeschlossen.',
        'stats' => ['total' => $total, 'updated' => $updated, 'failed' => $failed]
    ];
}

/**
 * Erzeugt einen eindeutigen Tab-Slug pro Benutzer.
 */
function buildUniqueTabSlug($user_id, $slug, $pdo, $excludeTabId = null) {
    $base = trim($slug);
    if ($base === '') {
        $base = 'tab';
    }

    $candidate = $base;
    $counter = 2;

    while (true) {
        $sql = "SELECT id FROM tabs WHERE user_id = ? AND slug = ?";
        $params = [$user_id, $candidate];

        if ($excludeTabId !== null) {
            $sql .= " AND id != ?";
            $params[] = $excludeTabId;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        if (!$stmt->fetchColumn()) {
            return $candidate;
        }

        $candidate = substr($base, 0, 110) . '-' . $counter;
        $counter++;
    }
}

/**
 * Importiert Favorites und Kategorien aus einem JSON-String
 * 
 * @param int $user_id Die ID des Benutzers
 * @param string $jsonData JSON-String mit den zu importierenden Daten
 * @param PDO $pdo PDO-Datenbankverbindung
 * @param bool $update_favicons Ob Favicons aktualisiert werden sollen
 * @return array Ergebnis mit Erfolgs-/Fehlerinformationen
 */
function importFavoritesData($user_id, $jsonData, $pdo, $update_favicons = false) {
    // JSON-Daten dekodieren
    $data = json_decode($jsonData, true);
    if (!$data || !isset($data['categories']) || !isset($data['favorites'])) {
        return ['success' => false, 'message' => 'Ungültiges Datenformat.'];
    }
    
    // Arrays fuer Mapping initialisieren
    $categoryMap = [];
    $tabMap = [];
    $updated_favicons = 0;
    
    try {
        // Transaktion starten
        $pdo->beginTransaction();
        
        // Kategorien importieren
        foreach ($data['categories'] as $category) {
            // Prüfen, ob die Kategorie bereits existiert
            $stmt = $pdo->prepare("SELECT id FROM categories WHERE name = ? AND user_id = ?");
            $stmt->execute([$category['name'], $user_id]);
            $existingCategory = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existingCategory) {
                // Kategorie aktualisieren
                $stmt = $pdo->prepare("UPDATE categories SET position = ? WHERE id = ?");
                $stmt->execute([$category['position'], $existingCategory['id']]);
                
                // Original-ID zu aktueller ID mappen für Favorites-Import
                $categoryMap[$category['id']] = $existingCategory['id'];
            } else {
                // Neue Kategorie erstellen
                $stmt = $pdo->prepare("INSERT INTO categories (user_id, name, position) VALUES (?, ?, ?)");
                $stmt->execute([$user_id, $category['name'], $category['position']]);
                
                // Original-ID zu neuer ID mappen für Favorites-Import
                $categoryMap[$category['id']] = $pdo->lastInsertId();
            }
        }

        // Tabs importieren (ab Version 2.0), sonst Default-Tab verwenden
        $importTabs = isset($data['tabs']) && is_array($data['tabs']) ? $data['tabs'] : [];
        if (!empty($importTabs)) {
            foreach ($importTabs as $tab) {
                $name = trim($tab['name'] ?? '');
                if ($name === '') {
                    continue;
                }

                $slug = trim($tab['slug'] ?? '');
                if ($slug === '') {
                    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
                    $slug = trim($slug, '-');
                    if ($slug === '') {
                        $slug = 'tab';
                    }
                }

                $icon = trim($tab['icon'] ?? 'T');
                if ($icon === '') {
                    $icon = 'T';
                }

                $position = (int)($tab['position'] ?? 0);

                $stmt = $pdo->prepare("SELECT id FROM tabs WHERE user_id = ? AND name = ?");
                $stmt->execute([$user_id, $name]);
                $existingTab = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($existingTab) {
                    $slug = buildUniqueTabSlug($user_id, $slug, $pdo, (int)$existingTab['id']);
                    $stmt = $pdo->prepare("UPDATE tabs SET slug = ?, icon = ?, position = ? WHERE id = ? AND user_id = ?");
                    $stmt->execute([$slug, $icon, $position, $existingTab['id'], $user_id]);
                    $tabMap[$tab['id']] = (int)$existingTab['id'];
                } else {
                    $slug = buildUniqueTabSlug($user_id, $slug, $pdo);
                    $stmt = $pdo->prepare("INSERT INTO tabs (user_id, name, slug, icon, position) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$user_id, $name, $slug, $icon, $position]);
                    $tabMap[$tab['id']] = (int)$pdo->lastInsertId();
                }
            }
        }

        // Default-Tab sicherstellen
        $stmt = $pdo->prepare("SELECT id FROM tabs WHERE user_id = ? AND slug = 'alle' LIMIT 1");
        $stmt->execute([$user_id]);
        $defaultTabId = $stmt->fetchColumn();

        if (!$defaultTabId) {
            $stmt = $pdo->prepare("SELECT COALESCE(MAX(position), -1) + 1 FROM tabs WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $position = (int)$stmt->fetchColumn();

            $stmt = $pdo->prepare("INSERT INTO tabs (user_id, name, slug, icon, position) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$user_id, 'Alle', 'alle', 'A', $position]);
            $defaultTabId = (int)$pdo->lastInsertId();
        } else {
            $defaultTabId = (int)$defaultTabId;
        }

        // Kategorie-Tab-Mapping importieren oder auf Default setzen
        $importCategoryTabs = isset($data['category_tabs']) && is_array($data['category_tabs']) ? $data['category_tabs'] : [];
        $hasImportedCategoryTabs = false;

        if (!empty($importCategoryTabs)) {
            $insertMapStmt = $pdo->prepare("INSERT IGNORE INTO category_tabs (category_id, tab_id) VALUES (?, ?)");
            $insertPosStmt = $pdo->prepare(
                "INSERT INTO category_tab_positions (tab_id, category_id, position)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE position = VALUES(position)"
            );

            foreach ($importCategoryTabs as $mapping) {
                $mappedCategoryId = $categoryMap[$mapping['category_id']] ?? null;
                $mappedTabId = $tabMap[$mapping['tab_id']] ?? null;

                if (!$mappedCategoryId || !$mappedTabId) {
                    continue;
                }

                $insertMapStmt->execute([$mappedCategoryId, $mappedTabId]);
                $insertPosStmt->execute([$mappedTabId, $mappedCategoryId, 0]);
                $hasImportedCategoryTabs = true;
            }
        }

        if (!$hasImportedCategoryTabs) {
            $insertMapStmt = $pdo->prepare("INSERT IGNORE INTO category_tabs (category_id, tab_id) VALUES (?, ?)");
            $insertPosStmt = $pdo->prepare(
                "INSERT INTO category_tab_positions (tab_id, category_id, position)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE position = VALUES(position)"
            );

            foreach ($categoryMap as $originalCategoryId => $mappedCategoryId) {
                $position = 0;
                foreach ($data['categories'] as $category) {
                    if ((string)$category['id'] === (string)$originalCategoryId) {
                        $position = (int)($category['position'] ?? 0);
                        break;
                    }
                }

                $insertMapStmt->execute([$mappedCategoryId, $defaultTabId]);
                $insertPosStmt->execute([$defaultTabId, $mappedCategoryId, $position]);
            }
        }

        // Tab-spezifische Positionen importieren
        $importPositions = isset($data['category_tab_positions']) && is_array($data['category_tab_positions'])
            ? $data['category_tab_positions']
            : [];

        if (!empty($importPositions)) {
            $stmt = $pdo->prepare(
                "INSERT INTO category_tab_positions (tab_id, category_id, position)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE position = VALUES(position)"
            );

            foreach ($importPositions as $positionData) {
                $mappedTabId = $tabMap[$positionData['tab_id']] ?? null;
                $mappedCategoryId = $categoryMap[$positionData['category_id']] ?? null;

                if (!$mappedTabId || !$mappedCategoryId) {
                    continue;
                }

                $stmt->execute([$mappedTabId, $mappedCategoryId, (int)($positionData['position'] ?? 0)]);
            }
        }

        // Alle Kategorien mindestens dem Default-Tab zuordnen
        $insertMapStmt = $pdo->prepare("INSERT IGNORE INTO category_tabs (category_id, tab_id) VALUES (?, ?)");
        $insertPosStmt = $pdo->prepare(
            "INSERT INTO category_tab_positions (tab_id, category_id, position)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE position = VALUES(position)"
        );

        foreach ($categoryMap as $originalCategoryId => $mappedCategoryId) {
            $position = 0;
            foreach ($data['categories'] as $category) {
                if ((string)$category['id'] === (string)$originalCategoryId) {
                    $position = (int)($category['position'] ?? 0);
                    break;
                }
            }

            $insertMapStmt->execute([$mappedCategoryId, $defaultTabId]);
            $insertPosStmt->execute([$defaultTabId, $mappedCategoryId, $position]);
        }
        
        // Favorites importieren
        foreach ($data['favorites'] as $favorite) {
            // Kategorie-ID mappen
            $mappedCategoryId = $categoryMap[$favorite['category_id']] ?? null;
            if (!$mappedCategoryId) {
                // Wenn keine passende Kategorie gefunden, Standardkategorie verwenden
                $stmt = $pdo->prepare("SELECT id FROM categories WHERE user_id = ? ORDER BY position ASC LIMIT 1");
                $stmt->execute([$user_id]);
                $mappedCategoryId = $stmt->fetchColumn();
                
                // Falls keine Kategorie vorhanden, eine erstellen
                if (!$mappedCategoryId) {
                    $stmt = $pdo->prepare("INSERT INTO categories (user_id, name, position) VALUES (?, ?, ?)");
                    $stmt->execute([$user_id, 'Importierte Kategorie', 0]);
                    $mappedCategoryId = $pdo->lastInsertId();
                }
            }
            
            // Prüfen, ob das Favorite bereits existiert
            $stmt = $pdo->prepare("SELECT id FROM favorites WHERE url = ? AND user_id = ?");
            $stmt->execute([$favorite['url'], $user_id]);
            $existingFavorite = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Favicon-Behandlung
            $favicon_url = $favorite['favicon_url'];
            
            if ($existingFavorite) {
                // Favorit existiert bereits
                $favorite_id = $existingFavorite['id'];
                
                // Favicon aktualisieren, wenn gewünscht
                if ($update_favicons) {
                    $new_favicon = downloadFavicon($favorite['url'], $favorite_id);
                    if ($new_favicon) {
                        $favicon_url = $new_favicon;
                        $updated_favicons++;
                    }
                }
                
                // Favorite aktualisieren
                $stmt = $pdo->prepare("UPDATE favorites SET category_id = ?, title = ?, favicon_url = ? WHERE id = ?");
                $stmt->execute([$mappedCategoryId, $favorite['title'], $favicon_url, $favorite_id]);
            } else {
                // Neues Favorite erstellen
                $stmt = $pdo->prepare("INSERT INTO favorites (user_id, category_id, title, url, favicon_url, created_at) 
                                      VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $user_id, 
                    $mappedCategoryId, 
                    $favorite['title'], 
                    $favorite['url'], 
                    $favicon_url,
                    date('Y-m-d H:i:s')
                ]);
                
                // ID des neuen Favoriten abrufen
                $favorite_id = $pdo->lastInsertId();
                
                // Favicon aktualisieren, wenn gewünscht
                if ($update_favicons) {
                    $new_favicon = downloadFavicon($favorite['url'], $favorite_id);
                    if ($new_favicon) {
                        $stmt = $pdo->prepare("UPDATE favorites SET favicon_url = ? WHERE id = ?");
                        $stmt->execute([$new_favicon, $favorite_id]);
                        $updated_favicons++;
                    }
                }
            }
        }
        
        // Notes importieren (ab Version 3.0, optional für Backwards-Kompatibilität)
        $importNotes = isset($data['notes']) && is_array($data['notes']) ? $data['notes'] : [];
        $noteMap = [];

        if (!empty($importNotes)) {
            foreach ($importNotes as $note) {
                $title = trim($note['title'] ?? 'Note');
                if ($title === '') $title = 'Note';
                $content = $note['content'] ?? null;
                $createdAt = $note['created_at'] ?? date('Y-m-d H:i:s');

                $stmt = $pdo->prepare("SELECT id FROM notes WHERE title = ? AND user_id = ?");
                $stmt->execute([$title, $user_id]);
                $existingNote = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($existingNote) {
                    $stmt = $pdo->prepare("UPDATE notes SET content = ? WHERE id = ? AND user_id = ?");
                    $stmt->execute([$content, $existingNote['id'], $user_id]);
                    $noteMap[$note['id']] = (int)$existingNote['id'];
                } else {
                    $stmt = $pdo->prepare("INSERT INTO notes (user_id, title, content, created_at) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$user_id, $title, $content, $createdAt]);
                    $noteMap[$note['id']] = (int)$pdo->lastInsertId();
                }
            }
        }

        // Note-Tab-Mapping importieren
        $importNoteTabs = isset($data['note_tabs']) && is_array($data['note_tabs']) ? $data['note_tabs'] : [];

        if (!empty($importNoteTabs) && !empty($noteMap)) {
            $insertNoteTabStmt = $pdo->prepare(
                "INSERT INTO note_tabs (note_id, tab_id, position, pos_x, pos_y, width, height)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE position = VALUES(position), pos_x = VALUES(pos_x),
                     pos_y = VALUES(pos_y), width = VALUES(width), height = VALUES(height)"
            );

            foreach ($importNoteTabs as $mapping) {
                $mappedNoteId = $noteMap[$mapping['note_id']] ?? null;
                $mappedTabId  = $tabMap[$mapping['tab_id']] ?? null;
                if (!$mappedNoteId || !$mappedTabId) continue;

                $insertNoteTabStmt->execute([
                    $mappedNoteId,
                    $mappedTabId,
                    (int)($mapping['position'] ?? 0),
                    isset($mapping['pos_x']) && $mapping['pos_x'] !== null ? (int)$mapping['pos_x'] : null,
                    isset($mapping['pos_y']) && $mapping['pos_y'] !== null ? (int)$mapping['pos_y'] : null,
                    (int)($mapping['width'] ?? 360),
                    (int)($mapping['height'] ?? 200),
                ]);
            }
        }

        // Alle importierten Notes mindestens dem Default-Tab zuordnen
        if (!empty($noteMap)) {
            $insertDefaultNoteTab = $pdo->prepare(
                "INSERT IGNORE INTO note_tabs (note_id, tab_id, position) VALUES (?, ?, 0)"
            );
            foreach ($noteMap as $mappedNoteId) {
                $insertDefaultNoteTab->execute([$mappedNoteId, $defaultTabId]);
            }
        }

        // Transaktion abschließen
        $pdo->commit();
        
        $message = 'Daten erfolgreich importiert.';
        if ($update_favicons && $updated_favicons > 0) {
            $message .= " $updated_favicons Favicons wurden aktualisiert.";
        }
        
        return [
            'success' => true, 
            'message' => $message,
            'stats' => [
                'categories' => count($data['categories']),
                'favorites' => count($data['favorites']),
                'tabs' => count($importTabs),
                'notes' => count($importNotes),
                'updated_favicons' => $updated_favicons
            ]
        ];
    } catch (Exception $e) {
        // Bei Fehler Transaktion zurückrollen
        $pdo->rollBack();
        return ['success' => false, 'message' => 'Fehler beim Import: ' . $e->getMessage()];
    }
}

/**
 * Verarbeitet Export-Anfrage und sendet Datei als Download
 * 
 * @param int $user_id Die ID des Benutzers
 * @param PDO $pdo PDO-Datenbankverbindung
 */
function handleExportRequest($user_id, $pdo) {
    // Daten exportieren
    $jsonData = exportFavoritesData($user_id, $pdo);
    
    // Download-Header setzen
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="favorites_backup_' . date('Y-m-d') . '.json"');
    header('Content-Length: ' . strlen($jsonData));
    echo $jsonData;
    exit;
}

/**
 * Verarbeitet Browser-Export-Anfrage und sendet Datei als Download
 * 
 * @param int $user_id Die ID des Benutzers
 * @param PDO $pdo PDO-Datenbankverbindung
 */
function handleBrowserExportRequest($user_id, $pdo) {
    // Daten als Browser-Lesezeichen exportieren
    $htmlData = exportBrowserBookmarks($user_id, $pdo);
    
    // Download-Header setzen
    header('Content-Type: text/html');
    header('Content-Disposition: attachment; filename="favorites_browser_' . date('Y-m-d') . '.html"');
    header('Content-Length: ' . strlen($htmlData));
    echo $htmlData;
    exit;
}

/**
 * Verarbeitet Import-Anfrage
 * 
 * @param int $user_id Die ID des Benutzers
 * @param array $file Hochgeladene Datei ($_FILES['import_file'])
 * @param PDO $pdo PDO-Datenbankverbindung
 * @return array Ergebnis mit Erfolgs-/Fehlerinformationen
 */
function handleImportRequest($user_id, $file, $pdo) {
    if ($file['error'] != 0) {
        return ['success' => false, 'message' => 'Fehler beim Datei-Upload: Code ' . $file['error']];
    }
    
    // Dateiendung prüfen
    $fileExtension = pathinfo($file['name'], PATHINFO_EXTENSION);
    if (strtolower($fileExtension) !== 'json') {
        return ['success' => false, 'message' => 'Nur JSON-Dateien sind erlaubt.'];
    }
    
    // Datei einlesen
    $fileTmpPath = $file['tmp_name'];
    $fileContent = file_get_contents($fileTmpPath);
    
    // Import ausführen
    return importFavoritesData($user_id, $fileContent, $pdo);
}