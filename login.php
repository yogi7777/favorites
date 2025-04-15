<?php
require_once 'config.php';
require_once 'auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $csrf_token = $_POST['csrf_token'] ?? '';
    $remember = isset($_POST['remember']);
    $device_name = $_POST['device_name'] ?? ''; // Gerätename aus Formular

    if ($csrf_token === $_SESSION['csrf_token'] && login($username, $password, $remember, $device_name)) {
        header('Location: index.php');
        exit;
    } else {
        $error = "Ungültige Anmeldedaten oder CSRF-Fehler.";
    }
}

$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="author" content="yogi7777">
    <title>Login</title>
    <link href="assets/src/bootstrap.min.css" rel="stylesheet">
    <link href="assets/style.css" rel="stylesheet">
</head>
<body data-bs-theme="dark">
    <div class="container mt-5">
        <h1>Login</h1>
        <?php if (isset($error)) echo "<p class='text-danger'>$error</p>"; ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <div class="mb-3">
                <label for="username" class="form-label">Username</label>
                <input type="text" class="form-control" id="username" name="username" required>
            </div>
            <div class="mb-3">
                <label for="password" class="form-label">Password</label>
                <input type="password" class="form-control" id="password" name="password" required>
            </div>
            <div class="mb-3 form-check">
                <input type="checkbox" class="form-check-input" id="remember" name="remember">
                <label class="form-check-label" for="remember">Angemeldet bleiben</label>
            </div>
            <div class="mb-3" id="device_name_group" style="display: none;">
                <label for="device_name" class="form-label">Gerätename (optional)</label>
                <input type="text" class="form-control" id="device_name" name="device_name" placeholder="z. B. Mein Laptop">
            </div>
            <button type="submit" class="btn btn-primary">Login</button>
        </form>

        <footer>
            © 2025 by <a href="https://github.com/yogi7777" target="_blank">yogi7777</a> <a href="https://itcrm.ch" target="_blank">itcrm.ch</a>. Alle Rechte vorbehalten.
        </footer>
    </div>
    <script src="assets/src/bootstrap.bundle.min.js"></script>
    <script>
        // Gerätename-Feld ein-/ausblenden basierend auf Checkbox
        document.getElementById('remember').addEventListener('change', function() {
            document.getElementById('device_name_group').style.display = this.checked ? 'block' : 'none';
        });
    </script>
</body>
</html>