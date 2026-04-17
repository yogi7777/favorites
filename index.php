<?php
// Setup-Prüfung: Weiterleitung wenn Erst-Einrichtung noch aussteht
$setupCompleted = false;

if (file_exists(__DIR__ . '/config.php') || file_exists(__DIR__ . '/.env')) {
    try {
        // Versuche, die DB-Verbindung zu laden (über config.php oder env)
        if (file_exists(__DIR__ . '/config.php')) {
            require_once __DIR__ . '/config.php';
        } elseif (file_exists(__DIR__ . '/.env')) {
            // Hier deine loadEnv-Funktion aufrufen, falls du sie hast
        }

        if (defined('DB_HOST') && defined('DB_NAME')) {
            $pdoCheck = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER ?? getenv('DB_USER'),
                DB_PASS ?? getenv('DB_PASS'),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            $stmt = $pdoCheck->query("SELECT `value` FROM system_settings WHERE `key` = 'setup_completed' LIMIT 1");
            if ($stmt && $stmt->fetchColumn() === '1') {
                $setupCompleted = true;
            }
        }
    } catch (Exception $e) {
        // Stille Fehler – falls DB noch nicht existiert, Setup erlauben
    }
}

if (!$setupCompleted) {
    header('Location: setup.php');
    exit;
}

require_once 'config.php';
require_once 'auth.php';
require_once 'functions.php';
checkAuth();

$userId = (int)$_SESSION['user_id'];

// Redirect legacy mode=categories and mode=tabs to dedicated pages
$rawMode = $_GET['mode'] ?? 'view';
if ($rawMode === 'categories' || $rawMode === 'tabs') {
    header('Location: ' . ($rawMode === 'categories' ? 'categories' : 'tabs') . '.php?tab=' . urlencode($_GET['tab'] ?? 'alle'));
    exit;
}
$mode = in_array($rawMode, ['view', 'edit'], true) ? $rawMode : 'view';

ensureDefaultTab($pdo, $userId);

$stmt = $pdo->prepare('SELECT id, name, slug, icon, position FROM tabs WHERE user_id = ? ORDER BY position ASC, name ASC');
$stmt->execute([$userId]);
$tabs = $stmt->fetchAll(PDO::FETCH_ASSOC);

[$activeTabSlug, $activeTab] = resolveActiveTab($tabs, $_GET['tab'] ?? 'alle');
$activeTabId = $activeTab['id'] ?? null;

$stmt = $pdo->prepare('SELECT id, name FROM categories WHERE user_id = ? ORDER BY name ASC');
$stmt->execute([$userId]);
$allCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($activeTabSlug === 'alle') {
    $stmt = $pdo->prepare('SELECT id, name, position, NULL as pos_x, NULL as pos_y FROM categories WHERE user_id = ? ORDER BY position ASC, name ASC');
    $stmt->execute([$userId]);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare(
        'SELECT c.id, c.name, c.position, ctp.position AS tab_position, ctp.pos_x, ctp.pos_y
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

$categoryIds = array_column($categories, 'id');

$favoritesByCategory = [];
if (!empty($categoryIds)) {
    $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
    $params = array_merge([$userId], $categoryIds);
    $stmt = $pdo->prepare("SELECT id, category_id, title, url, favicon_url FROM favorites WHERE user_id = ? AND category_id IN ($placeholders) ORDER BY title ASC");
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fav) {
        $favoritesByCategory[(int)$fav['category_id']][] = $fav;
    }
}

// All favorites for cross-tab search
$allFavsByCategory = [];
$allCatIds = array_column($allCategories, 'id');
if (!empty($allCatIds)) {
    $placeholders = implode(',', array_fill(0, count($allCatIds), '?'));
    $params = array_merge([$userId], $allCatIds);
    $stmt = $pdo->prepare("SELECT id, category_id, title, url, favicon_url FROM favorites WHERE user_id = ? AND category_id IN ($placeholders) ORDER BY title ASC");
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fav) {
        $allFavsByCategory[(int)$fav['category_id']][] = $fav;
    }
}

$searchData = [];
foreach ($allCategories as $cat) {
    $searchData[] = [
        'id'        => (int)$cat['id'],
        'name'      => $cat['name'],
        'favorites' => array_values($allFavsByCategory[(int)$cat['id']] ?? []),
    ];
}

// All notes for cross-tab search
$searchNotes = [];
$stmt = $pdo->prepare('SELECT id, title, content FROM notes WHERE user_id = ? ORDER BY title ASC');
$stmt->execute([$userId]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $noteRow) {
    $searchNotes[] = [
        'id' => (int)$noteRow['id'],
        'title' => $noteRow['title'] ?? '',
        'content' => $noteRow['content'] ?? '',
    ];
}

// Load notes for current tab
$notes = [];
if ($activeTabSlug === 'alle') {
    // All user notes via the 'alle' tab mapping
    $alleTabRow = null;
    foreach ($tabs as $t) {
        if ($t['slug'] === 'alle') { $alleTabRow = $t; break; }
    }
    $alleTabId4Notes = $alleTabRow ? (int)$alleTabRow['id'] : null;
    if ($alleTabId4Notes) {
        $stmt = $pdo->prepare(
            'SELECT n.id, n.title, n.content, nt.position,
                    NULL AS pos_x, NULL AS pos_y, nt.width, nt.height, nt.tab_id
             FROM notes n
             JOIN note_tabs nt ON nt.note_id = n.id AND nt.tab_id = ?
             WHERE n.user_id = ?
             ORDER BY nt.position ASC, n.title ASC'
        );
        $stmt->execute([$alleTabId4Notes, $userId]);
        $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} elseif ($activeTabId) {
    $stmt = $pdo->prepare(
        'SELECT n.id, n.title, n.content, nt.position,
                nt.pos_x, nt.pos_y, nt.width, nt.height, nt.tab_id
         FROM notes n
         JOIN note_tabs nt ON nt.note_id = n.id AND nt.tab_id = ?
         WHERE n.user_id = ?
         ORDER BY nt.position ASC, n.title ASC'
    );
    $stmt->execute([$activeTabId, $userId]);
    $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta name="csrf-token" content="<?php echo htmlspecialchars(generateCsrfToken()); ?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="author" content="yogi7777">
    <title>Favorites</title>
    <link href="assets/src/bootstrap.min.css" rel="stylesheet">
    <link href="assets/style.css?v1.4" rel="stylesheet">
</head>
<body data-bs-theme="dark"<?php if ($mode === 'edit'): ?> class="edit-mode"<?php endif; ?>>
    <div class="container-fluid p-0 m-0">
        <div class="top-bar sticky-top">
            <div class="tabs-and-search">
                <ul class="nav nav-pills top-tab-list">
                    <li class="nav-item">
                        <a class="nav-link top-tab-link <?php echo $activeTabSlug === 'alle' ? 'active' : ''; ?>" href="index.php?mode=<?php echo urlencode($mode); ?>&tab=alle" title="Alle">
                            <span class="tab-icon d-sm-none">🏠</span>
                            <span class="tab-label d-none d-sm-inline">Alle</span>
                        </a>
                    </li>
                    <?php foreach ($tabs as $tab): ?>
                        <?php if ($tab['slug'] === 'alle') continue; ?>
                        <li class="nav-item">
                            <a class="nav-link top-tab-link <?php echo $activeTabSlug === $tab['slug'] ? 'active' : ''; ?>" href="index.php?mode=<?php echo urlencode($mode); ?>&tab=<?php echo urlencode($tab['slug']); ?>" title="<?php echo htmlspecialchars($tab['name']); ?>">
                                <span class="tab-icon d-sm-none"><?php echo htmlspecialchars(extractFirstEmoji($tab['name'])); ?></span>
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
                   <a href="categories.php?tab=<?php echo urlencode($activeTabSlug); ?>" class="alert-link">Click here to create a category</a>.
                </p>
            </div>
        <?php endif; ?>

        <?php if ($mode === 'edit'): ?>
            <div class="w-100 px-3 mb-4">
                <ul class="nav nav-tabs">
                    <?php if ($activeTabSlug !== 'alle'): ?>
                        <li class="nav-item">
                            <a class="nav-link active" href="index.php?mode=edit&tab=<?php echo urlencode($activeTabSlug); ?>">Edit</a>
                        </li>
                    <?php endif; ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $activeTabSlug === 'alle' ? 'active' : ''; ?>" href="categories.php?tab=<?php echo urlencode($activeTabSlug); ?>">Alle</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="tabs.php?tab=<?php echo urlencode($activeTabSlug); ?>">Tabs</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="notes_manage.php?tab=<?php echo urlencode($activeTabSlug); ?>">Notes</a>
                    </li>
                </ul>
            </div>
        <?php endif; ?>

        <div id="categories" class="<?php echo $activeTabSlug === 'alle' ? 'all-grid' : ''; ?>"
             data-tab-slug="<?php echo htmlspecialchars($activeTabSlug); ?>"
             data-tab-id="<?php echo (int)$activeTabId; ?>"
             <?php if ($mode === 'edit'): ?>data-sortable<?php endif; ?>>
            <?php foreach ($categories as $category): ?>
                <div class="category" data-category-id="<?php echo (int)$category['id']; ?>"
                     data-pos-x="<?php echo $category['pos_x'] !== null ? (int)$category['pos_x'] : ''; ?>"
                     data-pos-y="<?php echo $category['pos_y'] !== null ? (int)$category['pos_y'] : ''; ?>"
                     data-tab-id="<?php echo (int)$activeTabId; ?>"
                     <?php if ($mode === 'edit'): ?>draggable="true"<?php endif; ?>
                     ondrop="drop(event)" ondragover="allowDrop(event)" ondragleave="this.classList.remove('dragover')">
                            <div class="card category-card">
                                <div class="card-header">
                                    <h5 class="card-title"><?php echo htmlspecialchars($category['name']); ?></h5>
                                    <button class="btn cat-inline-edit-btn cat-open-all-btn" title="Open all links">⧉</button>
                                    <button class="btn cat-inline-edit-btn" title="Edit favorites">✏</button>
                                </div>
                                <div class="card-body">
                                    <?php $favoritesInCategory = $favoritesByCategory[(int)$category['id']] ?? []; ?>
                                    <?php foreach ($favoritesInCategory as $favorite): ?>
                                        <div class="favorite" data-title="<?php echo htmlspecialchars($favorite['title']); ?>" data-id="<?php echo (int)$favorite['id']; ?>">
                                            <img src="<?php echo htmlspecialchars(normalizeFaviconPath($favorite['favicon_url'])); ?>" alt="Favicon" class="favicon">
                                            <a href="<?php echo htmlspecialchars($favorite['url']); ?>" target="_blank" data-bs-toggle="tooltip" data-bs-placement="right" title="<?php echo htmlspecialchars($favorite['url']); ?>">
                                                <?php echo htmlspecialchars($favorite['title']); ?>
                                            </a>
                                            <div class="inline-fav-btns ms-auto">
                                                <button class="btn btn-sm edit-favorite" title="Edit">✏</button>
                                                <button class="btn btn-sm delete-favorite" title="Delete">✕</button>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
            <?php endforeach; ?>
            <?php foreach ($notes as $note): ?>
                <div class="note-tile"
                     data-note-id="<?php echo (int)$note['id']; ?>"
                     data-tab-id="<?php echo (int)$note['tab_id']; ?>"
                     data-pos-x="<?php echo $note['pos_x'] !== null ? (int)$note['pos_x'] : ''; ?>"
                     data-pos-y="<?php echo $note['pos_y'] !== null ? (int)$note['pos_y'] : ''; ?>"
                     data-width="<?php echo (int)($note['width'] ?? 360); ?>"
                     data-height="<?php echo (int)($note['height'] ?? 200); ?>"
                     <?php if ($mode === 'edit'): ?>draggable="true"<?php endif; ?>>
                    <div class="card note-card">
                        <div class="card-header">
                            <h5 class="note-header-title"><?php echo htmlspecialchars($note['title']); ?></h5>
                            <button class="btn note-inline-edit-btn" title="Edit note">✏</button>
                            <button class="btn note-inline-save-btn" title="Save note">✓</button>
                            <?php if ($mode === 'edit'): ?>
                                <button class="btn btn-sm btn-outline-danger delete-note" title="Delete note">✕</button>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <div class="note-view"
                                 data-raw="<?php echo htmlspecialchars($note['content'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                            ></div>
                            <textarea class="note-edit-area"
                                      placeholder="Enter note…&#10;Markdown supported: **bold**, *italic*, # Heading, - List"><?php echo htmlspecialchars($note['content'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div id="searchResults" class="d-none" style="padding: 0 15px;"></div>
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
                            <label for="url" class="form-label">URL</label>
                            <input type="url" class="form-control" id="url" name="url" required>
                        </div>
                        <div id="faviconPreview" class="mb-3 d-none">
                            <div class="d-flex align-items-center gap-2 px-3 py-2 border rounded bg-body-secondary">
                                <img id="faviconPreviewImg" src="" alt="Favicon" style="width:16px;height:16px;flex-shrink:0;">
                                <div class="overflow-hidden">
                                    <div class="text-muted" style="font-size:0.75rem;">Erkanntes Favicon:</div>
                                    <a id="faviconPreviewSource" href="#" target="_blank" class="text-truncate d-block" style="font-size:0.75rem;max-width:380px;"></a>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="favicon_url" class="form-label">Custom Favicon URL <span class="text-muted fw-normal">(optional – überschreibt die erkannte)</span></label>
                            <input type="url" class="form-control" id="favicon_url" placeholder="Leer lassen für automatisch">
                        </div>
                        <input type="hidden" id="detectedFaviconUrl" name="detected_favicon_url">
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" id="saveFavorite">Save</button>
                </div>
            </div>
        </div>
    </div>

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
                        <div id="editFaviconPreview" class="mb-3 d-none">
                            <div class="d-flex align-items-center gap-2 px-3 py-2 border rounded bg-body-secondary">
                                <img id="editFaviconPreviewImg" src="" alt="Favicon" style="width:16px;height:16px;flex-shrink:0;">
                                <div class="overflow-hidden">
                                    <div class="text-muted" style="font-size:0.75rem;">Erkanntes Favicon:</div>
                                    <a id="editFaviconPreviewSource" href="#" target="_blank" class="text-truncate d-block" style="font-size:0.75rem;max-width:380px;"></a>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="edit_favicon_url" class="form-label">Custom Favicon URL <span class="text-muted fw-normal">(optional – überschreibt die erkannte)</span></label>
                            <input type="url" class="form-control" id="edit_favicon_url" name="favicon_url" placeholder="Leer lassen für automatisch">
                        </div>
                        <input type="hidden" id="editDetectedFaviconUrl" name="detected_favicon_url">
                        <input type="hidden" id="edit_id" name="id">
                    </form>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-primary" id="updateFavorite">Update</button>
                    </div>
                </div>
            </div>
        </div>

    <?php if ($mode === 'categories'): ?>
    <!-- editCategoryModal is now in categories.php -->
    <?php endif; ?>

    <?php if ($mode === 'view'): ?>
    <!-- Quick Add: Note -->
    <div class="modal fade" id="quickNoteModal" tabindex="-1" aria-labelledby="quickNoteModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="quickNoteModalLabel">New Note</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="quickNoteError" class="alert alert-warning d-none" role="alert"></div>
                    <div class="mb-3">
                        <label for="quickNoteTitle" class="form-label">Title</label>
                        <input type="text" class="form-control" id="quickNoteTitle" placeholder="Note title" autocomplete="off">
                    </div>
                    <?php
                    $nonDefaultTabs = array_filter($tabs, fn($t) => $t['slug'] !== 'alle');
                    ?>
                    <?php if (!empty($nonDefaultTabs)): ?>
                    <div class="mb-3">
                        <label class="form-label mb-1">Assign to tabs:</label>
                        <div class="d-flex gap-2 flex-wrap">
                            <?php foreach ($nonDefaultTabs as $tab): ?>
                                <label class="form-check form-check-inline">
                                    <input class="form-check-input quick-note-tab" type="checkbox"
                                           value="<?php echo (int)$tab['id']; ?>"
                                           <?php echo ($activeTabSlug !== 'alle' && $activeTabSlug === $tab['slug']) ? 'checked' : ''; ?>>
                                    <span class="form-check-label"><?php echo htmlspecialchars($tab['name']); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-info">
                        Please <a href="tabs.php" class="alert-link">create a tab</a> first before adding notes.
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="saveQuickNote"
                        <?php echo empty($nonDefaultTabs) ? 'disabled' : ''; ?>>Save</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Add: Favorites Category -->
    <div class="modal fade" id="quickCategoryModal" tabindex="-1" aria-labelledby="quickCategoryModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="quickCategoryModalLabel">New Favorites Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="quickCategoryError" class="alert alert-warning d-none" role="alert"></div>
                    <div class="mb-3">
                        <label for="quickCategoryName" class="form-label">Category Name</label>
                        <input type="text" class="form-control" id="quickCategoryName" placeholder="Category name" autocomplete="off">
                    </div>
                    <?php if (!empty($nonDefaultTabs)): ?>
                    <div class="mb-3">
                        <label class="form-label mb-1">Assign to tabs:</label>
                        <div class="d-flex gap-2 flex-wrap">
                            <?php foreach ($nonDefaultTabs as $tab): ?>
                                <label class="form-check form-check-inline">
                                    <input class="form-check-input quick-cat-tab" type="checkbox"
                                           value="<?php echo (int)$tab['id']; ?>"
                                           <?php echo ($activeTabSlug !== 'alle' && $activeTabSlug === $tab['slug']) ? 'checked' : ''; ?>>
                                    <span class="form-check-label"><?php echo htmlspecialchars($tab['name']); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-info">
                        Please <a href="tabs.php" class="alert-link">create a tab</a> first before adding categories.
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="saveQuickCategory"
                        <?php echo empty($nonDefaultTabs) ? 'disabled' : ''; ?>>Save</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php include 'navigation.php'; ?>

    <script>
        window.favSearchData = <?= json_encode($searchData, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
        window.noteSearchData = <?= json_encode($searchNotes, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    </script>
    <script src="assets/src/bootstrap.bundle.min.js"></script>
    <script src="assets/script.js?v1.6"></script>
    <script src="assets/notes.js?v1.5"></script>
    <?php if ($mode === 'edit'): ?>
        <script src="assets/sort.js?v1.7"></script>
    <?php endif; ?>
    <?php if ($mode === 'view'): ?>
    <script>
    (function () {
        function quickSave(action, nameId, tabSelector, errorId, btnId) {
            const btn     = document.getElementById(btnId);
            const errorEl = document.getElementById(errorId);
            if (!btn) return;
            btn.addEventListener('click', function () {
                const name = document.getElementById(nameId).value.trim();
                const tabs = [...document.querySelectorAll(tabSelector + ':checked')].map(cb => cb.value);
                errorEl.classList.add('d-none');
                if (!name) {
                    errorEl.textContent = 'Please enter a name.';
                    errorEl.classList.remove('d-none');
                    return;
                }
                if (tabs.length === 0) {
                    errorEl.textContent = 'Please select at least one tab.';
                    errorEl.classList.remove('d-none');
                    return;
                }
                btn.disabled = true;
                const body = new URLSearchParams({ action, name });
                const tabKey = action === 'add_note' ? 'note_tabs[]' : 'cat_tabs[]';
                tabs.forEach(t => body.append(tabKey, t));
                fetch('quick_add.php', { method: 'POST', headers: { 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || '' }, body })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            location.reload();
                        } else {
                            errorEl.textContent = data.error ?? 'An error occurred.';
                            errorEl.classList.remove('d-none');
                            btn.disabled = false;
                        }
                    })
                    .catch(() => {
                        errorEl.textContent = 'Network error. Please try again.';
                        errorEl.classList.remove('d-none');
                        btn.disabled = false;
                    });
            });
        }

        quickSave('add_note',     'quickNoteTitle',    '.quick-note-tab', 'quickNoteError',     'saveQuickNote');
        quickSave('add_category', 'quickCategoryName', '.quick-cat-tab',  'quickCategoryError', 'saveQuickCategory');

        // Reset modal state on close
        ['quickNoteModal', 'quickCategoryModal'].forEach(function (id) {
            const el = document.getElementById(id);
            if (!el) return;
            el.addEventListener('hidden.bs.modal', function () {
                el.querySelectorAll('input[type="text"]').forEach(function (i) { i.value = ''; });
                el.querySelectorAll('.alert').forEach(function (a) { a.classList.add('d-none'); });
            });
        });
    })();
    </script>
    <?php endif; ?>
</body>
</html>
