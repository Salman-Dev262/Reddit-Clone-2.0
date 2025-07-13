<?php
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$success = '';
$error = '';

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
        // Check if category already exists
        $stmt = $pdo->prepare("SELECT * FROM categories WHERE name = ?");
        $stmt->execute([$name]);
        
        if ($stmt->fetch()) {
            $error = 'Community already exists';
        } else {
            $stmt = $pdo->prepare("INSERT INTO categories (name, description) VALUES (?, ?)");
            
            if ($stmt->execute([$name, $description])) {
                $category_id = $pdo->lastInsertId();
                header("Location: category.php?id=$category_id");
                exit;
            } else {
                $error = 'Error creating community';
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
    <title>Create Community - Reddit</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --reddit-orange: #FF4500;
            --reddit-blue: #0079D3;
            --reddit-bg: #DAE0E6;
            --reddit-card: #FFFFFF;
            --reddit-text: #1A1A1B;
            --reddit-text-meta: #787C7E;
            --reddit-border: #EDEFF1;
            --reddit-hover: #F6F7F8;
        }

        body {
            background-color: var(--reddit-bg);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            margin: 0;
            padding: 0;
            font-size: 14px;
        }

        .reddit-header {
            background-color: var(--reddit-card);
            border-bottom: 1px solid var(--reddit-border);
            height: 48px;
        }

        .header-content {
            display: flex;
            align-items: center;
            height: 48px;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        .reddit-logo {
            display: flex;
            align-items: center;
            text-decoration: none;
        }

        .reddit-logo i {
            color: var(--reddit-orange);
            font-size: 32px;
            margin-right: 8px;
        }

        .reddit-logo span {
            color: var(--reddit-text);
            font-size: 18px;
            font-weight: bold;
        }

        .main-container {
            max-width: 740px;
            margin: 20px auto;
            padding: 0 20px;
        }

        .create-community-header {
            background: var(--reddit-card);
            border: 1px solid var(--reddit-border);
            border-radius: 4px;
            padding: 16px;
            margin-bottom: 16px;
        }

        .create-community-title {
            font-size: 18px;
            font-weight: 500;
            color: var(--reddit-text);
            margin: 0;
        }

        .form-container {
            background: var(--reddit-card);
            border: 1px solid var(--reddit-border);
            border-radius: 4px;
            overflow: hidden;
        }

        .form-section {
            padding: 16px;
            border-bottom: 1px solid var(--reddit-border);
        }

        .form-section:last-child {
            border-bottom: none;
        }

        .form-label {
            font-size: 14px;
            font-weight: 500;
            color: var(--reddit-text);
            margin-bottom: 8px;
            display: block;
        }

        .form-control {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid var(--reddit-border);
            border-radius: 4px;
            font-size: 14px;
            background: var(--reddit-card);
            color: var(--reddit-text);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--reddit-blue);
            box-shadow: 0 0 0 1px var(--reddit-blue);
        }

        .form-textarea {
            min-height: 120px;
            resize: vertical;
            font-family: inherit;
        }

        .input-group {
            display: flex;
        }

        .input-group-text {
            background: var(--reddit-hover);
            border: 1px solid var(--reddit-border);
            border-right: none;
            padding: 8px 12px;
            font-size: 14px;
            color: var(--reddit-text);
            border-radius: 4px 0 0 4px;
        }

        .input-group .form-control {
            border-radius: 0 4px 4px 0;
        }

        .form-text {
            font-size: 12px;
            color: var(--reddit-text-meta);
            margin-top: 4px;
        }

        .form-actions {
            padding: 16px;
            background: var(--reddit-hover);
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }

        .btn {
            padding: 8px 16px;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
            border: 1px solid;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .btn-primary {
            background: var(--reddit-blue);
            border-color: var(--reddit-blue);
            color: white;
        }

        .btn-primary:hover {
            background: #0066CC;
            border-color: #0066CC;
            color: white;
        }

        .btn-secondary {
            background: var(--reddit-card);
            border-color: var(--reddit-border);
            color: var(--reddit-text);
        }

        .btn-secondary:hover {
            background: var(--reddit-hover);
            color: var(--reddit-text);
        }

        .error-alert {
            background: #FFE6E6;
            border: 1px solid #FF6B6B;
            border-radius: 4px;
            padding: 12px;
            margin-bottom: 16px;
            color: #D63031;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="reddit-header">
        <div class="header-content">
            <a href="index.php" class="reddit-logo">
                <i class="fab fa-reddit-alien"></i>
                <span>reddit</span>
            </a>
        </div>
    </header>

    <div class="main-container">
        <!-- Header -->
        <div class="create-community-header">
            <h1 class="create-community-title">Create a community</h1>
        </div>

        <?php if ($error): ?>
            <div class="error-alert"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-container">
                <!-- Community Name -->
                <div class="form-section">
                    <label for="name" class="form-label">Community name</label>
                    <div class="input-group">
                        <span class="input-group-text">r/</span>
                        <input type="text" class="form-control" id="name" name="name" 
                               value="<?php echo isset($name) ? htmlspecialchars($name) : ''; ?>" 
                               placeholder="community_name" required>
                    </div>
                    <div class="form-text">
                        Community names including capitalization cannot be changed. 
                        <br>Only letters, numbers, and underscores allowed.
                    </div>
                </div>

                <!-- Description -->
                <div class="form-section">
                    <label for="description" class="form-label">Description</label>
                    <textarea class="form-control form-textarea" id="description" name="description"
                              placeholder="Tell people what this community is about"><?php echo isset($description) ? htmlspecialchars($description) : ''; ?></textarea>
                    <div class="form-text">
                        This is how new members come to understand your community.
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="form-actions">
                    <a href="index.php" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-plus-circle"></i> Create Community
                    </button>
                </div>
            </div>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
