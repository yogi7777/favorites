<?php
require_once 'config.php';
require_once 'auth.php';
checkAuth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_SESSION['user_id'];
    $data = json_decode(file_get_contents('php://input'), true);

    if ($data) {
        $tabSlug = $data[0]['tab'] ?? 'alle';

        if ($tabSlug === 'alle') {
            $stmt = $pdo->prepare("UPDATE categories SET position = ? WHERE id = ? AND user_id = ?");
            foreach ($data as $item) {
                $stmt->execute([$item['position'], $item['id'], $user_id]);
            }
            exit;
        }

        $stmt = $pdo->prepare("SELECT id FROM tabs WHERE user_id = ? AND slug = ? LIMIT 1");
        $stmt->execute([$user_id, $tabSlug]);
        $tabId = $stmt->fetchColumn();

        if (!$tabId) {
            http_response_code(400);
            exit;
        }

        $stmt = $pdo->prepare(
            "INSERT INTO category_tab_positions (tab_id, category_id, position)
             SELECT ?, c.id, ?
             FROM categories c
             JOIN category_tabs ct ON ct.category_id = c.id AND ct.tab_id = ?
             WHERE c.id = ? AND c.user_id = ?
             ON DUPLICATE KEY UPDATE position = VALUES(position)"
        );

        foreach ($data as $item) {
            $stmt->execute([$tabId, $item['position'], $tabId, $item['id'], $user_id]);
        }
    }
}
?>