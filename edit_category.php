<?php
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$category_id = $_GET['id'] ?? 0;
$error = '';
$success = '';

// Fetch category to edit
$stmt = $pdo->prepare("SELECT * FROM categories WHERE id = ? AND user_id = ?");
$stmt->execute([$category_id, $_SESSION['user_id']]);
$category = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$category) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    
    if (empty($name)) {
        $error = 'Community name is required';
    } elseif (strlen($name) < 3) {
        $error = 'Community name must be at least 3 characters long';
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
        $error = 'Community name can only contain letters, numbers, and underscores';
    } else {
        // Check if category name already exists (excluding current category)
        $stmt = $pdo->prepare("SELECT * FROM categories WHERE name = ? AND id != ?");
        $stmt->execute([$name, $category_id]);
        
        if ($stmt->fetch()) {
            $error = 'Community name already exists';
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE categories SET name = ?, description = ? WHERE id = ? AND user_id = ?");
                $stmt->execute([$name, $description, $category_id, $_SESSION['user_id']]);
                
                header("Location: category.php?id=$category_id");
                exit;
            } catch (Exception $e) {
                $error = 'Error updating community';
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Community - Reddify</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <!-- Header -->
    <nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom">
        <div class="container-fluid">
            <a class="navbar-brand d-flex align-items-center" href="index.php">
                <i class="fab fa-reddit-alien text-danger me-2" style="font-size: 2rem;"></i>
                <span class="fw-bold text-dark">reddify</span>
            </a>
            <div class="ms-auto">
                <a href="category.php?id=<?php echo $category_id; ?>" class="btn btn-outline-danger">Cancel</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-lg-6">
                <div class="card shadow-sm">
                    <div class="card-header bg-white">
                        <h4 class="mb-0">Edit Community</h4>
                    </div>
                    <div class="card-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger" role="alert">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <?php echo htmlspecialchars($error); ?>
                            </div>
                        <?php endif; ?>

                        <form method="POST">
                            <!-- Community Name -->
                            <div class="mb-4">
                                <label for="name" class="form-label fw-bold">Community name</label>
                                <div class="input-group">
                                    <span class="input-group-text">r/</span>
                                    <input type="text" class="form-control" id="name" name="name" 
                                           value="<?php echo htmlspecialchars($category['name']); ?>" 
                                           placeholder="community_name" required>
                                </div>
                                <div class="form-text">
                                    Only letters, numbers, and underscores allowed.
                                </div>
                            </div>

                            <!-- Description -->
                            <div class="mb-4">
                                <label for="description" class="form-label fw-bold">Description</label>
                                <textarea class="form-control" id="description" name="description" rows="4"
                                          placeholder="Tell people what this community is about"><?php echo htmlspecialchars($category['description']); ?></textarea>
                                <div class="form-text">
                                    This is how new members come to understand your community.
                                </div>
                            </div>

                            <!-- Form Actions -->
                            <div class="d-flex justify-content-end gap-2">
                                <a href="category.php?id=<?php echo $category_id; ?>" class="btn btn-outline-danger">Cancel</a>
                                <button type="submit" class="btn btn-danger">
                                    <i class="fas fa-save me-2"></i>Update Community
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
