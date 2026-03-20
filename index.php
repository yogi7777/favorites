<?php
require_once 'config.php';
require_once 'auth.php';
checkAuth();

$user_id = $_SESSION['user_id'];
$mode = $_GET['mode'] ?? 'view';

// Load categories sorted by position
$stmt = $pdo->prepare("SELECT * FROM categories WHERE user_id = ? ORDER BY position ASC, name ASC");
$stmt->execute([$user_id]);
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Load favorites with all their category IDs via junction table
$stmt = $pdo->prepare("
    SELECT f.id, f.title, f.url, f.favicon_url, f.created_at,
           GROUP_CONCAT(fc.category_id ORDER BY fc.category_id SEPARATOR ',') AS category_ids
    FROM favorites f
    LEFT JOIN favorite_categories fc ON f.id = fc.favorite_id
    WHERE f.user_id = ?
    GROUP BY f.id
    ORDER BY f.title ASC
");
$stmt->execute([$user_id]);
$all_favorites = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Build per-category lookups
$favorites_by_category = [];
foreach ($all_favorites as &$fav) {
    $fav['cat_ids_array'] = $fav['category_ids']
        ? array_map('intval', explode(',', $fav['category_ids']))
        : [];
    foreach ($fav['cat_ids_array'] as $cid) {
        $favorites_by_category[$cid][] = $fav;
    }
}
unset($fav);

// Handle categories-mode POST actions
if ($mode === 'categories') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['add_category'])) {
            $name = trim($_POST['name'] ?? '');
            if ($name !== '') {
                $stmt = $pdo->prepare("SELECT COALESCE(MAX(position), -1) FROM categories WHERE user_id = ?");
                $stmt->execute([$user_id]);
                $max_position = (int)$stmt->fetchColumn();
                $stmt = $pdo->prepare("INSERT INTO categories (user_id, name, position) VALUES (?, ?, ?)");
                $stmt->execute([$user_id, $name, $max_position + 1]);
            }
            header('Location: index.php?mode=categories');
            exit;
        } elseif (isset($_POST['edit_category'])) {
            $id   = (int)($_POST['id']   ?? 0);
            $name = trim($_POST['name'] ?? '');
            if ($id && $name !== '') {
                $stmt = $pdo->prepare("UPDATE categories SET name = ? WHERE id = ? AND user_id = ?");
                $stmt->execute([$name, $id, $user_id]);
            }
            header('Location: index.php?mode=categories');
            exit;
        } elseif (isset($_POST['delete_category'])) {
            $id = (int)($_POST['id'] ?? 0);
            if ($id) {
                // Deleting the category cascades in favorite_categories automatically.
                // Favorites themselves are NOT deleted – only their assignment to this category.
                $stmt = $pdo->prepare("DELETE FROM categories WHERE id = ? AND user_id = ?");
                $stmt->execute([$id, $user_id]);
                // Renormalize positions
                $stmt = $pdo->prepare("SELECT id FROM categories WHERE user_id = ? ORDER BY position ASC");
                $stmt->execute([$user_id]);
                $remaining = $stmt->fetchAll(PDO::FETCH_COLUMN);
                $upd = $pdo->prepare("UPDATE categories SET position = ? WHERE id = ? AND user_id = ?");
                foreach ($remaining as $index => $catId) {
                    $upd->execute([$index, $catId, $user_id]);
                }
            }
            header('Location: index.php?mode=categories');
            exit;
        }
    }
    // Reload after possible POST
    $stmt = $pdo->prepare("SELECT * FROM categories WHERE user_id = ? ORDER BY position ASC, name ASC");
    $stmt->execute([$user_id]);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
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
        <?php include 'navigation.php'; ?>

        <?php if (count($categories) > 0): ?>
            <div class="row mx-auto col-md-12">
                <button class="btn btn-link text-decoration-none p-0" type="button"
                        data-bs-toggle="collapse" data-bs-target="#urlCollapse"
                        aria-expanded="false" aria-controls="urlCollapse">
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
                   <a href="index.php?mode=categories" class="alert-link">Click here to create a category</a>.
                </p>
            </div>
        <?php endif; ?>

        <?php if ($mode === 'edit' || $mode === 'categories'): ?>
            <div class="w-100 px-3 mb-0">
                <ul class="nav nav-tabs sub-mode-tabs">
                    <li class="nav-item">
                        <a class="nav-link <?php echo $mode === 'edit' ? 'active' : ''; ?>" href="?mode=edit">Edit</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $mode === 'categories' ? 'active' : ''; ?>" href="?mode=categories">Categories</a>
                    </li>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($mode !== 'categories'): ?>
        <!-- ===== Category Tabs Navigation ===== -->
        <div class="px-3 pt-2 pb-0">
            <ul class="nav nav-tabs category-tabs" id="categoryTabs" role="tablist"
                <?php if ($mode === 'edit'): ?>data-tab-sortable<?php endif; ?>>
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="tab-all-btn"
                            data-bs-toggle="tab" data-bs-target="#tab-all"
                            type="button" role="tab" draggable="false">Alle</button>
                </li>
                <?php foreach ($categories as $cat): ?>
                <li class="nav-item tab-sortable-item" role="presentation"
                    data-category-id="<?php echo $cat['id']; ?>"
                    <?php if ($mode === 'edit'): ?>draggable="true"<?php endif; ?>>
                    <button class="nav-link" id="tab-<?php echo $cat['id']; ?>-btn"
                            data-bs-toggle="tab" data-bs-target="#tab-<?php echo $cat['id']; ?>"
                            type="button" role="tab" draggable="false">
                        <?php echo htmlspecialchars($cat['name']); ?>
                    </button>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <!-- ===== Tab Content ===== -->
        <div class="tab-content" id="categoryTabContent">

            <!-- ALL tab: masonry layout with all categories -->
            <div class="tab-pane fade show active" id="tab-all" role="tabpanel">
                <div id="categories">
                    <?php foreach ($categories as $cat): ?>
                        <div class="category" data-category-id="<?php echo $cat['id']; ?>"
                             ondrop="drop(event)" ondragover="allowDrop(event)"
                             ondragleave="this.classList.remove('dragover')">
                            <div class="card category-card">
                                <div class="card-header">
                                    <h5 class="card-title"><?php echo htmlspecialchars($cat['name']); ?></h5>
                                </div>
                                <div class="card-body">
                                    <?php foreach ($favorites_by_category[$cat['id']] ?? [] as $fav): ?>
                                        <div class="favorite"
                                             data-id="<?php echo $fav['id']; ?>"
                                             data-title="<?php echo htmlspecialchars($fav['title']); ?>"
                                             data-category-ids="<?php echo htmlspecialchars($fav['category_ids'] ?? ''); ?>">
                                            <img src="<?php echo htmlspecialchars($fav['favicon_url'] ?? ''); ?>" alt="Favicon" class="favicon">
                                            <a href="<?php echo htmlspecialchars($fav['url']); ?>"
                                               target="_blank"
                                               data-bs-toggle="tooltip" data-bs-placement="right"
                                               title="<?php echo htmlspecialchars($fav['url']); ?>">
                                                <?php echo htmlspecialchars($fav['title']); ?>
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
                </div>
            </div>

            <!-- Individual category tabs -->
            <?php foreach ($categories as $cat): ?>
            <div class="tab-pane fade" id="tab-<?php echo $cat['id']; ?>" role="tabpanel" data-category-id="<?php echo $cat['id']; ?>">
                <div class="single-category-panel px-3 pt-3"
                     data-category-id="<?php echo $cat['id']; ?>"
                     ondrop="drop(event)" ondragover="allowDrop(event)"
                     ondragleave="this.classList.remove('dragover')">
                    <?php foreach ($favorites_by_category[$cat['id']] ?? [] as $fav): ?>
                        <div class="favorite"
                             data-id="<?php echo $fav['id']; ?>"
                             data-title="<?php echo htmlspecialchars($fav['title']); ?>"
                             data-category-ids="<?php echo htmlspecialchars($fav['category_ids'] ?? ''); ?>">
                            <img src="<?php echo htmlspecialchars($fav['favicon_url'] ?? ''); ?>" alt="Favicon" class="favicon">
                            <a href="<?php echo htmlspecialchars($fav['url']); ?>"
                               target="_blank"
                               data-bs-toggle="tooltip" data-bs-placement="right"
                               title="<?php echo htmlspecialchars($fav['url']); ?>">
                                <?php echo htmlspecialchars($fav['title']); ?>
                            </a>
                            <?php if ($mode === 'edit'): ?>
                            <button class="btn btn-sm btn-outline-primary edit-favorite ms-auto">Edit</button>
                            <button class="btn btn-sm btn-outline-danger delete-favorite ms-2">Delete</button>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>

        </div><!-- /tab-content -->

        <?php else: // categories management mode ?>
        <!-- ===== Categories Mode ===== -->
        <div id="categories" style="column-count: 1;">
            <h2>Categories</h2>
            <div class="row cat-edit">
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
                <div class="w-100"></div>
                <div class="container-fluid">
                    <div class="table-responsive" id="categoriesTable">
                        <table class="table table-dark">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($categories as $cat): ?>
                                    <tr class="category-row" data-name="<?php echo htmlspecialchars($cat['name']); ?>">
                                        <td><?php echo $cat['id']; ?></td>
                                        <td><?php echo htmlspecialchars($cat['name']); ?></td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-warning edit-category"
                                                    data-id="<?php echo $cat['id']; ?>"
                                                    data-name="<?php echo htmlspecialchars($cat['name']); ?>">Edit</button>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="id" value="<?php echo $cat['id']; ?>">
                                                <button type="submit" name="delete_category"
                                                        class="btn btn-sm btn-outline-danger"
                                                        onclick="return confirm('Delete this category? Favorites will NOT be deleted — only their assignment to this category is removed.');">
                                                    Delete
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /container-fluid -->

    <!-- ===== Add Favorite Modal ===== -->
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
                            <label class="form-label">Categories</label>
                            <div id="add-category-checkboxes" class="category-checkbox-list border rounded p-2">
                                <?php foreach ($categories as $cat): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox"
                                               value="<?php echo $cat['id']; ?>"
                                               id="add_cat_<?php echo $cat['id']; ?>">
                                        <label class="form-check-label" for="add_cat_<?php echo $cat['id']; ?>">
                                            <?php echo htmlspecialchars($cat['name']); ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
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
    <!-- ===== Edit Favorite Modal ===== -->
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
                            <label class="form-label">Categories</label>
                            <div id="edit-category-checkboxes" class="category-checkbox-list border rounded p-2">
                                <?php foreach ($categories as $cat): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox"
                                               value="<?php echo $cat['id']; ?>"
                                               id="edit_cat_<?php echo $cat['id']; ?>">
                                        <label class="form-check-label" for="edit_cat_<?php echo $cat['id']; ?>">
                                            <?php echo htmlspecialchars($cat['name']); ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
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
    <!-- ===== Edit Category Modal ===== -->
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

    <script src="assets/src/bootstrap.bundle.min.js"></script>
    <script src="assets/script.js?v2.0"></script>
    <?php if ($mode === 'edit'): ?>
        <script src="assets/sort.js?v2.0"></script>
    <?php endif; ?>
</body>
</html>
