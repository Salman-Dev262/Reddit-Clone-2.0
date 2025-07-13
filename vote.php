<?php
require_once 'config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$post_id = $input['post_id'] ?? 0;
$vote_type = $input['vote_type'] ?? '';
$user_id = $_SESSION['user_id'];

if (!$post_id || !in_array($vote_type, ['up', 'down'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

try {
    // Check if user already voted on this post
    $stmt = $pdo->prepare("SELECT * FROM votes WHERE post_id = ? AND user_id = ?");
    $stmt->execute([$post_id, $user_id]);
    $existing_vote = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing_vote) {
        if ($existing_vote['vote_type'] == $vote_type) {
            // Remove vote if clicking same button
            $stmt = $pdo->prepare("DELETE FROM votes WHERE post_id = ? AND user_id = ?");
            $stmt->execute([$post_id, $user_id]);
        } else {
            // Update vote type
            $stmt = $pdo->prepare("UPDATE votes SET vote_type = ? WHERE post_id = ? AND user_id = ?");
            $stmt->execute([$vote_type, $post_id, $user_id]);
        }
    } else {
        // Insert new vote
        $stmt = $pdo->prepare("INSERT INTO votes (post_id, user_id, vote_type) VALUES (?, ?, ?)");
        $stmt->execute([$post_id, $user_id, $vote_type]);
    }
    
    // Update post vote count
    $stmt = $pdo->prepare("
        UPDATE posts SET votes = (
            SELECT COALESCE(SUM(CASE WHEN vote_type = 'up' THEN 1 ELSE -1 END), 0)
            FROM votes WHERE post_id = ?
        ) WHERE id = ?
    ");
    $stmt->execute([$post_id, $post_id]);
    
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
