<?php
/**
 * setup.php – Installations-Assistent
 *
 * Führt beim ersten Aufruf durch die Einrichtung:
 *   1. Datenbankzugangsdaten eingeben → config.php wird erstellt
 *   2. Admin-Account erstellen
 *   3. Datenbank + Tabellen anlegen (DB_Script.sql)
 *
 * Nach erfolgreichem Setup wird .setup_complete erstellt,
 * das erneute Aufrufen des Setups wird dann verhindert.
 */

// Bereits eingerichtet → direkt zur Login-Seite
if (file_exists(__DIR__ . '/.setup_complete')) {
    header('Location: login.php');
    exit;
}

$errors  = [];
$info    = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── Eingaben einlesen & bereinigen ────────────────────────────────────────
    $db_host     = trim($_POST['db_host']     ?? '');
    $db_user     = trim($_POST['db_user']     ?? '');
    $db_pass     =      $_POST['db_pass']     ?? '';
    $db_name     = trim($_POST['db_name']     ?? '');
    $admin_user  = trim($_POST['admin_user']  ?? '');
    $admin_pass  =      $_POST['admin_pass']  ?? '';
    $admin_pass2 =      $_POST['admin_pass2'] ?? '';

    // ── Validierung ───────────────────────────────────────────────────────────
    if ($db_host    === '') $errors[] = 'Datenbank-Hostname ist erforderlich.';
    if ($db_user    === '') $errors[] = 'Datenbankbenutzer ist erforderlich.';
    if ($db_name    === '') $errors[] = 'Datenbankname ist erforderlich.';
    if ($admin_user === '') $errors[] = 'Admin-Benutzername ist erforderlich.';
    if (strlen($admin_pass) < 8) $errors[] = 'Admin-Passwort muss mindestens 8 Zeichen haben.';
    if ($admin_pass !== $admin_pass2) $errors[] = 'Passwörter stimmen nicht überein.';

    // Nur alphanumerisch + Unterstrich für DB-Name (SQL-Injection-Schutz)
    if ($db_name !== '' && !preg_match('/^\w+$/', $db_name)) {
        $errors[] = 'Datenbankname darf nur Buchstaben, Zahlen und _ enthalten.';
    }

    // ── Schritt 1: MySQL-Verbindung ohne Datenbank testen ────────────────────
    if (empty($errors)) {
        try {
            $pdoInit = new PDO(
                "mysql:host={$db_host};charset=utf8mb4",
                $db_user,
                $db_pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
            );
            $info[] = '✓ MySQL-Verbindung erfolgreich.';
        } catch (PDOException $e) {
            $errors[] = 'MySQL-Verbindung fehlgeschlagen: ' . $e->getMessage();
        }
    }

    // ── Schritt 2: Datenbank anlegen ─────────────────────────────────────────
    if (empty($errors)) {
        try {
            // Backtick-quoted db_name ist durch Regex oben bereits sicher
            $pdoInit->exec("CREATE DATABASE IF NOT EXISTS `{$db_name}`
                CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $info[] = "✓ Datenbank '{$db_name}' bereit.";
        } catch (PDOException $e) {
            // Fallback: Bei eingeschraenkten Rechten bestehende DB verwenden
            try {
                $pdoCheck = new PDO(
                    "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4",
                    $db_user,
                    $db_pass,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
                );
                $info[] = "✓ Datenbank '{$db_name}' bereits vorhanden (Create uebersprungen).";
            } catch (PDOException $inner) {
                $errors[] = 'Datenbank anlegen fehlgeschlagen: ' . $e->getMessage();
            }
        }
    }

    // ── Schritt 3: Tabellen anlegen (DB_Script.sql) ──────────────────────────
    if (empty($errors)) {
        try {
            $pdoDb = new PDO(
                "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4",
                $db_user,
                $db_pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            $sqlFile = __DIR__ . '/DB_Script.sql';
            if (!is_readable($sqlFile)) {
                throw new RuntimeException('DB_Script.sql nicht gefunden oder nicht lesbar.');
            }

            $sql = file_get_contents($sqlFile);

            // Statements aufteilen; CREATE DATABASE und USE überspringen
            // (Datenbank wurde bereits oben angelegt)
            $stmts = array_filter(
                array_map('trim', explode(";\n", $sql)),
                static fn(string $s): bool =>
                    $s !== '' && !preg_match('/^\s*(CREATE\s+DATABASE|USE\s+)/i', $s)
                    && !preg_match('/^--/', $s)
            );

            foreach ($stmts as $stmt) {
                $pdoDb->exec($stmt);
            }
            $info[] = '✓ Datenbanktabellen angelegt.';

        } catch (PDOException | RuntimeException $e) {
            $errors[] = 'Fehler beim Einrichten der Datenbank: ' . $e->getMessage();
        }
    }

    // ── Schritt 4: Admin-Benutzer anlegen ────────────────────────────────────
    if (empty($errors)) {
        try {
            $hash = password_hash($admin_pass, PASSWORD_BCRYPT);
            $stmt = $pdoDb->prepare(
                'INSERT INTO users (username, password_hash) VALUES (?, ?)'
            );
            $stmt->execute([$admin_user, $hash]);
            $info[] = "✓ Admin-Account '{$admin_user}' erstellt.";
        } catch (PDOException $e) {
            $errors[] = 'Admin-Account anlegen fehlgeschlagen: ' . $e->getMessage();
        }
    }

    // ── Schritt 5: config.php schreiben ──────────────────────────────────────
    if (empty($errors)) {
        $cfg = '<?php' . "\n"
            . '# Datenbankverbindung – generiert von setup.php am ' . date('Y-m-d H:i:s') . "\n\n"
            . 'define(\'DB_HOST\', ' . var_export($db_host, true) . ");\n"
            . 'define(\'DB_USER\', ' . var_export($db_user, true) . ");\n"
            . 'define(\'DB_PASS\', ' . var_export($db_pass, true) . ");\n"
            . 'define(\'DB_NAME\', ' . var_export($db_name, true) . ");\n\n"
            . "try {\n"
            . '    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);' . "\n"
            . '    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);' . "\n"
            . "} catch (PDOException \$e) {\n"
            . '    die("Datenbankverbindung fehlgeschlagen: " . $e->getMessage());' . "\n"
            . "}\n";

        if (file_put_contents(__DIR__ . '/config.php', $cfg) === false) {
            $errors[] = 'config.php konnte nicht geschrieben werden (Schreibrechte prüfen).';
        } else {
            $info[] = '✓ config.php erstellt.';
        }
    }

    // ── Schritt 6: Setup als abgeschlossen markieren ─────────────────────────
    if (empty($errors)) {
        file_put_contents(
            __DIR__ . '/.setup_complete',
            'Setup abgeschlossen: ' . date('Y-m-d H:i:s') . "\n"
        );
        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup – Favorites</title>
    <link href="assets/src/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #1a1a2e; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .setup-card { background: #16213e; border: 1px solid #0f3460; border-radius: 12px; color: #e0e0e0; max-width: 580px; width: 100%; padding: 2rem; box-shadow: 0 8px 32px rgba(0,0,0,.5); }
        .setup-card h1 { font-size: 1.6rem; color: #e94560; margin-bottom: .25rem; }
        .setup-card .subtitle { color: #8a8a9a; margin-bottom: 1.5rem; font-size: .9rem; }
        .section-title { font-size: .8rem; text-transform: uppercase; letter-spacing: .08em; color: #5a5a7a; margin: 1.2rem 0 .6rem; }
        .form-control, .form-control:focus {
            background: #0d1b2a; color: #e0e0e0; border-color: #0f3460;
        }
        .form-control:focus { border-color: #e94560; box-shadow: 0 0 0 .2rem rgba(233,69,96,.15); }
        .form-control::placeholder { color: #4a4a6a; }
        .btn-setup { background: #e94560; border: none; color: #fff; width: 100%; padding: .7rem; border-radius: 8px; font-size: 1rem; }
        .btn-setup:hover { background: #c73652; }
        .alert-setup-error { background: rgba(233,69,96,.15); border: 1px solid #e94560; border-radius: 8px; padding: .8rem 1rem; color: #e94560; }
        .alert-setup-info  { background: rgba(32,178,90,.12); border: 1px solid rgba(32,178,90,.4); border-radius: 8px; padding: .8rem 1rem; color: #5dbb7a; }
        .step-badge { display: inline-block; background: #e94560; color: #fff; border-radius: 50%; width: 1.5rem; height: 1.5rem; text-align: center; line-height: 1.5rem; font-size: .8rem; margin-right: .5rem; }
        .form-label { color: #b0b0c8; font-size: .88rem; }
        label small { color: #5a5a7a; }
        .divider { border-color: #0f3460; margin: 1.4rem 0; }
        .success-icon { font-size: 3rem; text-align: center; margin-bottom: 1rem; }
        a.btn-login { display: block; text-align: center; background: #e94560; color: #fff; border-radius: 8px; padding: .7rem; text-decoration: none; font-size: 1rem; margin-top: 1rem; }
        a.btn-login:hover { background: #c73652; color: #fff; }
        .config-hint { background: rgba(255,193,7,.08); border: 1px solid rgba(255,193,7,.3); border-radius: 8px; padding: .75rem 1rem; color: #ffc107; font-size: .85rem; margin-bottom: 1rem; }
    </style>
</head>
<body>
<div class="setup-card">

    <?php if ($success): ?>
    <!-- ── Erfolg ─────────────────────────────────────────────── -->
    <div class="success-icon">🎉</div>
    <h1 style="text-align:center">Setup abgeschlossen!</h1>
    <p class="subtitle" style="text-align:center">Die App wurde erfolgreich eingerichtet.</p>
    <div class="alert-setup-info mb-3">
        <?php foreach ($info as $msg): ?>
            <div><?= htmlspecialchars($msg) ?></div>
        <?php endforeach ?>
    </div>
    <a href="login.php" class="btn-login">→ Zum Login</a>

    <?php else: ?>
    <!-- ── Formular ───────────────────────────────────────────── -->
    <h1>⭐ Favorites – Setup</h1>
    <p class="subtitle">Richte die App in wenigen Schritten ein.</p>

    <?php if (!file_exists(__DIR__ . '/config.php') && file_exists(__DIR__ . '/config.expample.php')): ?>
    <div class="config-hint">
        ℹ️ <strong>config.php fehlt noch.</strong> Das Setup erstellt sie automatisch aus deinen Angaben unten.
    </div>
    <?php endif ?>

    <?php if (!empty($errors)): ?>
    <div class="alert-setup-error mb-3">
        <?php foreach ($errors as $e): ?>
            <div>✗ <?= htmlspecialchars($e) ?></div>
        <?php endforeach ?>
        <?php if (!empty($info)): ?>
            <hr style="border-color:rgba(233,69,96,.3)">
            <?php foreach ($info as $msg): ?>
                <div style="color:#5dbb7a"><?= htmlspecialchars($msg) ?></div>
            <?php endforeach ?>
        <?php endif ?>
    </div>
    <?php endif ?>

    <form method="post" novalidate>

        <!-- Datenbankverbindung -->
        <div class="section-title"><span class="step-badge">1</span>Datenbankverbindung</div>

        <div class="mb-2">
            <label class="form-label" for="db_host">Hostname <small>(z. B. localhost)</small></label>
            <input type="text" id="db_host" name="db_host" class="form-control"
                   value="<?= htmlspecialchars($_POST['db_host'] ?? (getenv('DB_HOST') ?: 'localhost')) ?>" required>
        </div>
        <div class="mb-2">
            <label class="form-label" for="db_user">Datenbankbenutzer</label>
            <input type="text" id="db_user" name="db_user" class="form-control"
                   value="<?= htmlspecialchars($_POST['db_user'] ?? (getenv('DB_USER') ?: '')) ?>" autocomplete="username" required>
        </div>
        <div class="mb-2">
            <label class="form-label" for="db_pass">Datenbankpasswort <small>(kann leer sein)</small></label>
            <input type="password" id="db_pass" name="db_pass" class="form-control"
                   value="<?= htmlspecialchars($_POST['db_pass'] ?? (getenv('DB_PASS') ?: '')) ?>" autocomplete="current-password">
        </div>
        <div class="mb-2">
            <label class="form-label" for="db_name">Datenbankname <small>(wird angelegt falls nicht vorhanden)</small></label>
            <input type="text" id="db_name" name="db_name" class="form-control"
                   value="<?= htmlspecialchars($_POST['db_name'] ?? (getenv('DB_NAME') ?: 'favorites')) ?>" required>
        </div>

        <hr class="divider">

        <!-- Admin-Account -->
        <div class="section-title"><span class="step-badge">2</span>Admin-Account</div>

        <div class="mb-2">
            <label class="form-label" for="admin_user">Benutzername</label>
            <input type="text" id="admin_user" name="admin_user" class="form-control"
                   value="<?= htmlspecialchars($_POST['admin_user'] ?? '') ?>" autocomplete="username" required>
        </div>
        <div class="mb-2">
            <label class="form-label" for="admin_pass">Passwort <small>(min. 8 Zeichen)</small></label>
            <input type="password" id="admin_pass" name="admin_pass" class="form-control" autocomplete="new-password" required>
        </div>
        <div class="mb-3">
            <label class="form-label" for="admin_pass2">Passwort wiederholen</label>
            <input type="password" id="admin_pass2" name="admin_pass2" class="form-control" autocomplete="new-password" required>
        </div>

        <button type="submit" class="btn-setup">Setup starten →</button>
    </form>
    <?php endif ?>

</div>
</body>
</html>
