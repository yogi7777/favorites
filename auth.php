<?php
session_start();

// Funktion zur Generierung eines sicheren Tokens
function generateToken($length = 64) {
    return bin2hex(random_bytes($length / 2));
}

// Funktion zur Prüfung der Authentifizierung
function checkAuth() {
    global $pdo;

    // Wenn die Session bereits aktiv ist, ist alles gut
    if (isset($_SESSION['user_id'])) {
        return;
    }

    // Prüfe, ob ein Remember-Token-Cookie existiert
    if (isset($_COOKIE['remember_token'])) {
        $token = $_COOKIE['remember_token'];

        // Suche das Token in der remember_tokens-Tabelle
        $stmt = $pdo->prepare("SELECT user_id, token_hash, expires_at FROM remember_tokens WHERE token_hash = ?");
        $stmt->execute([hash('sha256', $token)]);
        $tokenData = $stmt->fetch(PDO::FETCH_ASSOC);

        // Prüfe, ob das Token gültig ist und nicht abgelaufen
        if ($tokenData && hash_equals($tokenData['token_hash'], hash('sha256', $token)) && new DateTime() < new DateTime($tokenData['expires_at'])) {
            // Stelle die Session wieder her
            $_SESSION['user_id'] = $tokenData['user_id'];
            return;
        }

        // Ungültiges oder abgelaufenes Token: Cookie löschen
        setcookie('remember_token', '', time() - 3600, '/', '', true, true);
    }

    // Keine gültige Session oder Token: Zum Login weiterleiten
    header('Location: login.php');
    exit;
}

// Funktion zum Login
function login($username, $password, $remember = false, $device_name = '') {
    global $pdo;

    // Benutzer prüfen
    $stmt = $pdo->prepare("SELECT id, password_hash FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password_hash'])) {
        // Session setzen
        $_SESSION['user_id'] = $user['id'];

        // Falls "Angemeldet bleiben" gewählt wurde, Token erstellen
        if ($remember) {
            $token = generateToken();
            $tokenHash = hash('sha256', $token);
            $expiresAt = (new DateTime())->modify('+1 year')->format('Y-m-d H:i:s');
            $deviceName = trim($device_name) ?: null; // Leerer Name wird NULL

            // Token und Gerätename in der remember_tokens-Tabelle speichern
            $stmt = $pdo->prepare("INSERT INTO remember_tokens (user_id, token_hash, expires_at, device_name) VALUES (?, ?, ?, ?)");
            $stmt->execute([$user['id'], $tokenHash, $expiresAt, $deviceName]);

            // Cookie-Optionen für Sicherheit
            $cookieOptions = [
                'expires' => time() + 31536000, // 1 Jahr
                'path' => '/',
                'secure' => true, // Nur über HTTPS
                'httponly' => true, // Kein JavaScript-Zugriff
                'samesite' => 'Strict' // Schutz vor CSRF
            ];
            setcookie('remember_token', $token, $cookieOptions);
        }

        return true;
    }

    return false;
}

// Funktion zum Abrufen aller vertrauenswürdigen Geräte
function getTrustedDevices($userId) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT id, created_at, expires_at, device_name FROM remember_tokens WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Funktion zum Widerrufen eines bestimmten Geräts
function revokeDevice($userId, $tokenId) {
    global $pdo;
    $stmt = $pdo->prepare("DELETE FROM remember_tokens WHERE id = ? AND user_id = ?");
    $stmt->execute([$tokenId, $userId]);

    // Wenn das aktuelle Gerät widerrufen wird, Cookie löschen
    if (isset($_COOKIE['remember_token'])) {
        $stmt = $pdo->prepare("SELECT token_hash FROM remember_tokens WHERE id = ?");
        $stmt->execute([$tokenId]);
        $tokenData = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($tokenData && hash_equals($tokenData['token_hash'], hash('sha256', $_COOKIE['remember_token']))) {
            setcookie('remember_token', '', time() - 3600, '/', '', true, true);
        }
    }
}

// Funktion zum Widerrufen aller Geräte
function revokeAllDevices($userId) {
    global $pdo;
    $stmt = $pdo->prepare("DELETE FROM remember_tokens WHERE user_id = ?");
    $stmt->execute([$userId]);

    // Cookie löschen, falls vorhanden
    if (isset($_COOKIE['remember_token'])) {
        setcookie('remember_token', '', time() - 3600, '/', '', true, true);
    }
}

// Funktion zum Logout
function logout() {
    global $pdo;

    // Lösche das Remember-Token aus der Datenbank, falls vorhanden
    if (isset($_COOKIE['remember_token'])) {
        $stmt = $pdo->prepare("DELETE FROM remember_tokens WHERE token_hash = ?");
        $stmt->execute([hash('sha256', $_COOKIE['remember_token'])]);

        // Cookie löschen
        setcookie('remember_token', '', time() - 3600, '/', '', true, true);
    }

    // Session beenden
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit;
}