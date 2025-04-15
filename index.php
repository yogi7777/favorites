<?php
require_once 'config.php';
require_once 'auth.php';
checkAuth();

$user_id = $_SESSION['user_id'];
$mode = $_GET['mode'] ?? 'view';

$stmt = $pdo->prepare("SELECT * FROM categories WHERE user_id = ? ORDER BY position ASC, name ASC");
$stmt->execute([$user_id]);
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT f.*, c.name AS category_name FROM favorites f JOIN categories c ON f.category_id = c.id WHERE f.user_id = ? ORDER BY f.title ASC");
$stmt->execute([$user_id]);
$favorites = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($mode === 'categories') {
    $categories = $pdo->query("SELECT * FROM categories WHERE user_id = {$_SESSION['user_id']}")->fetchAll(PDO::FETCH_ASSOC);
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['add_category'])) {
            $name = $_POST['name'] ?? '';
            $stmt = $pdo->prepare("SELECT MAX(position) FROM categories WHERE user_id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $max_position = $stmt->fetchColumn() ?? -1;
            $new_position = $max_position + 1;

            $stmt = $pdo->prepare("INSERT INTO categories (user_id, name, position) VALUES (?, ?, ?)");
            $stmt->execute([$_SESSION['user_id'], $name, $new_position]);
            header('Location: index.php?mode=categories');
            exit;
        } elseif (isset($_POST['edit_category'])) {
            $id = $_POST['id'] ?? '';
            $name = $_POST['name'] ?? '';
            $stmt = $pdo->prepare("UPDATE categories SET name = ? WHERE id = ? AND user_id = ?");
            $stmt->execute([$name, $id, $_SESSION['user_id']]);
            header('Location: index.php?mode=categories');
            exit;
        } elseif (isset($_POST['delete_category'])) {
            $id = $_POST['id'] ?? '';
            $stmt = $pdo->prepare("DELETE FROM favorites WHERE category_id = ? AND user_id = ?");
            $stmt->execute([$id, $_SESSION['user_id']]);
            $stmt = $pdo->prepare("DELETE FROM categories WHERE id = ? AND user_id = ?");
            $stmt->execute([$id, $_SESSION['user_id']]);

            $stmt = $pdo->prepare("SELECT id FROM categories WHERE user_id = ? ORDER BY position ASC");
            $stmt->execute([$_SESSION['user_id']]);
            $remaining_categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $update_stmt = $pdo->prepare("UPDATE categories SET position = ? WHERE id = ? AND user_id = ?");
            foreach ($remaining_categories as $index => $cat) {
                $update_stmt->execute([$index, $cat['id'], $_SESSION['user_id']]);
            }

            header('Location: index.php?mode=categories');
            exit;
        }
    }
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
                <!-- Button/Link zum Ausklappen -->
                <button class="btn btn-link text-decoration-none p-0" type="button" data-bs-toggle="collapse" data-bs-target="#urlCollapse" aria-expanded="false" aria-controls="urlCollapse">
                    Add URL
                </button>
                
                <!-- Collapse-Container -->
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
            <div class="w-100 px-3 mb-4">
                <ul class="nav nav-tabs">
                    <li class="nav-item">
                        <a class="nav-link <?php echo $mode === 'edit' ? 'active' : ''; ?>" href="?mode=edit">Edit</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $mode === 'categories' ? 'active' : ''; ?>" href="?mode=categories">Categories</a>
                    </li>
                </ul>
            </div>
        <?php endif; ?>
        <!-- Hier ändern wir die Struktur zu einem Flexbox-Container -->
        <?php
        $inline_style = '';
        if (isset($mode) && $mode === 'categories') {
            $inline_style = 'style="column-count: 1;"';
        }
        ?>
        <div id="categories" <?php echo $inline_style; ?> <?php if ($mode === 'edit'): ?>data-sortable<?php endif; ?>>
            <?php switch ($mode): case 'view': ?>
                <?php foreach ($categories as $cat): ?>
                            <div class="category" data-category-id="<?php echo $cat['id']; ?>" ondrop="drop(event)" ondragover="allowDrop(event)" ondragleave="this.classList.remove('dragover')">
                                <div class="card category-card">
                                    <div class="card-header">
                                        <h5 class="card-title"><?php echo htmlspecialchars($cat['name']); ?></h5>
                                    </div>
                                    <div class="card-body">
                                        <?php foreach ($favorites as $fav): if ($fav['category_id'] == $cat['id']): ?>
                                            <div class="favorite" data-title="<?php echo htmlspecialchars($fav['title']); ?>">
                                                <img src="<?php echo htmlspecialchars($fav['favicon_url']); ?>" alt="Favicon" class="favicon">
                                                <a href="<?php echo $fav['url']; ?>" 
                                                    target="_blank" 
                                                    data-bs-toggle="tooltip" 
                                                    data-bs-placement="right" 
                                                    title="<?php echo htmlspecialchars($fav['url']); ?>">
                                                    <?php echo htmlspecialchars($fav['title']); ?>
                                                </a>
                                            </div>
                                        <?php endif; endforeach; ?>
                                    </div>
                                </div>
                            </div>
                <?php endforeach; ?>
            <?php break; case 'edit': ?>
                <?php foreach ($categories as $cat): ?>
                    <div class="category" data-category-id="<?php echo $cat['id']; ?>" draggable="true" ondrop="drop(event)" ondragover="allowDrop(event)" ondragleave="this.classList.remove('dragover')">
                        <div class="card category-card">
                            <div class="card-header">
                                <h5 class="card-title"><?php echo htmlspecialchars($cat['name']); ?></h5>
                            </div>
                            <div class="card-body">
                                <?php foreach ($favorites as $fav): if ($fav['category_id'] == $cat['id']): ?>
                                    <div class="favorite" data-title="<?php echo htmlspecialchars($fav['title']); ?>" data-id="<?php echo $fav['id']; ?>">
                                        <img src="<?php echo htmlspecialchars($fav['favicon_url']); ?>" alt="Favicon" class="favicon">
                                        <a href="<?php echo $fav['url']; ?>" 
                                            target="_blank" 
                                            data-bs-toggle="tooltip" 
                                            data-bs-placement="right" 
                                            title="<?php echo htmlspecialchars($fav['url']); ?>">
                                            <?php echo htmlspecialchars($fav['title']); ?>
                                        </a>
                                        <button class="btn btn-sm btn-outline-primary edit-favorite ms-auto">Edit</button>
                                        <button class="btn btn-sm btn-outline-danger delete-favorite ms-2">Delete</button>
                                    </div>
                                <?php endif; endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php break; case 'categories': ?>
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
                                                <button class="btn btn-sm btn-outline-warning edit-category" data-id="<?php echo $cat['id']; ?>" data-name="<?php echo htmlspecialchars($cat['name']); ?>">Edit</button>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="id" value="<?php echo $cat['id']; ?>">
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
            <?php endswitch; ?>
        </div>
    </div>

    <!-- Modals bleiben unverändert -->
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
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="favicon_url" class="form-label">Custom Favicon URL (optional)</label>
                            <input type="url" class="form-control" id="favicon_url" placeholder="Leave blank for default">
                        </div>
                        <input type="hidden" id="url">
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
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
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

    <script src="assets/src/bootstrap.bundle.min.js"></script>
    <script src="assets/script.js?v1.1"></script>
    <?php if ($mode === 'edit'): ?>
        <script src="assets/sort.js?v1.1"></script>
    <?php endif; ?>
</body>
</html>