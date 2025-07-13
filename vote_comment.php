<?php
require_once 'config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$comment_id = $input['comment_id'] ?? 0;
$vote_type = $input['vote_type'] ?? '';
$user_id = $_SESSION['user_id'];

if (!$comment_id || !in_array($vote_type, ['up', 'down'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

try {
    // Check if user already voted on this comment
    $stmt = $pdo->prepare("SELECT * FROM comment_votes WHERE comment_id = ? AND user_id = ?");
    $stmt->execute([$comment_id, $user_id]);
    $existing_vote = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing_vote) {
        if ($existing_vote['vote_type'] == $vote_type) {
            // Remove vote if clicking same button
            $stmt = $pdo->prepare("DELETE FROM comment_votes WHERE comment_id = ? AND user_id = ?");
            $stmt->execute([$comment_id, $user_id]);
        } else {
            // Update vote type
            $stmt = $pdo->prepare("UPDATE comment_votes SET vote_type = ? WHERE comment_id = ? AND user_id = ?");
            $stmt->execute([$vote_type, $comment_id, $user_id]);
        }
    } else {
        // Insert new vote
        $stmt = $pdo->prepare("INSERT INTO comment_votes (comment_id, user_id, vote_type) VALUES (?, ?, ?)");
        $stmt->execute([$comment_id, $user_id, $vote_type]);
    }
    
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
