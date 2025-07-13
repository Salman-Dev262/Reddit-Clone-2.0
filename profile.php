<?php
require_once 'config/database.php';

$username = $_GET['user'] ?? '';
$tab = $_GET['tab'] ?? 'posts';

if (empty($username)) {
    header('Location: index.php');
    exit;
}

// Fetch user information
$stmt = $pdo->prepare("
    SELECT u.*, up.bio, up.profile_picture, up.join_date,
           COALESCE(SUM(p.votes), 0) as post_karma,
           COUNT(p.id) as post_count
    FROM users u 
    LEFT JOIN user_profiles up ON u.id = up.user_id
    LEFT JOIN posts p ON u.id = p.user_id
    WHERE u.username = ?
    GROUP BY u.id
");
$stmt->execute([$username]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header('Location: index.php');
    exit;
}

$is_own_profile = isset($_SESSION['user_id']) && $_SESSION['user_id'] == $user['id'];

// Handle post deletion
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_post']) && $is_own_profile) {
    $post_id = $_POST['post_id'];
    
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
        header("Location: profile.php?user=" . urlencode($username));
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = 'Error deleting post';
    }
}

// Fetch user's posts
if ($tab === 'posts') {
    $stmt = $pdo->prepare("
        SELECT p.*, c.name as category_name,
               f.file_path, f.file_type,
               (SELECT COUNT(*) FROM comments WHERE post_id = p.id) as comment_count,
               (SELECT vote_type FROM votes WHERE post_id = p.id AND user_id = ?) as user_vote
        FROM posts p 
        JOIN categories c ON p.category_id = c.id 
        LEFT JOIN files f ON p.id = f.post_id
        WHERE p.user_id = ?
        ORDER BY p.created_at DESC
        LIMIT 25
    ");
    $stmt->execute([($_SESSION['user_id'] ?? 0), $user['id']]);
    $user_posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch user's comments
if ($tab === 'comments') {
    $stmt = $pdo->prepare("
        SELECT c.*, p.title as post_title, p.id as post_id, cat.name as category_name,
               COALESCE(SUM(CASE WHEN cv.vote_type = 'up' THEN 1 WHEN cv.vote_type = 'down' THEN -1 ELSE 0 END), 0) as votes
        FROM comments c 
        JOIN posts p ON c.post_id = p.id
        JOIN categories cat ON p.category_id = cat.id
        LEFT JOIN comment_votes cv ON c.id = cv.comment_id
        WHERE c.user_id = ?
        GROUP BY c.id
        ORDER BY c.created_at DESC
        LIMIT 25
    ");
    $stmt->execute([$user['id']]);
    $user_comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch saved posts (only for own profile)
if ($tab === 'saved' && $is_own_profile) {
    $stmt = $pdo->prepare("
        SELECT p.*, c.name as category_name, u.username,
               f.file_path, f.file_type,
               (SELECT COUNT(*) FROM comments WHERE post_id = p.id) as comment_count,
               (SELECT vote_type FROM votes WHERE post_id = p.id AND user_id = ?) as user_vote
        FROM saved_posts sp
        JOIN posts p ON sp.post_id = p.id
        JOIN categories c ON p.category_id = c.id
        JOIN users u ON p.user_id = u.id
        LEFT JOIN files f ON p.id = f.post_id
        WHERE sp.user_id = ?
        ORDER BY sp.saved_at DESC
        LIMIT 25
    ");
    $stmt->execute([($_SESSION['user_id'] ?? 0), $user['id']]);
    $saved_posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>u/<?php echo htmlspecialchars($username); ?> - Reddify</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .post-card {
            transition: box-shadow 0.2s;
        }
        
        .post-card:hover {
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
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
        
        .profile-banner {
            height: 120px;
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            position: relative;
        }
        
        
        
        .sidebar-sticky {
            position: sticky;
            top: 1rem;
        }

        .profile-nav .nav-link {
            color: #6c757d;
            border: 1px solid transparent;
            border-radius: 0.375rem;
            padding: 0.75rem 1rem;
            text-decoration: none;
            transition: all 0.2s;
        }

        .profile-nav .nav-link:hover {
            color: #dc3545;
            background-color: #f8f9fa;
        }

        .profile-nav .nav-link.active {
            color: white;
            background-color: #dc3545;
            border-color: #dc3545;
        }

        .profile-nav .nav-link.active:hover {
            color: white;
            background-color: #c82333;
            border-color: #c82333;
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

    <div class="container-fluid">
        <div class="row">
            <!-- Main Content -->
            <div class="col-lg-8 mx-auto">
                <!-- Profile Header -->
                <div class="card mb-4 mt-3">
                    <div class="profile-banner"></div>
                    <div class="card-body" style="padding-top: 2rem;">
                        <div class="row">
                            <div class="col-md-8">
                                <h2 class="fw-bold mb-2">u/<?php echo htmlspecialchars($username); ?></h2>
                                <?php if ($user['bio']): ?>
                                    <p class="text-muted mb-3"><?php echo htmlspecialchars($user['bio']); ?></p>
                                <?php endif; ?>
                                <div class="d-flex gap-4 mb-3">
                                    <div>
                                        <strong class="d-block"><?php echo formatNumber($user['post_karma']); ?></strong>
                                        <small class="text-muted">Post Karma</small>
                                    </div>
                                    <div>
                                        <strong class="d-block"><?php echo formatNumber($user['post_count']); ?></strong>
                                        <small class="text-muted">Posts</small>
                                    </div>
                                    <div>
                                        <strong class="d-block"><?php echo date('M j, Y', strtotime($user['join_date'] ?? $user['created_at'])); ?></strong>
                                        <small class="text-muted">Cake Day</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Profile Navigation -->
                <div class="card mb-4">
                    <div class="card-body p-0">
                        <nav class="nav nav-fill profile-nav">
                            <a class="nav-link <?php echo $tab === 'posts' ? 'active' : ''; ?>" 
                               href="profile.php?user=<?php echo urlencode($username); ?>&tab=posts">
                                <i class="fas fa-file-alt me-2"></i>Posts
                            </a>
                            <a class="nav-link <?php echo $tab === 'comments' ? 'active' : ''; ?>" 
                               href="profile.php?user=<?php echo urlencode($username); ?>&tab=comments">
                                <i class="fas fa-comments me-2"></i>Comments
                            </a>
                            <?php if ($is_own_profile): ?>
                                <a class="nav-link <?php echo $tab === 'saved' ? 'active' : ''; ?>" 
                                   href="profile.php?user=<?php echo urlencode($username); ?>&tab=saved">
                                    <i class="fas fa-bookmark me-2"></i>Saved
                                </a>
                            <?php endif; ?>
                        </nav>
                    </div>
                </div>

                <!-- Content -->
                <?php if ($tab === 'posts'): ?>
                    <?php if (empty($user_posts)): ?>
                        <div class="card">
                            <div class="card-body text-center py-5">
                                <i class="fas fa-file-alt text-muted mb-3" style="font-size: 3rem;"></i>
                                <h5>No posts yet</h5>
                                <p class="text-muted">u/<?php echo htmlspecialchars($username); ?> hasn't posted anything</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($user_posts as $post): ?>
                            <div class="card mb-3 post-card">
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
                                                    <a href="category.php?id=<?php echo $post['category_id']; ?>" class="text-decoration-none fw-bold text-danger">
                                                        r/<?php echo htmlspecialchars($post['category_name']); ?>
                                                    </a>
                                                    <span class="text-muted">• <?php echo timeAgo($post['created_at']); ?> ago</span>
                                                </div>
                                                <?php if ($is_own_profile): ?>
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
                                                                    <input type="hidden" name="post_id" value="<?php echo $post['id']; ?>">
                                                                    <button type="submit" name="delete_post" class="dropdown-item text-danger">
                                                                        <i class="fas fa-trash me-2"></i>Delete
                                                                    </button>
                                                                </form>
                                                            </li>
                                                        </ul>
                                                    </div>
                                                <?php endif; ?>
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
                                            
                                            <div class="post-actions d-flex align-items-center">
                                                <a href="post.php?id=<?php echo $post['id']; ?>" class="btn btn-sm btn-outline-secondary me-2">
                                                    <i class="fas fa-comment me-1"></i><?php echo $post['comment_count']; ?> Comments
                                                </a>
                                                <button class="btn btn-sm btn-outline-secondary me-2">
                                                    <i class="fas fa-share me-1"></i>Share
                                                </button>
                                                <button class="btn btn-sm btn-outline-secondary" onclick="savePost(<?php echo $post['id']; ?>)">
                                                    <i class="far fa-bookmark me-1"></i>Save
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                <?php elseif ($tab === 'comments'): ?>
                    <?php if (empty($user_comments)): ?>
                        <div class="card">
                            <div class="card-body text-center py-5">
                                <i class="fas fa-comments text-muted mb-3" style="font-size: 3rem;"></i>
                                <h5>No comments yet</h5>
                                <p class="text-muted">u/<?php echo htmlspecialchars($username); ?> hasn't commented on anything</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($user_comments as $comment): ?>
                            <div class="card mb-3">
                                <div class="card-body">
                                    <div class="comment-meta mb-2">
                                        <a href="category.php?id=<?php echo $comment['category_id']; ?>" class="text-decoration-none fw-bold text-danger">
                                            r/<?php echo htmlspecialchars($comment['category_name']); ?>
                                        </a>
                                        <span class="text-muted">• </span>
                                        <a href="post.php?id=<?php echo $comment['post_id']; ?>" class="text-decoration-none">
                                            <?php echo htmlspecialchars(substr($comment['post_title'], 0, 50)); ?><?php echo strlen($comment['post_title']) > 50 ? '...' : ''; ?>
                                        </a>
                                        <span class="text-muted">• <?php echo timeAgo($comment['created_at']); ?> ago</span>
                                    </div>
                                    <div class="comment-content mb-2">
                                        <?php echo htmlspecialchars($comment['content']); ?>
                                    </div>
                                    <div class="d-flex align-items-center">
                                        <span class="badge bg-secondary me-2">
                                            <i class="fas fa-arrow-up"></i> <?php echo $comment['votes']; ?>
                                        </span>
                                        <a href="post.php?id=<?php echo $comment['post_id']; ?>" class="btn btn-sm btn-outline-secondary">
                                            <i class="fas fa-link me-1"></i>Context
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                <?php elseif ($tab === 'saved' && $is_own_profile): ?>
                    <?php if (empty($saved_posts)): ?>
                        <div class="card">
                            <div class="card-body text-center py-5">
                                <i class="fas fa-bookmark text-muted mb-3" style="font-size: 3rem;"></i>
                                <h5>No saved posts</h5>
                                <p class="text-muted">You haven't saved any posts yet</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($saved_posts as $post): ?>
                            <div class="card mb-3 post-card">
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
                                                <a href="category.php?id=<?php echo $post['category_id']; ?>" class="text-decoration-none fw-bold text-danger">
                                                    r/<?php echo htmlspecialchars($post['category_name']); ?>
                                                </a>
                                                <span class="text-muted">• Posted by</span>
                                                <a href="profile.php?user=<?php echo urlencode($post['username']); ?>" class="text-decoration-none">
                                                    u/<?php echo htmlspecialchars($post['username']); ?>
                                                </a>
                                                <span class="text-muted">• <?php echo timeAgo($post['created_at']); ?> ago</span>
                                            </div>
                                            
                                            <h5 class="card-title">
                                                <a href="post.php?id=<?php echo $post['id']; ?>" class="text-decoration-none text-dark">
                                                    <?php echo htmlspecialchars($post['title']); ?>
                                                </a>
                                            </h5>
                                            
                                            <?php if ($post['content']): ?>
                                                <p class="card-text">
                                                    <?php 
                                                    $preview = strip_tags($post['content']);
                                                    echo htmlspecialchars(substr($preview, 0, 300));
                                                    if (strlen($preview) > 300) echo '...';
                                                    ?>
                                                </p>
                                            <?php endif; ?>
                                            
                                            <div class="post-actions d-flex align-items-center">
                                                <a href="post.php?id=<?php echo $post['id']; ?>" class="btn btn-sm btn-outline-secondary me-2">
                                                    <i class="fas fa-comment me-1"></i><?php echo $post['comment_count']; ?> Comments
                                                </a>
                                                <button class="btn btn-sm btn-outline-secondary" onclick="savePost(<?php echo $post['id']; ?>)">
                                                    <i class="fas fa-bookmark me-1"></i>Unsave
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
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
