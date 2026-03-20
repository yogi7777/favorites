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
