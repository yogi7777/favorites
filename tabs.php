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
    if (isset($_POST['add_tab'])) {
        $name = trim($_POST['name'] ?? '');

        if ($name !== '') {
            $baseSlug = slugifyTabName($name);
            $slug = uniqueTabSlug($pdo, $userId, $baseSlug);

            $stmt = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM tabs WHERE user_id = ?');
            $stmt->execute([$userId]);
            $position = (int)$stmt->fetchColumn();

            $stmt = $pdo->prepare('INSERT INTO tabs (user_id, name, slug, icon, position) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$userId, $name, $slug, 'T', $position]);
        }

        header('Location: tabs.php?tab=' . urlencode($activeTabSlug));
        exit;
    }

    if (isset($_POST['save_all_tabs'])) {
        $tabIds       = $_POST['tab_ids']       ?? [];
        $tabNames     = $_POST['tab_names']     ?? [];
        $tabPositions = $_POST['tab_positions'] ?? [];

        foreach ($tabIds as $rawId) {
            $tabId    = (int)$rawId;
            $name     = trim($tabNames[$tabId]     ?? '');
            $position = (int)($tabPositions[$tabId] ?? 0);

            if ($tabId > 0 && $name !== '') {
                $stmt = $pdo->prepare('SELECT id, slug FROM tabs WHERE id = ? AND user_id = ? LIMIT 1');
                $stmt->execute([$tabId, $userId]);
                $existingTab = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($existingTab) {
                    $newSlug = $existingTab['slug'];
                    if ($existingTab['slug'] !== 'alle') {
                        $newSlug = uniqueTabSlug($pdo, $userId, slugifyTabName($name), $tabId);
                    }

                    $stmt = $pdo->prepare('UPDATE tabs SET name = ?, slug = ?, position = ? WHERE id = ? AND user_id = ?');
                    $stmt->execute([$name, $newSlug, $position, $tabId, $userId]);

                    if ($activeTabId === $tabId) {
                        $activeTabSlug = $newSlug;
                    }
                }
            }
        }

        header('Location: tabs.php?tab=' . urlencode($activeTabSlug));
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

        header('Location: tabs.php?tab=' . urlencode($activeTabSlug));
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="author" content="yogi7777">
    <title>Favorites – Tabs</title>
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
            </div>
        </div>

        <div class="w-100 px-3 mb-4 mt-2">
            <ul class="nav nav-tabs">
                <li class="nav-item">
                    <a class="nav-link" href="index.php?mode=edit&tab=<?php echo urlencode($activeTabSlug); ?>">Edit</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="categories.php?tab=<?php echo urlencode($activeTabSlug); ?>">Categories</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="notes_manage.php?tab=<?php echo urlencode($activeTabSlug); ?>">Notes</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="tabs.php?tab=<?php echo urlencode($activeTabSlug); ?>">Tabs</a>
                </li>
            </ul>
        </div>

        <div class="row cat-edit px-3">
            <div class="col-12">
                <?php foreach ($tabs as $tab): ?>
                    <?php if ($tab['slug'] !== 'alle'): ?>
                        <form method="POST" id="delete-tab-<?php echo (int)$tab['id']; ?>" style="display:none;">
                            <input type="hidden" name="id" value="<?php echo (int)$tab['id']; ?>">
                        </form>
                    <?php endif; ?>
                <?php endforeach; ?>

                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2 class="mb-0">Tabs</h2>
                    <button type="submit" form="save-all-tabs-form" name="save_all_tabs" class="btn btn-primary">Speichern</button>
                </div>

                <form method="POST" class="mb-4 row g-2">
                    <div class="col-md-8 col-12">
                        <input type="text" name="name" class="form-control" placeholder="Tab Name" required>
                    </div>
                    <div class="col-md-4 col-12">
                        <button type="submit" name="add_tab" class="btn btn-secondary w-100">Add Tab</button>
                    </div>
                </form>

                <form method="POST" id="save-all-tabs-form">
                    <div class="table-responsive">
                        <table class="table table-dark align-middle">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Slug</th>
                                    <th>Position</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tabs as $tab): ?>
                                    <tr>
                                        <input type="hidden" name="tab_ids[]" value="<?php echo (int)$tab['id']; ?>">
                                        <td><?php echo (int)$tab['id']; ?></td>
                                        <td>
                                            <input type="text" name="tab_names[<?php echo (int)$tab['id']; ?>]" class="form-control" value="<?php echo htmlspecialchars($tab['name']); ?>" required>
                                        </td>
                                        <td><?php echo htmlspecialchars($tab['slug']); ?></td>
                                        <td>
                                            <input type="number" min="0" name="tab_positions[<?php echo (int)$tab['id']; ?>]" class="form-control" value="<?php echo (int)$tab['position']; ?>" style="max-width: 110px;">
                                        </td>
                                        <td>
                                            <?php if ($tab['slug'] !== 'alle'): ?>
                                                <button type="submit" form="delete-tab-<?php echo (int)$tab['id']; ?>" name="delete_tab" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this tab and its category assignments?');">Delete</button>
                                            <?php else: ?>
                                                <span class="text-muted">System</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php include 'navigation.php'; ?>
    <script src="assets/src/bootstrap.bundle.min.js"></script>
    <script src="assets/script.js?v1.3"></script>
</body>
</html>
