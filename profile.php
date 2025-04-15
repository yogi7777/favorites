<?php
require_once 'config.php';
require_once 'auth.php';
require_once 'import-export.php';
checkAuth();

$user_id = $_SESSION['user_id'];
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF-Token prüfen
    $csrf_token = $_POST['csrf_token'] ?? '';
    if ($csrf_token !== $_SESSION['csrf_token']) {
        $message = 'CSRF-Fehler.';
    } else {
        // Profil löschen
        if (isset($_POST['action']) && $_POST['action'] === 'delete_profile') {
            try {
                // Schritt 1: Favicon-Dateien löschen
                $stmt = $pdo->prepare("SELECT favicon_url FROM favorites WHERE user_id = ?");
                $stmt->execute([$user_id]);
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
                $stmt->execute([$user_id]);

                // Schritt 3: Session beenden und ausloggen
                session_destroy();
                header("Location: login.php");
                exit;

            } catch (PDOException $e) {
                $message = 'Error while deleting the profile: ' . $e->getMessage();
            }
        }
        // Gerät widerrufen
        elseif (isset($_POST['revoke_device'])) {
            $tokenId = $_POST['revoke_device'];
            revokeDevice($user_id, $tokenId);
            $message = 'Device successfully revoked.';
        }
        // Alle Geräte widerrufen
        elseif (isset($_POST['revoke_all_devices'])) {
            revokeAllDevices($user_id);
            $message = 'All Devices successfully revoked.';
        }
        // Benutzerdaten ändern
        elseif (isset($_POST['username']) || isset($_POST['new_password'])) {
            $current_password = $_POST['current_password'] ?? '';
            $new_username = $_POST['username'] ?? '';
            $new_password = $_POST['new_password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';

            // Aktuellen User abrufen
            $stmt = $pdo->prepare("SELECT username, password_hash FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!password_verify($current_password, $user['password_hash'])) {
                $message = 'Current password is incorrect.';
            } else {
                // Username ändern
                if ($new_username && $new_username !== $user['username']) {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ? AND id != ?");
                    $stmt->execute([$new_username, $user_id]);
                    if ($stmt->fetchColumn() > 0) {
                        $message = 'Username already taken.';
                    } else {
                        $stmt = $pdo->prepare("UPDATE users SET username = ? WHERE id = ?");
                        $stmt->execute([$new_username, $user_id]);
                        $_SESSION['username'] = $new_username; // Session aktualisieren
                        $message = 'Username successfully updated.';
                    }
                }

                // Passwort ändern
                if ($new_password) {
                    if ($new_password !== $confirm_password) {
                        $message = 'New passwords do not match.';
                    } else {
                        $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                        $stmt->execute([$new_password_hash, $user_id]);
                        $message = $message ? $message . ' Password successfully updated.' : 'Password successfully updated.';
                    }
                }
            }
        }
        // Daten exportieren
        elseif (isset($_POST['export_data'])) {
            $jsonData = exportFavoritesData($user_id, $pdo);
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="favorites_backup_' . date('Y-m-d') . '.json"');
            header('Content-Length: ' . strlen($jsonData));
            echo $jsonData;
            exit;
        }
        // Browser-Lesezeichen exportieren
        elseif (isset($_POST['browser_export'])) {
            $htmlData = exportBrowserBookmarks($user_id, $pdo);
            header('Content-Type: text/html');
            header('Content-Disposition: attachment; filename="favorites_browser_' . date('Y-m-d') . '.html"');
            header('Content-Length: ' . strlen($htmlData));
            echo $htmlData;
            exit;
        }
        // Daten importieren
        elseif (isset($_FILES['import_file']) && $_FILES['import_file']['error'] == 0) {
            $fileTmpPath = $_FILES['import_file']['tmp_name'];
            $fileContent = file_get_contents($fileTmpPath);
            $fileExtension = pathinfo($_FILES['import_file']['name'], PATHINFO_EXTENSION);
            if (strtolower($fileExtension) !== 'json') {
                $message = 'Nur JSON-Dateien sind erlaubt.';
            } else {
                // Favicon-Update-Option verarbeiten
                $update_favicons = isset($_POST['update_favicons']) && $_POST['update_favicons'] == '1';
                
                // Import mit Favicon-Option ausführen
                $result = importFavoritesData($user_id, $fileContent, $pdo, $update_favicons);
                $message = $result['message'];
                if (isset($result['success']) && $result['success'] && isset($result['stats'])) {
                    // Prüfen, ob die Favicons-Info bereits in der Nachricht enthalten ist
                    if (!strpos($message, "Favicons")) {
                        $message .= " (" . $result['stats']['categories'] . " Kategorien, " . $result['stats']['favorites'] . " Favoriten)";
                    }
                }
            }
        }
    }
}

// CSRF-Token generieren
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// Aktuellen Benutzernamen abrufen
$stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$current_username = $stmt->fetchColumn();

// Vertrauenswürdige Geräte abrufen
$devices = getTrustedDevices($user_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="author" content="yogi7777">
    <title>Profile</title>
    <link href="assets/src/bootstrap.min.css" rel="stylesheet">
    <link href="assets/style.css" rel="stylesheet">
</head>
<body data-bs-theme="dark">
    <div class="container-fluid p-0 m-0">
        <?php include 'navigation.php'; ?>
        <div class="row mx-auto col-md-12"><br /></div>
        <div class="row mx-auto col-md-5">
            <br /><br />
            <?php if ($message): ?>
                <div class="alert alert-info"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <!-- Profil bearbeiten -->
            <h2>Edit Profil</h2>
            <form method="POST" class="mb-4">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <div class="mb-3">
                    <label for="username" class="form-label">Username (Email)</label>
                    <input type="email" name="username" id="username" class="form-control" value="<?php echo htmlspecialchars($current_username); ?>" required>
                </div>
                <div class="mb-3">
                    <label for="current_password" class="form-label">Current password</label>
                    <input type="password" name="current_password" id="current_password" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label for="new_password" class="form-label">New password (leave blank to keep the current one)</label>
                    <input type="password" name="new_password" id="new_password" class="form-control">
                </div>
                <div class="mb-3">
                    <label for="confirm_password" class="form-label">Confirm new password</label>
                    <input type="password" name="confirm_password" id="confirm_password" class="form-control">
                </div>
                <button type="submit" class="btn btn-primary">Save changes</button>
            </form>
            <!-- Trusted devices -->
            <h2 class="mt-5">Trusted devices</h2>
            <?php if (empty($devices)): ?>
                <p>No trusted devices available.</p>
            <?php else: ?>
                <div class="col-md-12 mb-4">
                    <table class="table table-dark table-striped">
                        <thead>
                            <tr>
                                <th>Device</th>
                                <th>Registered on</th>
                                <th>Expiration date</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($devices as $device): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($device['device_name'] ?: 'Unbenanntes Gerät'); ?></td>
                                    <td><?php echo htmlspecialchars(date('d.m.Y H:i', strtotime($device['created_at']))); ?></td>
                                    <td><?php echo htmlspecialchars(date('d.m.Y H:i', strtotime($device['expires_at']))); ?></td>
                                    <td>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                            <button type="submit" name="revoke_device" value="<?php echo $device['id']; ?>" class="btn btn-danger btn-sm">Revoke</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <button type="submit" name="revoke_all_devices" class="btn btn-warning">All Deviceses revoke</button>
                    </form>
                </div>
            <?php endif; ?>

            <!-- Export Favorites -->
            <h2 class="mt-5">Favorites Import/Export</h2>
            <div class="col-md-12 mb-4">
                <div class="card">
                    <div class="card-header">
                        Export Data
                    </div>
                    <div class="card-body">
                        <p>Export your favorites and categories for a backup or for use in your browser.</p>
                        <div class="d-flex gap-2">
                            <form method="POST" class="me-2">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <button type="submit" name="export_data" class="btn btn-success">
                                    <i class="bi bi-download"></i> JSON export
                                </button>
                            </form>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <button type="submit" name="browser_export" class="btn btn-primary">
                                    <i class="bi bi-bookmark"></i> Browser-Favorites export
                                </button>
                            </form>
                        </div>
                        <div class="mt-3">
                            <small class="text-muted">
                                <i class="bi bi-info-circle"></i> JSON-Export: For backups and later import into this application.<br>
                                <i class="bi bi-info-circle"></i> HTML format for direct import into your browser (Chrome, Firefox, Edge, etc.).
                            </small>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Import Favorites -->
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        Import Data
                    </div>
                    <div class="card-body">
                        <p>Import previously exported favorites and categories.</p>
                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <div class="mb-3">
                                <label for="import_file" class="form-label">Select JSON-File</label>
                                <input type="file" name="import_file" id="import_file" class="form-control" accept=".json" required>
                            </div>
                            <div class="mb-3 form-check">
                                <input type="checkbox" class="form-check-input" id="update_favicons" name="update_favicons" value="1">
                                <label class="form-check-label" for="update_favicons">Update Favicons</label>
                                <div class="form-text text-warning">
                                    <i class="bi bi-exclamation-triangle"></i> This process may take some time for many favorites. 
                                    Please do not reload or close the page until the import is complete.
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary">
                                Import Data
                            </button>
                        </form>
                        <div class="mt-3">
                            <small class="text-muted">
                                <i class="bi bi-info-circle"></i> Only import JSON files that were created with this application.<br>
                                <i class="bi bi-info-circle"></i> The import of browser bookmarks is currently not supported.
                            </small>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row mx-auto col-md-12"><br /></div>
            <!-- Profil löschen -->
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        Delete Profil
                    </div>
                    <div class="card-body">
                        <p><strong>DANGER:</strong> Deleting your profile irrevocably removes all your data (categories, favorites and settings).</p>
                        <!-- Button, der das Modal öffnet -->
                        <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#deleteProfileModal">
                            <i class="bi bi-trash"></i> Delete Profil
                        </button>
                        <div class="mt-3">
                            <small class="text-muted">
                                <i class="bi bi-info-circle"></i> All your data, including uploaded favicons, will be removed.
                            </small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bootstrap Modal -->
            <div class="modal fade" id="deleteProfileModal" tabindex="-1" aria-labelledby="deleteProfileModalLabel" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="deleteProfileModalLabel">Confirm profile delete</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <p>Are you sure you want to delete your profile? <strong>This action cannot be undone</strong>.</p>
                        </div>
                        <div class="modal-footer">
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="action" value="delete_profile">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-danger">Delete Profil</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="assets/src/bootstrap.bundle.min.js"></script>
</body>
</html>