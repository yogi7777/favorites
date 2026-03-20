<?php
require_once 'config.php';
require_once 'auth.php';
checkAuth();

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

$userId = (int)$_SESSION['user_id'];
$allowedModes = ['view', 'edit', 'categories', 'tabs'];
$mode = $_GET['mode'] ?? 'view';
if (!in_array($mode, $allowedModes, true)) {
    $mode = 'view';
}

ensureDefaultTab($pdo, $userId);

$stmt = $pdo->prepare('SELECT id, name, slug, icon, position FROM tabs WHERE user_id = ? ORDER BY position ASC, name ASC');
$stmt->execute([$userId]);
$tabs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$activeTabSlug = $_GET['tab'] ?? 'alle';
$activeTabSlug = trim($activeTabSlug) !== '' ? trim($activeTabSlug) : 'alle';
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

$activeTabId = $activeTab['id'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_tab'])) {
        $name = trim($_POST['name'] ?? '');
        $icon = trim($_POST['icon'] ?? 'T');
        if ($icon === '') {
            $icon = 'T';
        }

        if ($name !== '') {
            $baseSlug = slugifyTabName($name);
            $slug = uniqueTabSlug($pdo, $userId, $baseSlug);

            $stmt = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM tabs WHERE user_id = ?');
            $stmt->execute([$userId]);
            $position = (int)$stmt->fetchColumn();

            $stmt = $pdo->prepare('INSERT INTO tabs (user_id, name, slug, icon, position) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$userId, $name, $slug, $icon, $position]);
        }

        header('Location: index.php?mode=tabs&tab=' . urlencode($activeTabSlug));
        exit;
    }

    if (isset($_POST['edit_tab'])) {
        $tabId = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $icon = trim($_POST['icon'] ?? 'T');
        $position = (int)($_POST['position'] ?? 0);
        if ($icon === '') {
            $icon = 'T';
        }

        if ($tabId > 0 && $name !== '') {
            $stmt = $pdo->prepare('SELECT id, slug FROM tabs WHERE id = ? AND user_id = ? LIMIT 1');
            $stmt->execute([$tabId, $userId]);
            $existingTab = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existingTab) {
                $newSlug = $existingTab['slug'];
                if ($existingTab['slug'] !== 'alle') {
                    $newSlug = uniqueTabSlug($pdo, $userId, slugifyTabName($name), $tabId);
                }

                $stmt = $pdo->prepare('UPDATE tabs SET name = ?, icon = ?, slug = ?, position = ? WHERE id = ? AND user_id = ?');
                $stmt->execute([$name, $icon, $newSlug, $position, $tabId, $userId]);

                if ($activeTabId === $tabId) {
                    $activeTabSlug = $newSlug;
                }
            }
        }

        header('Location: index.php?mode=tabs&tab=' . urlencode($activeTabSlug));
        exit;
    }

    if (isset($_POST['delete_tab'])) {
        $tabId = (int)($_POST['id'] ?? 0);
        if ($tabId > 0) {
            $stmt = $pdo->prepare('SELECT slug FROM tabs WHERE id = ? AND user_id = ? LIMIT 1');
            $stmt->execute([$tabId, $userId]);
            $slug = $stmt->fetchColumn();

            if ($slug && $slug !== 'alle') {
                $stmt = $pdo->prepare('DELETE FROM tabs WHERE id = ? AND user_id = ?');
                $stmt->execute([$tabId, $userId]);

                if ($activeTabId === $tabId) {
                    $activeTabSlug = 'alle';
                }
            }
        }

        header('Location: index.php?mode=tabs&tab=' . urlencode($activeTabSlug));
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

            $insertMap->execute([$categoryId, $defaultTabId]);
            $insertPos->execute([$defaultTabId, $categoryId, $newPosition]);

            if ($activeTabId && $activeTabSlug !== 'alle' && $activeTabId !== $defaultTabId) {
                $stmt = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM category_tab_positions WHERE tab_id = ?');
                $stmt->execute([$activeTabId]);
                $tabPosition = (int)$stmt->fetchColumn();

                $insertMap->execute([$categoryId, $activeTabId]);
                $insertPos->execute([$activeTabId, $categoryId, $tabPosition]);
            }
        }

        header('Location: index.php?mode=categories&tab=' . urlencode($activeTabSlug));
        exit;
    }

    if (isset($_POST['edit_category'])) {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if ($id > 0 && $name !== '') {
            $stmt = $pdo->prepare('UPDATE categories SET name = ? WHERE id = ? AND user_id = ?');
            $stmt->execute([$name, $id, $userId]);
        }

        header('Location: index.php?mode=categories&tab=' . urlencode($activeTabSlug));
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

        header('Location: index.php?mode=categories&tab=' . urlencode($activeTabSlug));
        exit;
    }

    if (isset($_POST['save_category_tabs'])) {
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $tabIds = $_POST['tabs'] ?? [];
        if (!is_array($tabIds)) {
            $tabIds = [];
        }

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

        $selected = [];
        foreach ($tabIds as $tabId) {
            $tabId = (int)$tabId;
            if (in_array($tabId, $validIds, true)) {
                $selected[] = $tabId;
            }
        }

        if ($defaultTabId !== null && !in_array($defaultTabId, $selected, true)) {
            $selected[] = $defaultTabId;
        }

        $selected = array_values(array_unique($selected));

        $stmt = $pdo->prepare('SELECT id FROM categories WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$categoryId, $userId]);
        $categoryExists = (bool)$stmt->fetchColumn();

        if ($categoryExists) {
            $stmt = $pdo->prepare('SELECT tab_id FROM category_tabs WHERE category_id = ?');
            $stmt->execute([$categoryId]);
            $currentTabIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

            $deleteMapStmt = $pdo->prepare('DELETE FROM category_tabs WHERE category_id = ? AND tab_id = ?');
            $deletePosStmt = $pdo->prepare('DELETE FROM category_tab_positions WHERE category_id = ? AND tab_id = ?');
            foreach ($currentTabIds as $tabId) {
                if (!in_array($tabId, $selected, true)) {
                    $deleteMapStmt->execute([$categoryId, $tabId]);
                    $deletePosStmt->execute([$categoryId, $tabId]);
                }
            }

            $insertMapStmt = $pdo->prepare('INSERT IGNORE INTO category_tabs (category_id, tab_id) VALUES (?, ?)');
            $selectPosStmt = $pdo->prepare('SELECT position FROM category_tab_positions WHERE tab_id = ? AND category_id = ? LIMIT 1');
            $selectMaxPosStmt = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM category_tab_positions WHERE tab_id = ?');
            $insertPosStmt = $pdo->prepare('INSERT INTO category_tab_positions (tab_id, category_id, position) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE position = VALUES(position)');

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

        header('Location: index.php?mode=categories&tab=' . urlencode($activeTabSlug));
        exit;
    }
}

$stmt = $pdo->prepare('SELECT id, name FROM categories WHERE user_id = ? ORDER BY name ASC');
$stmt->execute([$userId]);
$allCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($activeTabSlug === 'alle') {
    $stmt = $pdo->prepare('SELECT id, name, position FROM categories WHERE user_id = ? ORDER BY position ASC, name ASC');
    $stmt->execute([$userId]);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare(
        'SELECT c.id, c.name, c.position, ctp.position AS tab_position
         FROM categories c
         JOIN category_tabs ct ON ct.category_id = c.id
         JOIN tabs t ON t.id = ct.tab_id AND t.user_id = c.user_id
         LEFT JOIN category_tab_positions ctp ON ctp.category_id = c.id AND ctp.tab_id = t.id
         WHERE c.user_id = ? AND t.slug = ?
         ORDER BY COALESCE(ctp.position, c.position) ASC, c.name ASC'
    );
    $stmt->execute([$userId, $activeTabSlug]);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$categoryIds = array_map(static function ($category) {
    return (int)$category['id'];
}, $categories);

$favoritesByCategory = [];
if (!empty($categoryIds)) {
    $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
    $params = array_merge([$userId], $categoryIds);
    $stmt = $pdo->prepare("SELECT id, category_id, title, url, favicon_url FROM favorites WHERE user_id = ? AND category_id IN ($placeholders) ORDER BY title ASC");
    $stmt->execute($params);
    $favorites = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($favorites as $favorite) {
        $favoritesByCategory[(int)$favorite['category_id']][] = $favorite;
    }
}

$categoryTabMap = [];
if ($mode === 'categories') {
    $stmt = $pdo->prepare(
        'SELECT ct.category_id, ct.tab_id
         FROM category_tabs ct
         JOIN categories c ON c.id = ct.category_id
         JOIN tabs t ON t.id = ct.tab_id
         WHERE c.user_id = ? AND t.user_id = ?'
    );
    $stmt->execute([$userId, $userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $categoryId = (int)$row['category_id'];
        $tabId = (int)$row['tab_id'];
        if (!isset($categoryTabMap[$categoryId])) {
            $categoryTabMap[$categoryId] = [];
        }
        $categoryTabMap[$categoryId][] = $tabId;
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="author" content="yogi7777">
    <title>Favorites</title>
    <link href="assets/src/bootstrap.min.css" rel="stylesheet">
    <link href="assets/style.css" rel="stylesheet">
</head>
<body data-bs-theme="dark">
    <div class="container-fluid p-0 m-0">
        <div class="top-bar sticky-top">
            <div class="tabs-and-search">
                <ul class="nav nav-pills top-tab-list">
                    <li class="nav-item">
                        <a class="nav-link top-tab-link <?php echo $activeTabSlug === 'alle' ? 'active' : ''; ?>" href="index.php?mode=<?php echo urlencode($mode); ?>&tab=alle" title="Alle">
                            <span class="tab-icon">🏠</span>
                            <span class="tab-label d-none d-sm-inline">Alle</span>
                        </a>
                    </li>
                    <?php foreach ($tabs as $tab): ?>
                        <?php if ($tab['slug'] === 'alle') continue; ?>
                        <li class="nav-item">
                            <a class="nav-link top-tab-link <?php echo $activeTabSlug === $tab['slug'] ? 'active' : ''; ?>" href="index.php?mode=<?php echo urlencode($mode); ?>&tab=<?php echo urlencode($tab['slug']); ?>" title="<?php echo htmlspecialchars($tab['name']); ?>">
                                <span class="tab-icon"><?php echo htmlspecialchars($tab['icon']); ?></span>
                                <span class="tab-label d-none d-sm-inline"><?php echo htmlspecialchars($tab['name']); ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <form class="d-flex" id="searchForm">
                    <input class="form-control" type="search" id="search" placeholder="🔍 Favorites" aria-label="Search">
                </form>
            </div>
        </div>

        <?php if (count($allCategories) > 0): ?>
            <div class="row mx-auto col-md-12">
                <button class="btn btn-link text-decoration-none p-0" type="button" data-bs-toggle="collapse" data-bs-target="#urlCollapse" aria-expanded="false" aria-controls="urlCollapse">
                    Add URL
                </button>
                <div class="collapse" id="urlCollapse">
                    <div class="input-group mt-2">
                        <input type="url" id="urlInput" class="form-control" placeholder="Paste or type URL here">
                        <button class="btn btn-outline-primary" id="pasteButton">Add</button>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-info mb-4 mx-3">
                <p>You need to create at least one category before adding favorites.
                   <a href="index.php?mode=categories&tab=<?php echo urlencode($activeTabSlug); ?>" class="alert-link">Click here to create a category</a>.
                </p>
            </div>
        <?php endif; ?>

        <?php if ($mode === 'edit' || $mode === 'categories' || $mode === 'tabs'): ?>
            <div class="w-100 px-3 mb-4">
                <ul class="nav nav-tabs">
                    <li class="nav-item">
                        <a class="nav-link <?php echo $mode === 'edit' ? 'active' : ''; ?>" href="index.php?mode=edit&tab=<?php echo urlencode($activeTabSlug); ?>">Edit</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $mode === 'categories' ? 'active' : ''; ?>" href="index.php?mode=categories&tab=<?php echo urlencode($activeTabSlug); ?>">Categories</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $mode === 'tabs' ? 'active' : ''; ?>" href="index.php?mode=tabs&tab=<?php echo urlencode($activeTabSlug); ?>">Tabs</a>
                    </li>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($mode === 'tabs'): ?>
            <div class="row cat-edit px-3">
                <h2>Tabs</h2>
                <div class="col-12">
                    <form method="POST" class="mb-4 row g-2">
                        <div class="col-md-6 col-12">
                            <input type="text" name="name" class="form-control" placeholder="Tab Name" required>
                        </div>
                        <div class="col-md-2 col-12">
                            <input type="text" name="icon" class="form-control" placeholder="Icon" maxlength="8">
                        </div>
                        <div class="col-md-4 col-12">
                            <button type="submit" name="add_tab" class="btn btn-primary w-100">Add Tab</button>
                        </div>
                    </form>
                </div>

                <div class="col-12">
                    <div class="table-responsive">
                        <table class="table table-dark align-middle">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Icon</th>
                                    <th>Name</th>
                                    <th>Slug</th>
                                    <th>Position</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tabs as $tab): ?>
                                    <?php $editFormId = 'edit-tab-' . (int)$tab['id']; ?>
                                    <tr>
                                        <td><?php echo (int)$tab['id']; ?></td>
                                        <td><?php echo htmlspecialchars($tab['icon']); ?></td>
                                        <td>
                                            <div class="d-flex gap-2">
                                                <input type="text" name="name" form="<?php echo $editFormId; ?>" class="form-control" value="<?php echo htmlspecialchars($tab['name']); ?>" required>
                                                <input type="text" name="icon" form="<?php echo $editFormId; ?>" class="form-control" value="<?php echo htmlspecialchars($tab['icon']); ?>" maxlength="8" style="max-width: 90px;">
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($tab['slug']); ?></td>
                                        <td>
                                            <input type="number" min="0" class="form-control" name="position" form="<?php echo $editFormId; ?>" value="<?php echo (int)$tab['position']; ?>" style="max-width: 110px;">
                                        </td>
                                        <td>
                                            <form method="POST" id="<?php echo $editFormId; ?>" style="display:inline;">
                                                <input type="hidden" name="id" value="<?php echo (int)$tab['id']; ?>">
                                                <button type="submit" name="edit_tab" class="btn btn-sm btn-outline-warning">Save</button>
                                            </form>
                                            <?php if ($tab['slug'] !== 'alle'): ?>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="id" value="<?php echo (int)$tab['id']; ?>">
                                                    <button type="submit" name="delete_tab" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this tab and its category assignments?');">Delete</button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-muted">System</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <?php $inlineStyle = $mode === 'categories' ? 'style="column-count: 1;"' : ''; ?>
            <div id="categories" <?php echo $inlineStyle; ?> <?php if ($mode === 'edit'): ?>data-sortable data-tab-slug="<?php echo htmlspecialchars($activeTabSlug); ?>"<?php endif; ?>>
                <?php if ($mode === 'view' || $mode === 'edit'): ?>
                    <?php foreach ($categories as $category): ?>
                        <div class="category" data-category-id="<?php echo (int)$category['id']; ?>" <?php if ($mode === 'edit'): ?>draggable="true"<?php endif; ?> ondrop="drop(event)" ondragover="allowDrop(event)" ondragleave="this.classList.remove('dragover')">
                            <div class="card category-card">
                                <div class="card-header">
                                    <h5 class="card-title"><?php echo htmlspecialchars($category['name']); ?></h5>
                                </div>
                                <div class="card-body">
                                    <?php $favoritesInCategory = $favoritesByCategory[(int)$category['id']] ?? []; ?>
                                    <?php foreach ($favoritesInCategory as $favorite): ?>
                                        <div class="favorite" data-title="<?php echo htmlspecialchars($favorite['title']); ?>" <?php if ($mode === 'edit'): ?>data-id="<?php echo (int)$favorite['id']; ?>"<?php endif; ?>>
                                            <img src="<?php echo htmlspecialchars($favorite['favicon_url']); ?>" alt="Favicon" class="favicon">
                                            <a href="<?php echo htmlspecialchars($favorite['url']); ?>" target="_blank" data-bs-toggle="tooltip" data-bs-placement="right" title="<?php echo htmlspecialchars($favorite['url']); ?>">
                                                <?php echo htmlspecialchars($favorite['title']); ?>
                                            </a>
                                            <?php if ($mode === 'edit'): ?>
                                                <button class="btn btn-sm btn-outline-primary edit-favorite ms-auto">Edit</button>
                                                <button class="btn btn-sm btn-outline-danger delete-favorite ms-2">Delete</button>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php elseif ($mode === 'categories'): ?>
                    <h2>Categories</h2>
                    <div class="row cat-edit px-3">
                        <div class="container-fluid">
                            <form method="POST" class="mb-4">
                                <div class="row g-3">
                                    <div class="col-md-8 col-12">
                                        <input type="text" name="name" class="form-control" placeholder="Category Name" required>
                                    </div>
                                    <div class="col-md-4 col-12">
                                        <button type="submit" name="add_category" class="btn btn-primary w-100">Add Category</button>
                                    </div>
                                </div>
                            </form>
                        </div>

                        <div class="container-fluid">
                            <div class="table-responsive" id="categoriesTable">
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
                                                <td><?php echo (int)$category['id']; ?></td>
                                                <td><?php echo htmlspecialchars($category['name']); ?></td>
                                                <td>
                                                    <form method="POST" class="d-flex gap-2 flex-wrap align-items-center">
                                                        <input type="hidden" name="category_id" value="<?php echo (int)$category['id']; ?>">
                                                        <?php foreach ($tabs as $tab): ?>
                                                            <?php
                                                            $assignedTabs = $categoryTabMap[(int)$category['id']] ?? [];
                                                            $isChecked = in_array((int)$tab['id'], $assignedTabs, true);
                                                            $isDefault = $tab['slug'] === 'alle';
                                                            ?>
                                                            <label class="form-check form-check-inline">
                                                                <input class="form-check-input" type="checkbox" name="tabs[]" value="<?php echo (int)$tab['id']; ?>" <?php echo $isChecked ? 'checked' : ''; ?> <?php echo $isDefault ? 'disabled' : ''; ?>>
                                                                <span class="form-check-label"><?php echo htmlspecialchars($tab['icon'] . ' ' . $tab['name']); ?></span>
                                                            </label>
                                                        <?php endforeach; ?>
                                                        <button type="submit" name="save_category_tabs" class="btn btn-sm btn-outline-primary">Save Tabs</button>
                                                    </form>
                                                </td>
                                                <td>
                                                    <button class="btn btn-sm btn-outline-warning edit-category" data-id="<?php echo (int)$category['id']; ?>" data-name="<?php echo htmlspecialchars($category['name']); ?>">Edit</button>
                                                    <form method="POST" style="display:inline;">
                                                        <input type="hidden" name="id" value="<?php echo (int)$category['id']; ?>">
                                                        <button type="submit" name="delete_category" class="btn btn-sm btn-outline-danger" onclick="return confirm('Are you sure? This will delete all favorites in this category.');">Delete</button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="modal fade" id="favoriteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Favorite</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="favoriteForm">
                        <div class="mb-3">
                            <label for="title" class="form-label">Title</label>
                            <input type="text" class="form-control" id="title" required>
                        </div>
                        <div class="mb-3">
                            <label for="category" class="form-label">Category</label>
                            <select class="form-select" id="category" required>
                                <option value="" selected disabled>Select</option>
                                <?php foreach ($allCategories as $category): ?>
                                    <option value="<?php echo (int)$category['id']; ?>"><?php echo htmlspecialchars($category['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="favicon_url" class="form-label">Custom Favicon URL (optional)</label>
                            <input type="url" class="form-control" id="favicon_url" placeholder="Leave blank for default">
                        </div>
                        <div class="mb-3">
                            <label for="url" class="form-label">URL</label>
                            <input type="url" class="form-control" id="url" name="url" required>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" id="saveFavorite">Save</button>
                </div>
            </div>
        </div>
    </div>

    <?php if ($mode === 'edit'): ?>
        <div class="modal fade" id="editFavoriteModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Favorite</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                    <form id="editFavoriteForm">
                        <div class="mb-3">
                            <label for="edit_title" class="form-label">Title</label>
                            <input type="text" class="form-control" id="edit_title" name="title" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit_category" class="form-label">Category</label>
                            <select class="form-select" id="edit_category" name="category" required>
                                <?php foreach ($allCategories as $category): ?>
                                    <option value="<?php echo (int)$category['id']; ?>"><?php echo htmlspecialchars($category['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="edit_url" class="form-label">URL</label>
                            <input type="url" class="form-control" id="edit_url" name="url" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit_favicon_url" class="form-label">Custom Favicon URL (optional)</label>
                            <input type="url" class="form-control" id="edit_favicon_url" name="favicon_url" placeholder="Leave blank for default">
                        </div>
                        <input type="hidden" id="edit_id" name="id">
                    </form>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-primary" id="updateFavorite">Update</button>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($mode === 'categories'): ?>
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
    <?php endif; ?>

    <?php include 'navigation.php'; ?>

    <script src="assets/src/bootstrap.bundle.min.js"></script>
    <script src="assets/script.js?v1.2"></script>
    <?php if ($mode === 'edit'): ?>
        <script src="assets/sort.js?v1.2"></script>
    <?php endif; ?>
</body>
</html>
