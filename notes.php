<?php
require_once 'config.php';
require_once 'auth.php';
checkAuth();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$action = $_POST['action'] ?? '';

switch ($action) {

    // ----------------------------------------------------------------
    // Live-save note content
    // ----------------------------------------------------------------
    case 'update_content': {
        $id      = (int)($_POST['id'] ?? 0);
        $content = $_POST['content'] ?? '';

        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid input']);
            exit;
        }

        $stmt = $pdo->prepare('UPDATE notes SET content = ? WHERE id = ? AND user_id = ?');
        $stmt->execute([$content, $id, $userId]);
        echo json_encode(['ok' => true]);
        break;
    }

    // ----------------------------------------------------------------
    // Save free position (x/y) + size (width/height) of a note
    // ----------------------------------------------------------------
    case 'update_position': {
        $id    = (int)($_POST['id'] ?? 0);
        $tabId = (int)($_POST['tab_id'] ?? 0);
        $posX  = isset($_POST['pos_x'])  && $_POST['pos_x']  !== '' ? (int)$_POST['pos_x']  : null;
        $posY  = isset($_POST['pos_y'])  && $_POST['pos_y']  !== '' ? (int)$_POST['pos_y']  : null;
        $w     = isset($_POST['width'])  && $_POST['width']  !== '' ? max(150, (int)$_POST['width'])  : null;
        $h     = isset($_POST['height']) && $_POST['height'] !== '' ? max(80,  (int)$_POST['height']) : null;

        if ($id <= 0 || $tabId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid input']);
            exit;
        }

        // Verify ownership
        $stmt = $pdo->prepare('SELECT 1 FROM notes WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
        if (!$stmt->fetchColumn()) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            exit;
        }

        $stmt = $pdo->prepare('SELECT 1 FROM tabs WHERE id = ? AND user_id = ?');
        $stmt->execute([$tabId, $userId]);
        if (!$stmt->fetchColumn()) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            exit;
        }

        $updates = [];
        $params  = [];
        if ($posX !== null) { $updates[] = 'pos_x = ?';  $params[] = $posX; }
        if ($posY !== null) { $updates[] = 'pos_y = ?';  $params[] = $posY; }
        if ($w    !== null) { $updates[] = 'width = ?';  $params[] = $w; }
        if ($h    !== null) { $updates[] = 'height = ?'; $params[] = $h; }

        if (!empty($updates)) {
            $params[] = $id;
            $params[] = $tabId;
            $stmt = $pdo->prepare(
                'UPDATE note_tabs SET ' . implode(', ', $updates) . ' WHERE note_id = ? AND tab_id = ?'
            );
            $stmt->execute($params);
        }

        echo json_encode(['ok' => true]);
        break;
    }

    // ----------------------------------------------------------------
    // Save free position of a category
    // ----------------------------------------------------------------
    case 'update_cat_position': {
        $catId = (int)($_POST['cat_id'] ?? 0);
        $tabId = (int)($_POST['tab_id'] ?? 0);
        $posX  = isset($_POST['pos_x']) && $_POST['pos_x'] !== '' ? (int)$_POST['pos_x'] : null;
        $posY  = isset($_POST['pos_y']) && $_POST['pos_y'] !== '' ? (int)$_POST['pos_y'] : null;

        if ($catId <= 0 || $tabId <= 0 || $posX === null || $posY === null) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid input']);
            exit;
        }

        $stmt = $pdo->prepare('SELECT 1 FROM categories WHERE id = ? AND user_id = ?');
        $stmt->execute([$catId, $userId]);
        if (!$stmt->fetchColumn()) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            exit;
        }

        $stmt = $pdo->prepare('SELECT 1 FROM tabs WHERE id = ? AND user_id = ?');
        $stmt->execute([$tabId, $userId]);
        if (!$stmt->fetchColumn()) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            exit;
        }

        $stmt = $pdo->prepare(
            'UPDATE category_tab_positions SET pos_x = ?, pos_y = ? WHERE tab_id = ? AND category_id = ?'
        );
        $stmt->execute([$posX, $posY, $tabId, $catId]);
        echo json_encode(['ok' => true]);
        break;
    }

    // ----------------------------------------------------------------
    // Save grid order for multiple notes (mobile sort)
    // Body: JSON-Array [{id, tab_id, position}, ...]
    // ----------------------------------------------------------------
    case 'update_grid_positions': {
        $dataJson = $_POST['data'] ?? '';
        if ($dataJson !== '') {
            $data = json_decode($dataJson, true);
        } else {
            $raw  = file_get_contents('php://input');
            $data = json_decode($raw, true);
        }

        if (!is_array($data)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON']);
            exit;
        }

        $stmt = $pdo->prepare(
            'UPDATE note_tabs SET position = ?
             WHERE note_id = ? AND tab_id = ?
               AND note_id IN (SELECT id FROM notes WHERE user_id = ?)'
        );
        foreach ($data as $item) {
            $noteId   = (int)($item['id']       ?? 0);
            $tabId    = (int)($item['tab_id']   ?? 0);
            $position = (int)($item['position'] ?? 0);
            if ($noteId > 0 && $tabId > 0) {
                $stmt->execute([$position, $noteId, $tabId, $userId]);
            }
        }
        echo json_encode(['ok' => true]);
        break;
    }

    // ----------------------------------------------------------------
    // Delete note
    // ----------------------------------------------------------------
    case 'delete': {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid input']);
            exit;
        }
        $stmt = $pdo->prepare('DELETE FROM notes WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
        echo json_encode(['ok' => true]);
        break;
    }

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
}
