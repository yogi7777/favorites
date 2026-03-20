<?php
// Legacy AJAX handler – redirects to full categories management page
require_once 'config.php';
require_once 'auth.php';
checkAuth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_SESSION['user_id'];
    $id      = (int)($_POST['id'] ?? 0);
    $name    = trim($_POST['name'] ?? '');

    if ($id > 0 && $name !== '') {
        $stmt = $pdo->prepare('UPDATE categories SET name = ? WHERE id = ? AND user_id = ?');
        $stmt->execute([$name, $id, $user_id]);
    }
}
// This file is kept for backwards compatibility.
// The full categories management is in categories.php
