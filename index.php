<?php
require_once 'config/database.php';

// Get parameters
$sort = $_GET['sort'] ?? 'hot';
$search = $_GET['search'] ?? '';
$filter = $_GET['filter'] ?? 'all'; // New filter parameter

// Build query based on sort and search
$whereClause = '';
$params = [];

if ($search) {
    $whereClause = "WHERE (p.title LIKE ? OR p.content LIKE ?)";
    $params = ["%$search%", "%$search%"];
}

$orderClause = match($sort) {
    'new' => 'ORDER BY p.created_at DESC',
    'top' => 'ORDER BY p.votes DESC, p.created_at DESC',
    'rising' => 'ORDER BY (p.votes + 1) / POWER(TIMESTAMPDIFF(HOUR, p.created_at, NOW()) + 2, 1.8) DESC',
    default => 'ORDER BY (p.votes + 1) / POWER(TIMESTAMPDIFF(HOUR, p.created_at, NOW()) + 2, 1.8) DESC'
};

// Fetch posts with all related data
$stmt = $pdo->prepare("
    SELECT p.*, u.username, c.name as category_name,
           f.file_path, f.file_type, f.file_name,
           (SELECT COUNT(*) FROM comments WHERE post_id = p.id) as comment_count,
           (SELECT COUNT(*) FROM saved_posts WHERE post_id = p.id AND user_id = ?) as is_saved,
           (SELECT vote_type FROM votes WHERE post_id = p.id AND user_id = ?) as user_vote
    FROM posts p 
    JOIN users u ON p.user_id = u.id 
    JOIN categories c ON p.category_id = c.id 
    LEFT JOIN files f ON p.id = f.post_id
    $whereClause
    $orderClause
    LIMIT 25
");
$params_with_user = array_merge([($_SESSION['user_id'] ?? 0), ($_SESSION['user_id'] ?? 0)], $params);
$stmt->execute($params_with_user);
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch popular categories
$stmt = $pdo->query("
    SELECT c.*, COUNT(p.id) as post_count 
    FROM categories c 
    LEFT JOIN posts p ON c.id = p.category_id 
    GROUP BY c.id 
    ORDER BY post_count DESC 
    LIMIT 10
");
$popular_categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch trending posts for sidebar
$stmt = $pdo->query("
    SELECT p.title, p.id, c.name as category_name, p.votes
    FROM posts p 
    JOIN categories c ON p.category_id = c.id 
    WHERE p.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ORDER BY p.votes DESC 
    LIMIT 5
");
$trending_posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get site statistics
$stmt = $pdo->query("SELECT COUNT(*) as total_posts FROM posts");
$total_posts = $stmt->fetch(PDO::FETCH_ASSOC)['total_posts'];

$stmt = $pdo->query("SELECT COUNT(*) as total_users FROM users");
$total_users = $stmt->fetch(PDO::FETCH_ASSOC)['total_users'];

$stmt = $pdo->query("SELECT COUNT(*) as total_communities FROM categories");
$total_communities = $stmt->fetch(PDO::FETCH_ASSOC)['total_communities'];

$stmt = $pdo->query("SELECT COUNT(*) as total_comments FROM comments");
$total_comments = $stmt->fetch(PDO::FETCH_ASSOC)['total_comments'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reddify - The Front Page of the Internet</title>
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
            color: #dc3545 !important; /* Red instead of orange */
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
        
        .sidebar-sticky {
            position: sticky;
            top: 1rem;
        }
        
        .post-image {
            max-width: 100%;
            height: auto;
            border-radius: 8px;
            margin: 1rem 0;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .sidebar-nav-item {
            display: flex;
            align-items: center;
            padding: 0.75rem 1rem;
            color: #6c757d;
            text-decoration: none;
            border-radius: 0.375rem;
            margin-bottom: 0.25rem;
            transition: all 0.2s;
        }

        .sidebar-nav-item:hover {
            background-color: #f8f9fa;
            color: #dc3545;
            text-decoration: none;
        }

        .sidebar-nav-item.active {
            background-color: #dc3545;
            color: white;
        }

        .sidebar-nav-item.active:hover {
            background-color: #c82333;
            color: white;
        }

        .sidebar-nav-item i {
            width: 20px;
            margin-right: 0.5rem;
        }

        .post-meta a {
            color: #dc3545 !important;
            text-decoration: none;
            font-weight: bold;
        }

        .post-meta a:hover {
            text-decoration: underline;
        }

        .about-reddify {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
            border-radius: 0.5rem;
            padding: 1.5rem;
            margin-bottom: 1rem;
        }

        .stat-item {
            text-align: center;
            padding: 0.5rem;
        }

        .stat-number {
            font-size: 1.5rem;
            font-weight: bold;
            color: #dc3545;
        }

        .news-item {
            border-left: 3px solid #dc3545;
            padding-left: 1rem;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body class="bg-light">
    <!-- Header -->
    <nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom sticky-top">
        <div class="container-fluid">
            <!-- Logo -->
            <a class="navbar-brand d-flex align-items-center" href="index.php">
                <i class="fab fa-reddit-alien text-danger me-2" style="font-size: 2rem;"></i>
                <span class="fw-bold text-dark">reddify</span>
            </a>

            <!-- Search Bar -->
            <div class="flex-grow-1 mx-3">
                <form method="GET" action="index.php">
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0">
                            <i class="fas fa-search text-muted"></i>
                        </span>
                        <input type="text" name="search" class="form-control border-start-0 bg-light" 
                               placeholder="Search Reddify" value="<?php echo htmlspecialchars($search); ?>">
                        <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort); ?>">
                        <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
                    </div>
                </form>
            </div>

            <!-- User Menu -->
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
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 d-none d-md-block">
                <div class="sidebar-sticky pt-3">
                    <!-- Navigation -->
                    <div class="card mb-3">
                        <div class="card-body p-2">
                            <a href="index.php?filter=all&sort=<?php echo $sort; ?><?php echo $search ? '&search='.urlencode($search) : ''; ?>" 
                               class="sidebar-nav-item <?php echo $filter === 'all' ? 'active' : ''; ?>">
                                <i class="fas fa-home"></i>Home
                            </a>
                            <a href="index.php?filter=popular&sort=top<?php echo $search ? '&search='.urlencode($search) : ''; ?>" 
                               class="sidebar-nav-item <?php echo $filter === 'popular' ? 'active' : ''; ?>">
                                <i class="fas fa-fire"></i>Popular
                            </a>
                            <a href="index.php?filter=new&sort=new<?php echo $search ? '&search='.urlencode($search) : ''; ?>" 
                               class="sidebar-nav-item <?php echo $filter === 'new' ? 'active' : ''; ?>">
                                <i class="fas fa-certificate"></i>New
                            </a>
                            <a href="index.php?filter=rising&sort=rising<?php echo $search ? '&search='.urlencode($search) : ''; ?>" 
                               class="sidebar-nav-item <?php echo $filter === 'rising' ? 'active' : ''; ?>">
                                <i class="fas fa-chart-line"></i>Rising
                            </a>
                            <?php if (isset($_SESSION['user_id'])): ?>
                                <hr class="my-2">
                                <a href="create_category.php" class="sidebar-nav-item">
                                    <i class="fas fa-plus"></i>Create Community
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Popular Communities -->
                    <div class="card mb-3">
                        <div class="card-header bg-white">
                            <h6 class="mb-0">Popular Communities</h6>
                        </div>
                        <div class="list-group list-group-flush">
                            <?php foreach ($popular_categories as $index => $category): ?>
                                <a href="category.php?id=<?php echo $category['id']; ?>" class="list-group-item list-group-item-action d-flex align-items-center">
                                    <div>
                                        <div class="fw-bold text-danger">r/<?php echo htmlspecialchars($category['name']); ?></div>
                                        <small class="text-muted"><?php echo formatNumber($category['post_count']); ?> posts</small>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Content -->
            <div class="col-md-6 col-lg-7">
                <!-- Sort Options -->
                <div class="d-flex justify-content-between align-items-center mb-3 pt-3">
                    <div class="btn-group" role="group">
                        <a href="index.php?sort=hot&filter=<?php echo $filter; ?><?php echo $search ? '&search='.urlencode($search) : ''; ?>" 
                           class="btn <?php echo $sort === 'hot' ? 'btn-danger' : 'btn-outline-secondary'; ?>">
                            <i class="fas fa-fire me-1"></i>Hot
                        </a>
                        <a href="index.php?sort=new&filter=<?php echo $filter; ?><?php echo $search ? '&search='.urlencode($search) : ''; ?>" 
                           class="btn <?php echo $sort === 'new' ? 'btn-danger' : 'btn-outline-secondary'; ?>">
                            <i class="fas fa-certificate me-1"></i>New
                        </a>
                        <a href="index.php?sort=top&filter=<?php echo $filter; ?><?php echo $search ? '&search='.urlencode($search) : ''; ?>" 
                           class="btn <?php echo $sort === 'top' ? 'btn-danger' : 'btn-outline-secondary'; ?>">
                            <i class="fas fa-arrow-up me-1"></i>Top
                        </a>
                        <a href="index.php?sort=rising&filter=<?php echo $filter; ?><?php echo $search ? '&search='.urlencode($search) : ''; ?>" 
                           class="btn <?php echo $sort === 'rising' ? 'btn-danger' : 'btn-outline-secondary'; ?>">
                            <i class="fas fa-chart-line me-1"></i>Rising
                        </a>
                    </div>
                </div>

                <!-- Posts -->
                <div class="posts-container">
                    <?php if (empty($posts)): ?>
                        <div class="card mb-3">
                            <div class="card-body text-center py-5">
                                <h5>No posts found</h5>
                                <p class="text-muted">
                                    <?php if ($search): ?>
                                        No posts match your search for "<?php echo htmlspecialchars($search); ?>"
                                    <?php else: ?>
                                        Be the first to create a post!
                                    <?php endif; ?>
                                </p>
                                <?php if (isset($_SESSION['user_id'])): ?>
                                    <a href="create_post.php" class="btn btn-danger">Create Post</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($posts as $post): ?>
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
                                                <a href="category.php?id=<?php echo $post['category_id']; ?>" class="text-decoration-none fw-bold">
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

                                            <?php if ($post['file_path']): ?>
                                                <?php if (strpos($post['file_type'], 'image/') === 0): ?>
                                                    <div class="text-center">
                                                        <img src="<?php echo htmlspecialchars($post['file_path']); ?>" 
                                                             class="post-image" alt="Post image"
                                                             style="max-height: 500px; cursor: pointer;"
                                                             onclick="openImageModal('<?php echo htmlspecialchars($post['file_path']); ?>')">
                                                    </div>
                                                <?php else: ?>
                                                    <div class="file-attachment mb-3">
                                                        <div class="card bg-light">
                                                            <div class="card-body py-2">
                                                                <i class="fas fa-file me-2"></i>
                                                                <a href="<?php echo htmlspecialchars($post['file_path']); ?>" target="_blank" class="text-decoration-none">
                                                                    <?php echo htmlspecialchars($post['file_name']); ?>
                                                                </a>
                                                            </div>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            
                                            <div class="post-actions d-flex align-items-center">
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

            <!-- Right Sidebar -->
            <div class="col-md-3 col-lg-3 d-none d-lg-block">
                <div class="pt-3">
                    <!-- About Reddify -->
                    <div class="about-reddify">
                        <div class="d-flex align-items-center mb-3">
                            <i class="fab fa-reddit-alien me-2" style="font-size: 2rem;"></i>
                            <h5 class="mb-0">About Reddify</h5>
                        </div>
                        <p class="mb-3">Reddify is a network of communities where people can dive into their interests, hobbies and passions. There's a community for whatever you're interested in on Reddify.</p>
                        
                        <div class="row text-center mb-3">
                            <div class="col-6">
                                <div class="stat-item">
                                    <div class="stat-number text-white"><?php echo formatNumber($total_posts); ?></div>
                                    <small>Posts</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="stat-item">
                                    <div class="stat-number text-white"><?php echo formatNumber($total_users); ?></div>
                                    <small>Redditors</small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row text-center">
                            <div class="col-6">
                                <div class="stat-item">
                                    <div class="stat-number text-white"><?php echo formatNumber($total_communities); ?></div>
                                    <small>Communities</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="stat-item">
                                    <div class="stat-number text-white"><?php echo formatNumber($total_comments); ?></div>
                                    <small>Comments</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Create Post Card -->
                    <?php if (isset($_SESSION['user_id'])): ?>
                    <div class="card mb-3">
                        <div class="card-body text-center">
                            <h6 class="card-title">Create a post</h6>
                            <a href="create_post.php" class="d-flex align-items-center text-decoration-none">
                                <input type="text" class="form-control bg-light" placeholder="Create Post" readonly>
                                <i class="fas fa-image text-muted ms-3"></i>
                                <i class="fas fa-link text-muted ms-2"></i>
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Reddify News -->
                    <div class="card mb-3">
                        <div class="card-header bg-white">
                            <h6 class="mb-0"><i class="fas fa-newspaper me-2 text-danger"></i>Reddify News</h6>
                        </div>
                        <div class="card-body">
                            <div class="news-item">
                                <h6 class="fw-bold mb-1">New Community Features</h6>
                                <small class="text-muted">Enhanced moderation tools and community customization options now available.</small>
                                <div><small class="text-muted">2 days ago</small></div>
                            </div>
                            <div class="news-item">
                                <h6 class="fw-bold mb-1">Mobile App Update</h6>
                                <small class="text-muted">Improved performance and new dark mode features in the latest mobile update.</small>
                                <div><small class="text-muted">1 week ago</small></div>
                            </div>
                            <div class="news-item">
                                <h6 class="fw-bold mb-1">Community Guidelines Update</h6>
                                <small class="text-muted">Updated community guidelines to ensure a better experience for all users.</small>
                                <div><small class="text-muted">2 weeks ago</small></div>
                            </div>
                        </div>
                    </div>

                    <!-- Trending -->
                    <?php if (!empty($trending_posts)): ?>
                    <div class="card mb-3">
                        <div class="card-header bg-white">
                            <h6 class="mb-0">Trending Today</h6>
                        </div>
                        <div class="card-body">
                            <?php foreach ($trending_posts as $trending): ?>
                                <div class="trending-item mb-2">
                                    <small class="text-muted">Trending in <span class="text-danger">r/<?php echo htmlspecialchars($trending['category_name']); ?></span></small>
                                    <div class="fw-bold">
                                        <a href="post.php?id=<?php echo $trending['id']; ?>" class="text-decoration-none text-dark">
                                            <?php echo htmlspecialchars(substr($trending['title'], 0, 50)); ?><?php echo strlen($trending['title']) > 50 ? '...' : ''; ?>
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Reddify Premium Ad -->
                    <div class="card">
                        <div class="card-body text-center">
                            <i class="fas fa-crown text-warning mb-2" style="font-size: 2rem;"></i>
                            <h6 class="card-title">Reddify Premium</h6>
                            <p class="card-text small">The best Reddify experience, with monthly Coins and exclusive features</p>
                            <button class="btn btn-warning btn-sm">Try Premium</button>
                        </div>
                    </div>
                </div>
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

        function openImageModal(imageSrc) {
            document.getElementById('modalImage').src = imageSrc;
            new bootstrap.Modal(document.getElementById('imageModal')).show();
        }
    </script>
</body>
</html>
