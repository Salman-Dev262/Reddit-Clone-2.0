<?php
require_once 'config/database.php';

$post_id = $_GET['id'] ?? 0;

// Fetch post with all related data
$stmt = $pdo->prepare("
    SELECT p.*, u.username, c.name as category_name,
           (SELECT vote_type FROM votes WHERE post_id = p.id AND user_id = ?) as user_vote,
           (SELECT COUNT(*) FROM saved_posts WHERE post_id = p.id AND user_id = ?) as is_saved
    FROM posts p 
    JOIN users u ON p.user_id = u.id 
    JOIN categories c ON p.category_id = c.id 
    WHERE p.id = ?
");
$stmt->execute([($_SESSION['user_id'] ?? 0), ($_SESSION['user_id'] ?? 0), $post_id]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$post) {
    header('Location: index.php');
    exit;
}

// Fetch post files
$stmt = $pdo->prepare("SELECT * FROM files WHERE post_id = ? ORDER BY id");
$stmt->execute([$post_id]);
$post_files = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Check if current user owns this post
$is_post_owner = isset($_SESSION['user_id']) && $_SESSION['user_id'] == $post['user_id'];

// Handle post deletion
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_post']) && $is_post_owner) {
    try {
        $pdo->beginTransaction();
        
        // Delete related records first
        $pdo->prepare("DELETE FROM votes WHERE post_id = ?")->execute([$post_id]);
        $pdo->prepare("DELETE FROM comments WHERE post_id = ?")->execute([$post_id]);
        $pdo->prepare("DELETE FROM saved_posts WHERE post_id = ?")->execute([$post_id]);
        $pdo->prepare("DELETE FROM post_tags WHERE post_id = ?")->execute([$post_id]);
        $pdo->prepare("DELETE FROM files WHERE post_id = ?")->execute([$post_id]);
        
        // Delete the post
        $stmt = $pdo->prepare("DELETE FROM posts WHERE id = ? AND user_id = ?");
        $stmt->execute([$post_id, $_SESSION['user_id']]);
        
        $pdo->commit();
        header("Location: index.php");
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = 'Error deleting post';
    }
}

// Fetch post tags
$stmt = $pdo->prepare("
    SELECT t.name 
    FROM tags t 
    JOIN post_tags pt ON t.id = pt.tag_id 
    WHERE pt.post_id = ?
");
$stmt->execute([$post_id]);
$post_tags = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Fetch comments with vote counts and files
$stmt = $pdo->prepare("
    SELECT c.*, u.username,
           COALESCE(SUM(CASE WHEN cv.vote_type = 'up' THEN 1 WHEN cv.vote_type = 'down' THEN -1 ELSE 0 END), 0) as votes,
           (SELECT vote_type FROM comment_votes WHERE comment_id = c.id AND user_id = ?) as user_vote
    FROM comments c 
    JOIN users u ON c.user_id = u.id 
    LEFT JOIN comment_votes cv ON c.id = cv.comment_id
    WHERE c.post_id = ? 
    GROUP BY c.id
    ORDER BY votes DESC, c.created_at ASC
");
$stmt->execute([($_SESSION['user_id'] ?? 0), $post_id]);
$comments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch comment files for each comment
foreach ($comments as &$comment) {
    $stmt = $pdo->prepare("SELECT * FROM comment_files WHERE comment_id = ?");
    $stmt->execute([$comment['id']]);
    $comment['files'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Handle comment submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_SESSION['user_id']) && isset($_POST['content'])) {
    $content = trim($_POST['content']);
    
    if (!empty($content)) {
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("INSERT INTO comments (post_id, user_id, content) VALUES (?, ?, ?)");
            $stmt->execute([$post_id, $_SESSION['user_id'], $content]);
            $comment_id = $pdo->lastInsertId();
            
            // Handle comment file uploads
            if (isset($_FILES['comment_files']) && !empty($_FILES['comment_files']['name'][0])) {
                $upload_dir = 'uploads/comment_' . $comment_id . '/';
                
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $file_count = count($_FILES['comment_files']['name']);
                for ($i = 0; $i < $file_count; $i++) {
                    if ($_FILES['comment_files']['error'][$i] === UPLOAD_ERR_OK) {
                        $file_name = $_FILES['comment_files']['name'][$i];
                        $file_type = $_FILES['comment_files']['type'][$i];
                        $file_extension = pathinfo($file_name, PATHINFO_EXTENSION);
                        $new_file_name = uniqid() . '.' . $file_extension;
                        $file_path = $upload_dir . $new_file_name;
                        
                        if (move_uploaded_file($_FILES['comment_files']['tmp_name'][$i], $file_path)) {
                            $stmt = $pdo->prepare("INSERT INTO comment_files (comment_id, file_name, file_path, file_type) VALUES (?, ?, ?, ?)");
                            $stmt->execute([$comment_id, $file_name, $file_path, $file_type]);
                        }
                    }
                }
            }
            
            $pdo->commit();
            header("Location: post.php?id=$post_id");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Error posting comment';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($post['title']); ?> : r/<?php echo htmlspecialchars($post['category_name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .vote-btn {
            border: none !important;
            background: none !important;
            color: #6c757d;
            padding: 0.25rem;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .vote-btn:hover {
            background-color: #f8f9fa !important;
            color: #495057;
            border-radius: 4px;
        }
        
        .vote-btn.upvoted {
            color: #dc3545 !important;
        }
        
        .vote-btn.downvoted {
            color: #7193FF !important;
        }
        
        .vote-count {
            font-size: 0.875rem;
            font-weight: bold;
            color: #495057;
            margin: 0.25rem 0;
        }
        
        .post-meta a {
            color: #dc3545 !important;
            text-decoration: none;
            font-weight: bold;
        }
        
        .post-meta a:hover {
            text-decoration: underline;
        }

        .post-image {
            max-width: 100%;
            height: auto;
            border-radius: 8px;
            margin: 1rem 0;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            cursor: pointer;
        }

        .file-attachment {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 1rem;
            margin: 0.5rem 0;
            display: inline-block;
        }

        .comment-file-upload {
            border: 2px dashed #dee2e6;
            border-radius: 8px;
            padding: 1rem;
            text-align: center;
            background: #f8f9fa;
            cursor: pointer;
            margin-top: 1rem;
        }

        .comment-file-upload:hover {
            border-color: #dc3545;
            background: #f8d7da;
        }
    </style>
</head>
<body class="bg-light">
    <!-- Header -->
    <nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom sticky-top">
        <div class="container-fluid">
            <a class="navbar-brand d-flex align-items-center" href="index.php">
                <i class="fab fa-reddit-alien text-danger me-2" style="font-size: 2rem;"></i>
                <span class="fw-bold text-dark">reddify</span>
            </a>
            
            <div class="d-flex align-items-center">
                <?php if (isset($_SESSION['user_id'])): ?>
                    <a href="create_post.php" class="btn btn-outline-danger me-2 d-none d-md-block">
                        <i class="fas fa-plus me-1"></i>Create Post
                    </a>
                    <div class="dropdown">
                        <button class="btn btn-light dropdown-toggle d-flex align-items-center" type="button" data-bs-toggle="dropdown">
                            <i class="fas fa-user-circle me-2"></i>
                            <span class="d-none d-md-inline">u/<?php echo htmlspecialchars($_SESSION['username']); ?></span>
                        </button>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="profile.php?user=<?php echo urlencode($_SESSION['username']); ?>"><i class="fas fa-user me-2"></i>My Profile</a></li>
                            <li><a class="dropdown-item" href="create_post.php"><i class="fas fa-plus me-2"></i>Create Post</a></li>
                            <li><a class="dropdown-item" href="create_category.php"><i class="fas fa-users me-2"></i>Create Community</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Log Out</a></li>
                        </ul>
                    </div>
                <?php else: ?>
                    <a href="login.php" class="btn btn-outline-danger me-2">Log In</a>
                    <a href="register.php" class="btn btn-danger">Sign Up</a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="row">
            <!-- Main Content -->
            <div class="col-lg-8">
                <!-- Post -->
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-1">
                                <div class="vote-section text-center">
                                    <button class="vote-btn <?php echo $post['user_vote'] === 'up' ? 'upvoted' : ''; ?>" 
                                            onclick="vote(<?php echo $post['id']; ?>, 'up')">
                                        <i class="fas fa-arrow-up"></i>
                                    </button>
                                    <div class="vote-count"><?php echo formatNumber($post['votes']); ?></div>
                                    <button class="vote-btn <?php echo $post['user_vote'] === 'down' ? 'downvoted' : ''; ?>" 
                                            onclick="vote(<?php echo $post['id']; ?>, 'down')">
                                        <i class="fas fa-arrow-down"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="col-11">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div class="post-meta">
                                        <a href="category.php?id=<?php echo $post['category_id']; ?>">
                                            r/<?php echo htmlspecialchars($post['category_name']); ?>
                                        </a>
                                        <span class="text-muted">• Posted by</span>
                                        <a href="profile.php?user=<?php echo urlencode($post['username']); ?>">
                                            u/<?php echo htmlspecialchars($post['username']); ?>
                                        </a>
                                        <span class="text-muted">• <?php echo timeAgo($post['created_at']); ?> ago</span>
                                    </div>
                                    <?php if ($is_post_owner): ?>
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                                <i class="fas fa-ellipsis-h"></i>
                                            </button>
                                            <ul class="dropdown-menu">
                                                <li><a class="dropdown-item" href="edit_post.php?id=<?php echo $post['id']; ?>">
                                                    <i class="fas fa-edit me-2"></i>Edit
                                                </a></li>
                                                <li><hr class="dropdown-divider"></li>
                                                <li>
                                                    <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this post?')">
                                                        <button type="submit" name="delete_post" class="dropdown-item text-danger">
                                                            <i class="fas fa-trash me-2"></i>Delete
                                                        </button>
                                                    </form>
                                                </li>
                                            </ul>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <h1 class="h3 mb-3"><?php echo htmlspecialchars($post['title']); ?></h1>

                                <?php if ($post['content']): ?>
                                    <div class="mb-3" style="white-space: pre-wrap;"><?php echo htmlspecialchars($post['content']); ?></div>
                                <?php endif; ?>

                                <!-- Post Files -->
                                <?php if (!empty($post_files)): ?>
                                    <div class="post-files mb-3">
                                        <?php foreach ($post_files as $file): ?>
                                            <?php if (strpos($file['file_type'], 'image/') === 0): ?>
                                                <div class="text-center">
                                                    <img src="<?php echo htmlspecialchars($file['file_path']); ?>" 
                                                         class="post-image" alt="Post image"
                                                         onclick="openImageModal('<?php echo htmlspecialchars($file['file_path']); ?>')">
                                                </div>
                                            <?php else: ?>
                                                <div class="file-attachment">
                                                    <i class="fas fa-file me-2"></i>
                                                    <a href="<?php echo htmlspecialchars($file['file_path']); ?>" target="_blank" class="text-decoration-none">
                                                        <?php echo htmlspecialchars($file['file_name']); ?>
                                                    </a>
                                                </div>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($post_tags)): ?>
                                    <div class="mb-3">
                                        <?php foreach ($post_tags as $tag): ?>
                                            <span class="badge bg-secondary me-1"><?php echo htmlspecialchars($tag); ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <div class="d-flex align-items-center">
                                    <span class="me-3">
                                        <i class="far fa-comment-alt me-1"></i>
                                        <?php echo count($comments); ?> Comments
                                    </span>
                                    <button class="btn btn-sm btn-outline-secondary me-2">
                                        <i class="fas fa-share me-1"></i>Share
                                    </button>
                                    <button class="btn btn-sm btn-outline-secondary" onclick="savePost(<?php echo $post['id']; ?>)">
                                        <i class="<?php echo $post['is_saved'] ? 'fas' : 'far'; ?> fa-bookmark me-1"></i>
                                        <?php echo $post['is_saved'] ? 'Saved' : 'Save'; ?>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Comment Form -->
                <?php if (isset($_SESSION['user_id'])): ?>
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="mb-2">
                            <small class="text-muted">Comment as u/<?php echo htmlspecialchars($_SESSION['username']); ?></small>
                        </div>
                        <form method="POST" enctype="multipart/form-data" id="commentForm">
                            <div class="mb-3">
                                <textarea class="form-control" name="content" rows="4" 
                                          placeholder="What are your thoughts?" required></textarea>
                            </div>
                            
                            <!-- Comment File Upload -->
                            <div class="comment-file-upload" id="commentFileUpload">
                                <i class="fas fa-paperclip me-2"></i>
                                <span>Click to attach files</span>
                                <input type="file" name="comment_files[]" multiple style="display: none;" id="commentFiles">
                            </div>
                            <div id="commentFilePreview" class="mt-2"></div>
                            
                            <div class="d-flex justify-content-end mt-3">
                                <button type="submit" class="btn btn-danger">
                                    <i class="fas fa-comment me-1"></i>Comment
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php else: ?>
                <div class="card mb-4">
                    <div class="card-body text-center">
                        <p class="mb-3">Log in or sign up to leave a comment</p>
                        <a href="login.php" class="btn btn-outline-danger me-2">Log In</a>
                        <a href="register.php" class="btn btn-danger">Sign Up</a>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Comments -->
                <div class="card">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><?php echo count($comments); ?> Comments</h5>
                    </div>
                    <?php if (empty($comments)): ?>
                        <div class="card-body text-center py-5">
                            <i class="fas fa-comments text-muted mb-3" style="font-size: 3rem;"></i>
                            <h6>No comments yet</h6>
                            <p class="text-muted">Be the first to share what you think!</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($comments as $comment): ?>
                            <div class="card-body border-bottom">
                                <div class="row">
                                    <div class="col-1">
                                        <div class="vote-section text-center">
                                            <button class="vote-btn <?php echo $comment['user_vote'] === 'up' ? 'upvoted' : ''; ?>" 
                                                    onclick="voteComment(<?php echo $comment['id']; ?>, 'up')" style="width: 24px; height: 24px;">
                                                <i class="fas fa-arrow-up" style="font-size: 12px;"></i>
                                            </button>
                                            <div class="vote-count" style="font-size: 12px;"><?php echo $comment['votes']; ?></div>
                                            <button class="vote-btn <?php echo $comment['user_vote'] === 'down' ? 'downvoted' : ''; ?>" 
                                                    onclick="voteComment(<?php echo $comment['id']; ?>, 'down')" style="width: 24px; height: 24px;">
                                                <i class="fas fa-arrow-down" style="font-size: 12px;"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="col-11">
                                        <div class="mb-2">
                                            <a href="profile.php?user=<?php echo urlencode($comment['username']); ?>" class="fw-bold text-decoration-none" style="color: #dc3545;">
                                                u/<?php echo htmlspecialchars($comment['username']); ?>
                                            </a>
                                            <span class="text-muted">• <?php echo timeAgo($comment['created_at']); ?> ago</span>
                                        </div>
                                        <div style="white-space: pre-wrap;" class="mb-2">
                                            <?php echo nl2br(htmlspecialchars($comment['content'])); ?>
                                        </div>
                                        
                                        <!-- Comment Files -->
                                        <?php if (!empty($comment['files'])): ?>
                                            <div class="comment-files mb-2">
                                                <?php foreach ($comment['files'] as $file): ?>
                                                    <?php if (strpos($file['file_type'], 'image/') === 0): ?>
                                                        <img src="<?php echo htmlspecialchars($file['file_path']); ?>" 
                                                             class="img-fluid rounded me-2 mb-2" 
                                                             style="max-width: 200px; cursor: pointer;"
                                                             onclick="openImageModal('<?php echo htmlspecialchars($file['file_path']); ?>')">
                                                    <?php else: ?>
                                                        <div class="file-attachment me-2 mb-2">
                                                            <i class="fas fa-file me-1"></i>
                                                            <a href="<?php echo htmlspecialchars($file['file_path']); ?>" target="_blank" class="text-decoration-none small">
                                                                <?php echo htmlspecialchars($file['file_name']); ?>
                                                            </a>
                                                        </div>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="col-lg-4">
                <div class="card mb-4">
                    <div class="card-header bg-danger text-white">
                        <h6 class="mb-0">About Community</h6>
                    </div>
                    <div class="card-body">
                        <h6 class="text-danger">r/<?php echo htmlspecialchars($post['category_name']); ?></h6>
                        <p class="text-muted small mb-3">
                            Community for discussing topics related to <?php echo htmlspecialchars($post['category_name']); ?>
                        </p>
                        <a href="category.php?id=<?php echo $post['category_id']; ?>" 
                           class="btn btn-outline-danger w-100">
                            View Community
                        </a>
                    </div>
                </div>

                <?php if (isset($_SESSION['user_id'])): ?>
                <div class="card">
                    <div class="card-header bg-danger text-white">
                        <h6 class="mb-0">Create</h6>
                    </div>
                    <div class="card-body">
                        <a href="create_post.php" class="btn btn-danger w-100 mb-2">
                            <i class="fas fa-plus me-2"></i>Create Post
                        </a>
                        <a href="create_category.php" class="btn btn-outline-danger w-100">
                            <i class="fas fa-users me-2"></i>Create Community
                        </a>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Image Modal -->
    <div class="modal fade" id="imageModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center p-0">
                    <img id="modalImage" src="/placeholder.svg" class="img-fluid" alt="Full size image">
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Comment file upload
        document.getElementById('commentFileUpload').addEventListener('click', function() {
            document.getElementById('commentFiles').click();
        });

        document.getElementById('commentFiles').addEventListener('change', function() {
            const files = Array.from(this.files);
            const preview = document.getElementById('commentFilePreview');
            preview.innerHTML = '';
            
            files.forEach(file => {
                const fileDiv = document.createElement('div');
                fileDiv.className = 'file-attachment me-2 mb-2';
                fileDiv.innerHTML = `<i class="fas fa-file me-1"></i><small>${file.name}</small>`;
                preview.appendChild(fileDiv);
            });
        });

        function vote(postId, voteType) {
            <?php if (!isset($_SESSION['user_id'])): ?>
                window.location.href = 'login.php';
                return;
            <?php endif; ?>

            fetch('vote.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    post_id: postId,
                    vote_type: voteType
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Error voting: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
            });
        }

        function voteComment(commentId, voteType) {
            <?php if (!isset($_SESSION['user_id'])): ?>
                window.location.href = 'login.php';
                return;
            <?php endif; ?>

            fetch('vote_comment.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    comment_id: commentId,
                    vote_type: voteType
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Error voting: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
            });
        }

        function savePost(postId) {
            <?php if (!isset($_SESSION['user_id'])): ?>
                window.location.href = 'login.php';
                return;
            <?php endif; ?>

            fetch('save_post.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    post_id: postId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Error saving post: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
            });
        }

        function openImageModal(imageSrc) {
            document.getElementById('modalImage').src = imageSrc;
            new bootstrap.Modal(document.getElementById('imageModal')).show();
        }
    </script>
</body>
</html>
