<?php
require_once 'config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

$action = $_POST['action'] ?? '';
$file_id = $_POST['file_id'] ?? 0;
$post_id = $_POST['post_id'] ?? 0;

switch ($action) {
    case 'delete_file':
        try {
            // Verify ownership
            $stmt = $pdo->prepare("
                SELECT f.file_path 
                FROM files f 
                JOIN posts p ON f.post_id = p.id 
                WHERE f.id = ? AND p.user_id = ?
            ");
            $stmt->execute([$file_id, $_SESSION['user_id']]);
            $file = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($file) {
                // Delete physical file
                if (file_exists($file['file_path'])) {
                    unlink($file['file_path']);
                }
                
                // Delete database record
                $stmt = $pdo->prepare("DELETE FROM files WHERE id = ?");
                $stmt->execute([$file_id]);
                
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'message' => 'File not found or access denied']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;
        
    case 'replace_file':
        try {
            if (!isset($_FILES['new_file']) || $_FILES['new_file']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['success' => false, 'message' => 'No file uploaded']);
                exit;
            }
            
            // Verify ownership
            $stmt = $pdo->prepare("
                SELECT f.file_path, f.post_id 
                FROM files f 
                JOIN posts p ON f.post_id = p.id 
                WHERE f.id = ? AND p.user_id = ?
            ");
            $stmt->execute([$file_id, $_SESSION['user_id']]);
            $file = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($file) {
                $upload_dir = 'uploads/post_' . $file['post_id'] . '/';
                
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $file_name = $_FILES['new_file']['name'];
                $file_type = $_FILES['new_file']['type'];
                $file_extension = pathinfo($file_name, PATHINFO_EXTENSION);
                $new_file_name = uniqid() . '.' . $file_extension;
                $file_path = $upload_dir . $new_file_name;
                
                if (move_uploaded_file($_FILES['new_file']['tmp_name'], $file_path)) {
                    // Delete old file
                    if (file_exists($file['file_path'])) {
                        unlink($file['file_path']);
                    }
                    
                    // Update database
                    $stmt = $pdo->prepare("UPDATE files SET file_name = ?, file_path = ?, file_type = ? WHERE id = ?");
                    $stmt->execute([$file_name, $file_path, $file_type, $file_id]);
                    
                    echo json_encode([
                        'success' => true, 
                        'new_path' => $file_path,
                        'new_name' => $file_name,
                        'new_type' => $file_type
                    ]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to upload file']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'File not found or access denied']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}
?>
