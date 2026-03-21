<?php
require_once 'config.php';
require_once 'auth.php';
require_once 'functions.php';
checkAuth();

$userId = (int)$_SESSION['user_id'];
ensureDefaultTab($pdo, $userId);

$stmt = $pdo->prepare('SELECT id, name, slug, icon, position FROM tabs WHERE user_id = ? ORDER BY position ASC, name ASC');
$stmt->execute([$userId]);
$tabs = $stmt->fetchAll(PDO::FETCH_ASSOC);

[$activeTabSlug, $activeTab] = resolveActiveTab($tabs, $_GET['tab'] ?? 'alle');
$activeTabId = $activeTab['id'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ----------------------------------------------------------------
    // ----------------------------------------------------------------
    // Create note
    // ----------------------------------------------------------------
    if (isset($_POST['add_note'])) {
        $title = trim($_POST['name'] ?? '');
        if ($title !== '') {
            $stmt = $pdo->prepare('INSERT INTO notes (user_id, title) VALUES (?, ?)');
            $stmt->execute([$userId, $title]);
            $noteId = (int)$pdo->lastInsertId();

            // Immer dem 'alle'-Tab zuordnen
                        // Always assign to 'alle' tab
            $stmt = $pdo->prepare("SELECT id FROM tabs WHERE user_id = ? AND slug = 'alle' LIMIT 1");
            $stmt->execute([$userId]);
            $defaultTabId = (int)$stmt->fetchColumn();

            $tabsToAssign = [$defaultTabId];
            foreach ($_POST['note_tabs'] ?? [] as $tid) {
                $tid = (int)$tid;
                if ($tid > 0 && $tid !== $defaultTabId) {
                    // Verify tab ownership
                                // Verify tab ownership
                    $chk = $pdo->prepare('SELECT id FROM tabs WHERE id = ? AND user_id = ?');
                    $chk->execute([$tid, $userId]);
                    if ($chk->fetchColumn()) {
                        $tabsToAssign[] = $tid;
                    }
                }
            }

            $maxPos = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM note_tabs WHERE tab_id = ?');
            $ins    = $pdo->prepare('INSERT INTO note_tabs (note_id, tab_id, position) VALUES (?, ?, ?)');
            foreach ($tabsToAssign as $tid) {
                $maxPos->execute([$tid]);
                $ins->execute([$noteId, $tid, (int)$maxPos->fetchColumn()]);
            }
        }
        header('Location: categories.php?tab=' . urlencode($activeTabSlug));
        exit;
    }

    // ----------------------------------------------------------------
    // ----------------------------------------------------------------
    // Delete note
    // ----------------------------------------------------------------
    if (isset($_POST['delete_note'])) {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('DELETE FROM notes WHERE id = ? AND user_id = ?');
            $stmt->execute([$id, $userId]);
        }
        header('Location: categories.php?tab=' . urlencode($activeTabSlug));
        exit;
    }

    if (isset($_POST['add_category'])) {
        $name = trim($_POST['name'] ?? '');

        if ($name !== '') {
            $stmt = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM categories WHERE user_id = ?');
            $stmt->execute([$userId]);
            $newPosition = (int)$stmt->fetchColumn();

            $stmt = $pdo->prepare('INSERT INTO categories (user_id, name, position) VALUES (?, ?, ?)');
            $stmt->execute([$userId, $name, $newPosition]);
            $categoryId = (int)$pdo->lastInsertId();

            $stmt = $pdo->prepare("SELECT id FROM tabs WHERE user_id = ? AND slug = 'alle' LIMIT 1");
            $stmt->execute([$userId]);
            $defaultTabId = (int)$stmt->fetchColumn();

            $insertMap = $pdo->prepare('INSERT IGNORE INTO category_tabs (category_id, tab_id) VALUES (?, ?)');
            $insertPos = $pdo->prepare('INSERT INTO category_tab_positions (tab_id, category_id, position) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE position = VALUES(position)');

            $tabsToAssign = [$defaultTabId];
            foreach ($_POST['cat_tabs'] ?? [] as $tid) {
                $tid = (int)$tid;
                if ($tid > 0 && $tid !== $defaultTabId) {
                    $chk = $pdo->prepare('SELECT id FROM tabs WHERE id = ? AND user_id = ?');
                    $chk->execute([$tid, $userId]);
                    if ($chk->fetchColumn()) {
                        $tabsToAssign[] = $tid;
                    }
                }
            }

            $maxTabPos = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM category_tab_positions WHERE tab_id = ?');
            foreach ($tabsToAssign as $tid) {
                $insertMap->execute([$categoryId, $tid]);
                $maxTabPos->execute([$tid]);
                $insertPos->execute([$tid, $categoryId, (int)$maxTabPos->fetchColumn()]);
            }
        }

        header('Location: categories.php?tab=' . urlencode($activeTabSlug));
        exit;
    }

    if (isset($_POST['edit_category'])) {
        $id   = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');

        if ($id > 0 && $name !== '') {
            $stmt = $pdo->prepare('UPDATE categories SET name = ? WHERE id = ? AND user_id = ?');
            $stmt->execute([$name, $id, $userId]);
        }

        header('Location: categories.php?tab=' . urlencode($activeTabSlug));
        exit;
    }

    if (isset($_POST['delete_category'])) {
        $id = (int)($_POST['id'] ?? 0);

        if ($id > 0) {
            $stmt = $pdo->prepare('DELETE FROM favorites WHERE category_id = ? AND user_id = ?');
            $stmt->execute([$id, $userId]);

            $stmt = $pdo->prepare('DELETE FROM categories WHERE id = ? AND user_id = ?');
            $stmt->execute([$id, $userId]);

            $stmt = $pdo->prepare('SELECT id FROM categories WHERE user_id = ? ORDER BY position ASC');
            $stmt->execute([$userId]);
            $remainingCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $updateStmt = $pdo->prepare('UPDATE categories SET position = ? WHERE id = ? AND user_id = ?');
            foreach ($remainingCategories as $index => $category) {
                $updateStmt->execute([$index, $category['id'], $userId]);
            }
        }

        header('Location: categories.php?tab=' . urlencode($activeTabSlug));
        exit;
    }

    if (isset($_POST['save_all_category_tabs'])) {
        $catIds      = $_POST['cat_ids']    ?? [];
        $allTabsPost = $_POST['tabs']       ?? [];
        $catNames    = $_POST['cat_names']  ?? [];

        $stmt = $pdo->prepare('SELECT id, slug FROM tabs WHERE user_id = ? ORDER BY position ASC');
        $stmt->execute([$userId]);
        $userTabs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $validIds     = [];
        $defaultTabId = null;
        foreach ($userTabs as $tab) {
            $validIds[] = (int)$tab['id'];
            if ($tab['slug'] === 'alle') {
                $defaultTabId = (int)$tab['id'];
            }
        }

        $deleteMapStmt    = $pdo->prepare('DELETE FROM category_tabs WHERE category_id = ? AND tab_id = ?');
        $deletePosStmt    = $pdo->prepare('DELETE FROM category_tab_positions WHERE category_id = ? AND tab_id = ?');
        $insertMapStmt    = $pdo->prepare('INSERT IGNORE INTO category_tabs (category_id, tab_id) VALUES (?, ?)');
        $selectPosStmt    = $pdo->prepare('SELECT position FROM category_tab_positions WHERE tab_id = ? AND category_id = ? LIMIT 1');
        $selectMaxPosStmt = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM category_tab_positions WHERE tab_id = ?');
        $insertPosStmt    = $pdo->prepare('INSERT INTO category_tab_positions (tab_id, category_id, position) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE position = VALUES(position)');
        $checkCatStmt     = $pdo->prepare('SELECT id FROM categories WHERE id = ? AND user_id = ? LIMIT 1');
        $getTabsStmt      = $pdo->prepare('SELECT tab_id FROM category_tabs WHERE category_id = ?');

    $updateNameStmt = $pdo->prepare('UPDATE categories SET name = ? WHERE id = ? AND user_id = ?');

        foreach ($catIds as $rawCatId) {
            $categoryId = (int)$rawCatId;

            $checkCatStmt->execute([$categoryId, $userId]);
            if (!$checkCatStmt->fetchColumn()) {
                continue;
            }

            $newName = trim($catNames[$categoryId] ?? '');
            if ($newName !== '') {
                $updateNameStmt->execute([$newName, $categoryId, $userId]);
            }

            $rawSelected = $allTabsPost[$categoryId] ?? [];
            $selected = [];
            foreach ($rawSelected as $tabId) {
                $tabId = (int)$tabId;
                if (in_array($tabId, $validIds, true)) {
                    $selected[] = $tabId;
                }
            }
            if ($defaultTabId !== null && !in_array($defaultTabId, $selected, true)) {
                $selected[] = $defaultTabId;
            }
            $selected = array_values(array_unique($selected));

            $getTabsStmt->execute([$categoryId]);
            $currentTabIds = array_map('intval', $getTabsStmt->fetchAll(PDO::FETCH_COLUMN));

            foreach ($currentTabIds as $tabId) {
                if (!in_array($tabId, $selected, true)) {
                    $deleteMapStmt->execute([$categoryId, $tabId]);
                    $deletePosStmt->execute([$categoryId, $tabId]);
                }
            }

            foreach ($selected as $tabId) {
                $insertMapStmt->execute([$categoryId, $tabId]);

                $selectPosStmt->execute([$tabId, $categoryId]);
                $position = $selectPosStmt->fetchColumn();
                if ($position === false) {
                    $selectMaxPosStmt->execute([$tabId]);
                    $position = (int)$selectMaxPosStmt->fetchColumn();
                }

                $insertPosStmt->execute([$tabId, $categoryId, (int)$position]);
            }
        }

        header('Location: categories.php?tab=' . urlencode($activeTabSlug));
        exit;
    }

    if (isset($_POST['save_all_note_tabs'])) {
        $noteIds         = $_POST['note_ids']   ?? [];
        $allNoteTabsPost = $_POST['note_tabs']  ?? [];
        $noteNames       = $_POST['note_names'] ?? [];

        $stmt = $pdo->prepare('SELECT id, slug FROM tabs WHERE user_id = ? ORDER BY position ASC');
        $stmt->execute([$userId]);
        $userTabs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $validTabIds  = [];
        $defaultTabId = null;
        foreach ($userTabs as $tab) {
            $validTabIds[] = (int)$tab['id'];
            if ($tab['slug'] === 'alle') $defaultTabId = (int)$tab['id'];
        }

        $stmtCheck   = $pdo->prepare('SELECT id FROM notes WHERE id = ? AND user_id = ? LIMIT 1');
        $stmtRename  = $pdo->prepare('UPDATE notes SET title = ? WHERE id = ? AND user_id = ?');
        $stmtGetTabs = $pdo->prepare('SELECT tab_id FROM note_tabs WHERE note_id = ?');
        $stmtDel     = $pdo->prepare('DELETE FROM note_tabs WHERE note_id = ? AND tab_id = ?');
        $stmtMaxPos  = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM note_tabs WHERE tab_id = ?');
        $stmtIns     = $pdo->prepare('INSERT IGNORE INTO note_tabs (note_id, tab_id, position) VALUES (?, ?, ?)');

        foreach ($noteIds as $rawNoteId) {
            $noteId = (int)$rawNoteId;
            $stmtCheck->execute([$noteId, $userId]);
            if (!$stmtCheck->fetchColumn()) continue;

            $name = trim($noteNames[$noteId] ?? '');
            if ($name !== '') {
                $stmtRename->execute([$name, $noteId, $userId]);
            }

            $rawSelected = $allNoteTabsPost[$noteId] ?? [];
            $selected = [];
            foreach ($rawSelected as $tabId) {
                $tabId = (int)$tabId;
                if (in_array($tabId, $validTabIds, true)) $selected[] = $tabId;
            }
            if ($defaultTabId !== null && !in_array($defaultTabId, $selected, true)) {
                $selected[] = $defaultTabId;
            }
            $selected = array_values(array_unique($selected));

            $stmtGetTabs->execute([$noteId]);
            $currentTabs = array_map('intval', $stmtGetTabs->fetchAll(PDO::FETCH_COLUMN));

            foreach ($currentTabs as $tabId) {
                if (!in_array($tabId, $selected, true)) $stmtDel->execute([$noteId, $tabId]);
            }
            foreach ($selected as $tabId) {
                if (!in_array($tabId, $currentTabs, true)) {
                    $stmtMaxPos->execute([$tabId]);
                    $stmtIns->execute([$noteId, $tabId, (int)$stmtMaxPos->fetchColumn()]);
                }
            }
        }

        header('Location: categories.php?tab=' . urlencode($activeTabSlug));
        exit;
    }
}



// Load all categories (not filtered by tab – management shows all)
$stmt = $pdo->prepare('SELECT id, name FROM categories WHERE user_id = ? ORDER BY name ASC');
$stmt->execute([$userId]);
$allCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Build tab assignment map for checkboxes
$categoryTabMap = [];
$stmt = $pdo->prepare(
    'SELECT ct.category_id, ct.tab_id
     FROM category_tabs ct
     JOIN categories c ON c.id = ct.category_id
     JOIN tabs t ON t.id = ct.tab_id
     WHERE c.user_id = ? AND t.user_id = ?'
);
$stmt->execute([$userId, $userId]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $categoryTabMap[(int)$row['category_id']][] = (int)$row['tab_id'];
}

// Load notes for management view
// Load notes for management view
$stmt = $pdo->prepare('SELECT id, title FROM notes WHERE user_id = ? ORDER BY title ASC');
$stmt->execute([$userId]);
$allNotes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$noteTabMap = [];
$stmt = $pdo->prepare(
    'SELECT nt.note_id, nt.tab_id
     FROM note_tabs nt
     JOIN notes n ON n.id = nt.note_id
     WHERE n.user_id = ?'
);
$stmt->execute([$userId]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $noteTabMap[(int)$row['note_id']][] = (int)$row['tab_id'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="author" content="yogi7777">
    <title>Favorites – Categories</title>
    <link href="assets/src/bootstrap.min.css" rel="stylesheet">
    <link href="assets/style.css" rel="stylesheet">
</head>
<body data-bs-theme="dark">
    <div class="container-fluid p-0 m-0">
        <div class="top-bar sticky-top">
            <div class="tabs-and-search">
                <ul class="nav nav-pills top-tab-list">
                    <li class="nav-item">
                        <a class="nav-link top-tab-link <?php echo $activeTabSlug === 'alle' ? 'active' : ''; ?>" href="index.php?tab=alle" title="Alle">
                            <span class="tab-icon d-sm-none">🏠</span>
                            <span class="tab-label d-none d-sm-inline">Alle</span>
                        </a>
                    </li>
                    <?php foreach ($tabs as $tab): ?>
                        <?php if ($tab['slug'] === 'alle') continue; ?>
                        <li class="nav-item">
                            <a class="nav-link top-tab-link <?php echo $activeTabSlug === $tab['slug'] ? 'active' : ''; ?>" href="index.php?tab=<?php echo urlencode($tab['slug']); ?>" title="<?php echo htmlspecialchars($tab['name']); ?>">
                                <span class="tab-icon d-sm-none"><?php echo htmlspecialchars(extractFirstEmoji($tab['name'])); ?></span>
                                <span class="tab-label d-none d-sm-inline"><?php echo htmlspecialchars($tab['name']); ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <form class="d-flex" id="searchForm">
                    <input class="form-control" type="search" id="search" placeholder="🔍 Categories" aria-label="Search">
                </form>
            </div>
        </div>

        <div class="w-100 px-3 mb-4 mt-2">
            <ul class="nav nav-tabs">
                <li class="nav-item">
                    <a class="nav-link" href="index.php?mode=edit&tab=<?php echo urlencode($activeTabSlug); ?>">Edit</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="categories.php?tab=<?php echo urlencode($activeTabSlug); ?>">Categories</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="tabs.php?tab=<?php echo urlencode($activeTabSlug); ?>">Tabs</a>
                </li>
            </ul>
        </div>

        <div class="row cat-edit px-3">
            <div class="container-fluid">
                <form method="POST" class="mb-4">
                    <div class="row g-3">
                        <div class="col-12">
                            <div class="btn-group" role="group" aria-label="Typ">
                                <input type="radio" class="btn-check" name="item_type" id="type_cat" value="category" checked>
                                <label class="btn btn-outline-secondary" for="type_cat">📁 Favorites Category</label>
                                <input type="radio" class="btn-check" name="item_type" id="type_note" value="note">
                                <label class="btn btn-outline-secondary" for="type_note">📝 Note</label>
                            </div>
                        </div>
                        <div class="col-md-8 col-12">
                                                    <input type="text" name="name" class="form-control" placeholder="Name / Title" required>
                        </div>
                        <div class="col-12" id="note-tab-section" style="display:none;">
                                                    <div class="col-12" id="cat-tab-section">
                                                        <label class="form-label mb-1 small text-muted">Assign category to tabs:</label>
                                                        <div class="d-flex gap-2 flex-wrap">
                                                            <?php foreach ($tabs as $tab): ?>
                                                                <?php if ($tab['slug'] === 'alle') continue; ?>
                                                                <label class="form-check form-check-inline">
                                                                    <input class="form-check-input" type="checkbox"
                                                                           name="cat_tabs[]" value="<?php echo (int)$tab['id']; ?>" checked>
                                                                    <span class="form-check-label"><?php echo htmlspecialchars($tab['name']); ?></span>
                                                                </label>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </div>
                                                    <div class="col-12" id="note-tab-section" style="display:none;">
                            <label class="form-label mb-1 small text-muted">Assign note to tabs:</label>
                            <div class="d-flex gap-2 flex-wrap">
                                <?php foreach ($tabs as $tab): ?>
                                    <?php if ($tab['slug'] === 'alle') continue; ?>
                                    <label class="form-check form-check-inline">
                                        <input class="form-check-input" type="checkbox"
                                               name="note_tabs[]" value="<?php echo (int)$tab['id']; ?>" checked>
                                        <span class="form-check-label"><?php echo htmlspecialchars($tab['name']); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="col-md-4 col-12">
                            <div id="btn-add-category">
                                <button type="submit" name="add_category" class="btn btn-secondary w-100">Add Category</button>
                            </div>
                            <div id="btn-add-note" style="display:none;">
                                <button type="submit" name="add_note" class="btn btn-secondary w-100">Add Note</button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <div class="container-fluid">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2 class="mb-0">Categories</h2>
                    <button type="submit" form="save-all-cats-form" name="save_all_category_tabs" class="btn btn-primary">Save</button>
                </div>
                <div class="table-responsive" id="categoriesTable">
                    <form method="POST" id="save-all-cats-form">
                    <table class="table table-dark">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Tabs</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allCategories as $category): ?>
                                <tr class="category-row" data-name="<?php echo htmlspecialchars($category['name']); ?>">
                                    <input type="hidden" name="cat_ids[]" value="<?php echo (int)$category['id']; ?>">
                                    <td><?php echo (int)$category['id']; ?></td>
                                    <td><input type="text" class="form-control form-control-sm" name="cat_names[<?php echo (int)$category['id']; ?>]" value="<?php echo htmlspecialchars($category['name']); ?>"></td>
                                    <td>
                                        <div class="d-flex gap-2 flex-wrap align-items-center">
                                            <?php foreach ($tabs as $tab): ?>
                                                <?php
                                                $assignedTabs = $categoryTabMap[(int)$category['id']] ?? [];
                                                $isChecked    = in_array((int)$tab['id'], $assignedTabs, true);
                                                $isDefault    = $tab['slug'] === 'alle';
                                                ?>
                                                <label class="form-check form-check-inline">
                                                    <input class="form-check-input" type="checkbox" name="tabs[<?php echo (int)$category['id']; ?>][]" value="<?php echo (int)$tab['id']; ?>" <?php echo $isChecked ? 'checked' : ''; ?> <?php echo $isDefault ? 'disabled' : ''; ?>>
                                                    <span class="form-check-label"><?php echo htmlspecialchars($tab['name']); ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="id" value="<?php echo (int)$category['id']; ?>">
                                            <button type="submit" name="delete_category" class="btn btn-sm btn-outline-danger" onclick="return confirm('Are you sure? This will delete all favorites in this category.');">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </form>
                </div>
            </div>

            <!-- ====================================================
                 Notes management
                 ==================================================== -->
            <div class="container-fluid notes-management-section">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2 class="mb-0">Notes</h2>
                    <button type="submit" form="save-all-notes-form" name="save_all_note_tabs" class="btn btn-primary">Save</button>
                </div>
                <div class="table-responsive">
                    <form method="POST" id="save-all-notes-form">
                    <table class="table table-dark">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Title</th>
                                <th>Tabs</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allNotes as $note): ?>
                                <tr>
                                    <input type="hidden" name="note_ids[]" value="<?php echo (int)$note['id']; ?>">
                                    <td><?php echo (int)$note['id']; ?></td>
                                    <td><input type="text" class="form-control form-control-sm" name="note_names[<?php echo (int)$note['id']; ?>]" value="<?php echo htmlspecialchars($note['title']); ?>"></td>
                                    <td>
                                        <div class="d-flex gap-2 flex-wrap align-items-center">
                                            <?php foreach ($tabs as $tab): ?>
                                                <?php
                                                $assignedNoteTabs = $noteTabMap[(int)$note['id']] ?? [];
                                                $isChecked        = in_array((int)$tab['id'], $assignedNoteTabs, true);
                                                $isDefault        = $tab['slug'] === 'alle';
                                                ?>
                                                <label class="form-check form-check-inline">
                                                    <input class="form-check-input" type="checkbox"
                                                           name="note_tabs[<?php echo (int)$note['id']; ?>][]"
                                                           value="<?php echo (int)$tab['id']; ?>"
                                                           <?php echo $isChecked ? 'checked' : ''; ?>
                                                           <?php echo $isDefault ? 'disabled' : ''; ?>>
                                                    <span class="form-check-label"><?php echo htmlspecialchars($tab['name']); ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="id" value="<?php echo (int)$note['id']; ?>">
                                            <button type="submit" name="delete_note"
                                                    class="btn btn-sm btn-outline-danger">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($allNotes)): ?>
                                <tr><td colspan="4" class="text-muted">No notes yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editCategoryModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="edit_category_name" class="form-label">Name</label>
                            <input type="text" class="form-control" id="edit_category_name" name="name" required>
                        </div>
                        <input type="hidden" id="edit_category_id" name="id">
                    </div>
                    <div class="modal-footer">
                        <button type="submit" name="edit_category" class="btn btn-primary">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php include 'navigation.php'; ?>
    <script src="assets/src/bootstrap.bundle.min.js"></script>
    <script src="assets/script.js?v1.3"></script>
    <script>
    // Type switcher: Favorites Category <-> Note
    document.querySelectorAll('input[name="item_type"]').forEach(radio => {
        radio.addEventListener('change', () => {
            const isNote = document.getElementById('type_note').checked;
            document.getElementById('cat-tab-section').style.display   = isNote ? 'none' : '';
            document.getElementById('note-tab-section').style.display  = isNote ? '' : 'none';
            document.getElementById('btn-add-category').style.display  = isNote ? 'none' : '';
            document.getElementById('btn-add-note').style.display      = isNote ? '' : 'none';
        });
    });
    </script>
</body>
</html>
