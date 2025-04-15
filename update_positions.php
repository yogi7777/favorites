<?php
require_once 'config.php';
require_once 'auth.php';
checkAuth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_SESSION['user_id'];
    $data = json_decode(file_get_contents('php://input'), true);

    if ($data) {
        $stmt = $pdo->prepare("UPDATE categories SET position = ? WHERE id = ? AND user_id = ?");
        foreach ($data as $item) {
            $stmt->execute([$item['position'], $item['id'], $user_id]);
        }
    }
}
?>