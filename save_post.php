<?php
require_once 'config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$post_id = $input['post_id'] ?? 0;
$user_id = $_SESSION['user_id'];

if (!$post_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid post ID']);
    exit;
}

try {
    // Check if post is already saved
    $stmt = $pdo->prepare("SELECT * FROM saved_posts WHERE post_id = ? AND user_id = ?");
    $stmt->execute([$post_id, $user_id]);
    $existing_save = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing_save) {
        // Remove save
        $stmt = $pdo->prepare("DELETE FROM saved_posts WHERE post_id = ? AND user_id = ?");
        $stmt->execute([$post_id, $user_id]);
        $action = 'unsaved';
    } else {
        // Add save
        $stmt = $pdo->prepare("INSERT INTO saved_posts (post_id, user_id) VALUES (?, ?)");
        $stmt->execute([$post_id, $user_id]);
        $action = 'saved';
    }
    
    echo json_encode(['success' => true, 'action' => $action]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
