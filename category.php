<?php
require_once 'config/database.php';

$category_id = $_GET['id'] ?? 0;
$sort = $_GET['sort'] ?? 'hot';

// Fetch category information
$stmt = $pdo->prepare("SELECT * FROM categories WHERE id = ?");
$stmt->execute([$category_id]);
$category = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$category) {
    header('Location: index.php');
    exit;
}

// Check if current user owns this category
$is_category_owner = isset($_SESSION['user_id']) && $_SESSION['user_id'] == ($category['user_id'] ?? 0);

// Handle category deletion
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_category']) && $is_category_owner) {
    try {
        $pdo->beginTransaction();
        
        // Delete all posts and related data in this category
        $stmt = $pdo->prepare("SELECT id FROM posts WHERE category_id = ?");
        $stmt->execute([$category_id]);
        $posts = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($posts as $post_id) {
            $pdo->prepare("DELETE FROM votes WHERE post_id = ?")->execute([$post_id]);
            $pdo->prepare("DELETE FROM comments WHERE post_id = ?")->execute([$post_id]);
            $pdo->prepare("DELETE FROM saved_posts WHERE post_id = ?")->execute([$post_id]);
            $pdo->prepare("DELETE FROM post_tags WHERE post_id = ?")->execute([$post_id]);
            $pdo->prepare("DELETE FROM files WHERE post_id = ?")->execute([$post_id]);
        }
        
        // Delete posts
        $pdo->prepare("DELETE FROM posts WHERE category_id = ?")->execute([$category_id]);
        
        // Delete the category
        $pdo->prepare("DELETE FROM categories WHERE id = ? AND user_id = ?")->execute([$category_id, $_SESSION['user_id']]);
        
        $pdo->commit();
        header("Location: index.php");
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = 'Error deleting category';
    }
}

// Build order clause based on sort
$orderClause = match($sort) {
    'new' => 'ORDER BY p.created_at DESC',
    'top' => 'ORDER BY p.votes DESC, p.created_at DESC',
    'rising' => 'ORDER BY (p.votes + 1) / POWER(TIMESTAMPDIFF(HOUR, p.created_at, NOW()) + 2, 1.8) DESC',
    default => 'ORDER BY (p.votes + 1) / POWER(TIMESTAMPDIFF(HOUR, p.created_at, NOW()) + 2, 1.8) DESC'
};

// Fetch posts in this category
$stmt = $pdo->prepare("
    SELECT p.*, u.username,
           f.file_path, f.file_type, f.file_name,
           (SELECT COUNT(*) FROM comments WHERE post_id = p.id) as comment_count,
           (SELECT COUNT(*) FROM saved_posts WHERE post_id = p.id AND user_id = ?) as is_saved,
           (SELECT vote_type FROM votes WHERE post_id = p.id AND user_id = ?) as user_vote
    FROM posts p 
    JOIN users u ON p.user_id = u.id 
    LEFT JOIN files f ON p.id = f.post_id
    WHERE p.category_id = ? 
    $orderClause
    LIMIT 25
");
$stmt->execute([($_SESSION['user_id'] ?? 0), ($_SESSION['user_id'] ?? 0), $category_id]);
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get community stats
$stmt = $pdo->prepare("SELECT COUNT(*) as total_posts FROM posts WHERE category_id = ?");
$stmt->execute([$category_id]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>r/<?php echo htmlspecialchars($category['name']); ?> - Reddify</title>
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
        
        .community-banner {
            height: 120px;
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            position: relative;
        }
        
        .community-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            font-weight: bold;
            color: #dc3545;
            border: 3px solid white;
            position: absolute;
            bottom: -30px;
            left: 2rem;
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

    <!-- Community Header -->
    <div class="community-banner"></div>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div style="padding-top: 2rem; padding-bottom: 1rem; margin-left: 2rem;">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h1 class="h3 fw-bold mb-1">r/<?php echo htmlspecialchars($category['name']); ?></h1>
                            <?php if ($category['description']): ?>
                                <p class="text-muted mb-2"><?php echo htmlspecialchars($category['description']); ?></p>
                            <?php endif; ?>
                            <small class="text-muted">
                                <?php echo formatNumber($stats['total_posts']); ?> posts • 
                                Created <?php echo date('M j, Y', strtotime($category['created_at'])); ?>
                            </small>
                        </div>
                        <div class="d-flex gap-2">
                            <?php if (isset($_SESSION['user_id'])): ?>
                                <a href="create_post.php" class="btn btn-danger">
                                    <i class="fas fa-plus me-1"></i>Create Post
                                </a>
                            <?php endif; ?>
                            <?php if ($is_category_owner): ?>
                                <div class="dropdown">
                                    <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                        <i class="fas fa-cog"></i>
                                    </button>
                                    <ul class="dropdown-menu">
                                        <li><a class="dropdown-item" href="edit_category.php?id=<?php echo $category_id; ?>">
                                            <i class="fas fa-edit me-2"></i>Edit Community
                                        </a></li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this community? This will delete all posts in it.')">
                                                <button type="submit" name="delete_category" class="dropdown-item text-danger">
                                                    <i class="fas fa-trash me-2"></i>Delete Community
                                                </button>
                                            </form>
                                        </li>
                                    </ul>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="container-fluid">
        <div class="row">
            <!-- Main Content -->
            <div class="col-lg-8 mx-auto">
                <!-- Create Post Button -->
                <?php if (isset($_SESSION['user_id'])): ?>
                <div class="card mb-3">
                    <div class="card-body">
                        <a href="create_post.php" class="d-flex align-items-center text-decoration-none">
                            <input type="text" class="form-control bg-light" placeholder="Create Post" readonly>
                            <i class="fas fa-image text-muted ms-3"></i>
                            <i class="fas fa-link text-muted ms-2"></i>
                        </a>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Sort Bar -->
                <div class="card mb-3">
                    <div class="card-body py-2">
                        <div class="btn-group" role="group">
                            <a href="category.php?id=<?php echo $category_id; ?>&sort=hot" 
                               class="btn <?php echo $sort === 'hot' ? 'btn-danger' : 'btn-outline-secondary'; ?> btn-sm">
                                <i class="fas fa-fire me-1"></i>Hot
                            </a>
                            <a href="category.php?id=<?php echo $category_id; ?>&sort=new" 
                               class="btn <?php echo $sort === 'new' ? 'btn-danger' : 'btn-outline-secondary'; ?> btn-sm">
                                <i class="fas fa-certificate me-1"></i>New
                            </a>
                            <a href="category.php?id=<?php echo $category_id; ?>&sort=top" 
                               class="btn <?php echo $sort === 'top' ? 'btn-danger' : 'btn-outline-secondary'; ?> btn-sm">
                                <i class="fas fa-arrow-up me-1"></i>Top
                            </a>
                            <a href="category.php?id=<?php echo $category_id; ?>&sort=rising" 
                               class="btn <?php echo $sort === 'rising' ? 'btn-danger' : 'btn-outline-secondary'; ?> btn-sm">
                                <i class="fas fa-chart-line me-1"></i>Rising
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Posts -->
                <?php if (empty($posts)): ?>
                    <div class="card">
                        <div class="card-body text-center py-5">
                            <i class="fas fa-file-alt text-muted mb-3" style="font-size: 3rem;"></i>
                            <h5>No posts in this community yet</h5>
                            <p class="text-muted mb-3">Be the first to share something!</p>
                            <?php if (isset($_SESSION['user_id'])): ?>
                                <a href="create_post.php" class="btn btn-danger">Create Post</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach ($posts as $post): ?>
                        <div class="card mb-3">
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
                                        <div class="post-meta mb-2">
                                            <span class="text-muted">Posted by</span>
                                            <a href="profile.php?user=<?php echo urlencode($post['username']); ?>" class="text-decoration-none fw-bold text-danger">
                                                u/<?php echo htmlspecialchars($post['username']); ?>
                                            </a>
                                            <span class="text-muted">• <?php echo timeAgo($post['created_at']); ?> ago</span>
                                        </div>
                                        
                                        <h5 class="card-title">
                                            <a href="post.php?id=<?php echo $post['id']; ?>" class="text-decoration-none text-dark">
                                                <?php echo htmlspecialchars($post['title']); ?>
                                            </a>
                                        </h5>
                                        
                                        <?php if ($post['file_path'] && strpos($post['file_type'], 'image/') === 0): ?>
                                            <img src="<?php echo htmlspecialchars($post['file_path']); ?>" 
                                                 class="img-fluid mb-3 rounded" style="max-height: 300px;" alt="Post image">
                                        <?php endif; ?>
                                        
                                        <?php if ($post['content']): ?>
                                            <p class="card-text">
                                                <?php 
                                                $preview = strip_tags($post['content']);
                                                echo htmlspecialchars(substr($preview, 0, 300));
                                                if (strlen($preview) > 300) echo '...';
                                                ?>
                                            </p>
                                        <?php endif; ?>
                                        
                                        <div class="d-flex align-items-center">
                                            <a href="post.php?id=<?php echo $post['id']; ?>" class="btn btn-sm btn-outline-secondary me-2">
                                                <i class="fas fa-comment me-1"></i><?php echo $post['comment_count']; ?> Comments
                                            </a>
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
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
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
    </script>
</body>
</html>
