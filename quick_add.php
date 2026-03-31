<?php
declare(strict_types=1);
require_once 'config.php';
require_once 'auth.php';
require_once 'functions.php';
checkAuth();

header('Content-Type: application/json');

$userId = (int)$_SESSION['user_id'];
$action = $_POST['action'] ?? '';

$stmt = $pdo->prepare('SELECT id, slug FROM tabs WHERE user_id = ? ORDER BY position ASC, name ASC');
$stmt->execute([$userId]);
$tabs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$defaultTabId       = null;
$validNonDefaultIds = [];
foreach ($tabs as $tab) {
    if ($tab['slug'] === 'alle') {
        $defaultTabId = (int)$tab['id'];
    } else {
        $validNonDefaultIds[] = (int)$tab['id'];
    }
}

if ($action === 'add_note') {
    $title        = trim($_POST['name'] ?? '');
    $selectedTabs = [];
    foreach ($_POST['note_tabs'] ?? [] as $tid) {
        $tid = (int)$tid;
        if (in_array($tid, $validNonDefaultIds, true)) {
            $selectedTabs[] = $tid;
        }
    }
    $selectedTabs = array_values(array_unique($selectedTabs));

    if ($title === '') {
        echo json_encode(['success' => false, 'error' => 'Title is required.']);
        exit;
    }
    if (empty($selectedTabs)) {
        echo json_encode(['success' => false, 'error' => 'Please select at least one tab.']);
        exit;
    }
    if ($defaultTabId === null) {
        echo json_encode(['success' => false, 'error' => 'Default tab not found.']);
        exit;
    }

    $stmt = $pdo->prepare('INSERT INTO notes (user_id, title) VALUES (?, ?)');
    $stmt->execute([$userId, $title]);
    $noteId = (int)$pdo->lastInsertId();

    $tabsToAssign = array_values(array_unique(array_merge([$defaultTabId], $selectedTabs)));
    $maxPos       = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM note_tabs WHERE tab_id = ?');
    $ins          = $pdo->prepare('INSERT INTO note_tabs (note_id, tab_id, position) VALUES (?, ?, ?)');
    foreach ($tabsToAssign as $tid) {
        $maxPos->execute([$tid]);
        $ins->execute([$noteId, $tid, (int)$maxPos->fetchColumn()]);
    }

    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'add_category') {
    $name         = trim($_POST['name'] ?? '');
    $selectedTabs = [];
    foreach ($_POST['cat_tabs'] ?? [] as $tid) {
        $tid = (int)$tid;
        if (in_array($tid, $validNonDefaultIds, true)) {
            $selectedTabs[] = $tid;
        }
    }
    $selectedTabs = array_values(array_unique($selectedTabs));

    if ($name === '') {
        echo json_encode(['success' => false, 'error' => 'Name is required.']);
        exit;
    }
    if (empty($selectedTabs)) {
        echo json_encode(['success' => false, 'error' => 'Please select at least one tab.']);
        exit;
    }
    if ($defaultTabId === null) {
        echo json_encode(['success' => false, 'error' => 'Default tab not found.']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM categories WHERE user_id = ?');
    $stmt->execute([$userId]);
    $newPosition = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare('INSERT INTO categories (user_id, name, position) VALUES (?, ?, ?)');
    $stmt->execute([$userId, $name, $newPosition]);
    $categoryId = (int)$pdo->lastInsertId();

    $tabsToAssign = array_values(array_unique(array_merge([$defaultTabId], $selectedTabs)));
    $insertMap    = $pdo->prepare('INSERT IGNORE INTO category_tabs (category_id, tab_id) VALUES (?, ?)');
    $insertPos    = $pdo->prepare('INSERT INTO category_tab_positions (tab_id, category_id, position) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE position = VALUES(position)');
    $maxTabPos    = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM category_tab_positions WHERE tab_id = ?');

    foreach ($tabsToAssign as $tid) {
        $insertMap->execute([$categoryId, $tid]);
        $maxTabPos->execute([$tid]);
        $insertPos->execute([$tid, $categoryId, (int)$maxTabPos->fetchColumn()]);
    }

    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid action.']);
