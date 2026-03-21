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

            $insertMap = $pdo->prepare('INSERT IGNORE INTO category_tabs (category_id, tab_id) VALUES (?, ?)');
            $insertPos = $pdo->prepare('INSERT INTO category_tab_positions (tab_id, category_id, position) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE position = VALUES(position)');
            $maxTabPos = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM category_tab_positions WHERE tab_id = ?');

            foreach (array_values(array_unique($tabsToAssign)) as $tid) {
                $insertMap->execute([$categoryId, $tid]);
                $maxTabPos->execute([$tid]);
                $insertPos->execute([$tid, $categoryId, (int)$maxTabPos->fetchColumn()]);
            }
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
                $updateStmt->execute([$index, (int)$category['id'], $userId]);
            }
        }

        header('Location: categories.php?tab=' . urlencode($activeTabSlug));
        exit;
    }

    if (isset($_POST['save_all_category_tabs'])) {
        $catIds = $_POST['cat_ids'] ?? [];
        $allTabsPost = $_POST['tabs'] ?? [];
        $catNames = $_POST['cat_names'] ?? [];

        $stmt = $pdo->prepare('SELECT id, slug FROM tabs WHERE user_id = ? ORDER BY position ASC');
        $stmt->execute([$userId]);
        $userTabs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $validIds = [];
        $defaultTabId = null;
        foreach ($userTabs as $tab) {
            $validIds[] = (int)$tab['id'];
            if ($tab['slug'] === 'alle') {
                $defaultTabId = (int)$tab['id'];
            }
        }

        $deleteMapStmt = $pdo->prepare('DELETE FROM category_tabs WHERE category_id = ? AND tab_id = ?');
        $deletePosStmt = $pdo->prepare('DELETE FROM category_tab_positions WHERE category_id = ? AND tab_id = ?');
        $insertMapStmt = $pdo->prepare('INSERT IGNORE INTO category_tabs (category_id, tab_id) VALUES (?, ?)');
        $selectPosStmt = $pdo->prepare('SELECT position FROM category_tab_positions WHERE tab_id = ? AND category_id = ? LIMIT 1');
        $selectMaxPosStmt = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM category_tab_positions WHERE tab_id = ?');
        $insertPosStmt = $pdo->prepare('INSERT INTO category_tab_positions (tab_id, category_id, position) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE position = VALUES(position)');
        $checkCatStmt = $pdo->prepare('SELECT id FROM categories WHERE id = ? AND user_id = ? LIMIT 1');
        $getTabsStmt = $pdo->prepare('SELECT tab_id FROM category_tabs WHERE category_id = ?');
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
}

$stmt = $pdo->prepare('SELECT id, name FROM categories WHERE user_id = ? ORDER BY name ASC');
$stmt->execute([$userId]);
$allCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="author" content="yogi7777">
    <title>Favorites - Categories</title>
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
                    <a class="nav-link" href="notes_manage.php?tab=<?php echo urlencode($activeTabSlug); ?>">Notes</a>
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
                        <div class="col-md-8 col-12">
                            <input type="text" name="name" class="form-control" placeholder="Category name" required>
                        </div>
                        <div class="col-12" id="cat-tab-section">
                            <label class="form-label mb-1 small text-muted">Assign category to tabs:</label>
                            <div class="d-flex gap-2 flex-wrap">
                                <?php foreach ($tabs as $tab): ?>
                                    <?php if ($tab['slug'] === 'alle') continue; ?>
                                    <label class="form-check form-check-inline">
                                        <input class="form-check-input" type="checkbox" name="cat_tabs[]" value="<?php echo (int)$tab['id']; ?>" checked>
                                        <span class="form-check-label"><?php echo htmlspecialchars($tab['name']); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="col-md-4 col-12">
                            <button type="submit" name="add_category" class="btn btn-secondary w-100">Add Category</button>
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
                                                $isChecked = in_array((int)$tab['id'], $assignedTabs, true);
                                                $isDefault = $tab['slug'] === 'alle';
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
        </div>
    </div>

    <?php include 'navigation.php'; ?>
    <script src="assets/src/bootstrap.bundle.min.js"></script>
    <script src="assets/script.js?v1.3"></script>
</body>
</html>
