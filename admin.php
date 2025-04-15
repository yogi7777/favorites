<?php
require_once 'config.php';
require_once 'auth.php';
checkAuth();

if ($_SESSION['user_id'] != 1) {
    header('Location: index.php');
    exit;
}

$users = $pdo->query("SELECT * FROM users")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['add_user'])) {
            $username = $_POST['username'] ?? '';
            $password = password_hash($_POST['password'] ?? '', PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, password_hash) VALUES (?, ?)");
            $stmt->execute([$username, $password]);
            header('Location: admin.php');
            exit;
        } elseif (isset($_POST['edit_user'])) {
            $id = $_POST['id'] ?? '';
            $username = $_POST['username'] ?? '';
            $stmt = $pdo->prepare("UPDATE users SET username = ? WHERE id = ?");
            $stmt->execute([$username, $id]);
            header('Location: admin.php');
            exit;
        } elseif (isset($_POST['delete_user'])) {
            $id = $_POST['id'] ?? '';
            if ($id && $id != 1) { // Verhindere Löschen des Admins (ID 1)
                try {
                    // Schritt 1: Favicon-Dateien löschen
                    $stmt = $pdo->prepare("SELECT favicon_url FROM favorites WHERE user_id = ?");
                    $stmt->execute([$id]);
                    $favicons = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
                    foreach ($favicons as $favicon) {
                        if (!empty($favicon['favicon_url'])) {
                            $file_path = __DIR__ . '/' . $favicon['favicon_url'];
                            if (file_exists($file_path)) {
                                unlink($file_path);
                            }
                        }
                    }
        
                    // Schritt 2: Benutzer löschen (abhängige Einträge werden durch ON DELETE CASCADE entfernt)
                    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                    $stmt->execute([$id]);
        
                } catch (PDOException $e) {
                    // Optional: Fehlermeldung speichern oder anzeigen
                    $_SESSION['error'] = 'Fehler beim Löschen des Benutzers: ' . $e->getMessage();
                }
            }
            header('Location: admin.php');
            exit;
        }
    } catch (PDOException $e) {
        die("Error: " . $e->getMessage()); // Debugging: Zeigt den Fehler an
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="author" content="yogi7777">
    <title>Admin Panel</title>
    <link href="assets/src/bootstrap.min.css" rel="stylesheet">
    <link href="assets/style.css" rel="stylesheet">
</head>
<body data-bs-theme="dark">
    <div class="container-fluid p-0 m-0">
        <?php include 'navigation.php'; ?>
        <div class="row mx-auto col-md-12"><br /></div>
        <div class="row mx-auto col-md-12">
            <h2>Users</h2>
            <form method="POST" class="mb-4">
                <div class="row g-3">
                    <div class="col-md-4 col-12">
                        <input type="text" name="username" class="form-control" placeholder="Username" required>
                    </div>
                    <div class="col-md-4 col-12">
                        <input type="password" name="password" class="form-control" placeholder="Password" required>
                    </div>
                    <div class="col-md-4 col-12">
                        <button type="submit" name="add_user" class="btn btn-primary w-100">Add User</button>
                    </div>
                </div>
            </form>
            <div class="table-responsive" id="usersTable">
                <table class="table table-dark">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr class="user-row" data-username="<?php echo htmlspecialchars($user['username']); ?>">
                                <td><?php echo $user['id']; ?></td>
                                <td><?php echo htmlspecialchars($user['username']); ?></td>
                                <td>
                                    <button class="btn btn-sm btn-outline-warning edit-user" data-id="<?php echo $user['id']; ?>" data-username="<?php echo htmlspecialchars($user['username']); ?>">Edit</button>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="id" value="<?php echo $user['id']; ?>">
                                        <button type="submit" name="delete_user" class="btn btn-sm btn-outline-danger" onclick="return confirm('Are you sure? This will delete all user data (favorites and categories).');">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editUserModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="edit_username" class="form-label">Username</label>
                            <input type="text" class="form-control" id="edit_username" name="username" required>
                        </div>
                        <input type="hidden" id="edit_user_id" name="id">
                    </div>
                    <div class="modal-footer">
                        <button type="submit" name="edit_user" class="btn btn-primary">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <script src="assets/src/bootstrap.bundle.min.js"></script>
    <script src="assets/script.js"></script>
</body>
</html>