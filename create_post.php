<?php
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$success = '';
$error = '';

// Fetch categories
$stmt = $pdo->query("SELECT * FROM categories ORDER BY name");
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch available tags
$stmt = $pdo->query("SELECT * FROM tags ORDER BY name");
$tags = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $title = trim($_POST['title']);
    $content = trim($_POST['content']);
    $category_id = $_POST['category_id'];
    $selected_tags = $_POST['tags'] ?? [];
    
    if (empty($title) || empty($category_id)) {
        $error = 'Title and community are required';
    } else {
        try {
            $pdo->beginTransaction();
            
            // Insert post
            $stmt = $pdo->prepare("INSERT INTO posts (user_id, category_id, title, content) VALUES (?, ?, ?, ?)");
            $stmt->execute([$_SESSION['user_id'], $category_id, $title, $content]);
            $post_id = $pdo->lastInsertId();
            
            // Handle multiple file uploads
            if (isset($_FILES['post_files']) && !empty($_FILES['post_files']['name'][0])) {
                $upload_dir = 'uploads/post_' . $post_id . '/';
                
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $file_count = count($_FILES['post_files']['name']);
                for ($i = 0; $i < $file_count; $i++) {
                    if ($_FILES['post_files']['error'][$i] === UPLOAD_ERR_OK) {
                        $file_name = $_FILES['post_files']['name'][$i];
                        $file_type = $_FILES['post_files']['type'][$i];
                        $file_extension = pathinfo($file_name, PATHINFO_EXTENSION);
                        $new_file_name = uniqid() . '.' . $file_extension;
                        $file_path = $upload_dir . $new_file_name;
                        
                        if (move_uploaded_file($_FILES['post_files']['tmp_name'][$i], $file_path)) {
                            $stmt = $pdo->prepare("INSERT INTO files (post_id, file_name, file_path, file_type) VALUES (?, ?, ?, ?)");
                            $stmt->execute([$post_id, $file_name, $file_path, $file_type]);
                        }
                    }
                }
            }
            
            // Handle tags
            foreach ($selected_tags as $tag_id) {
                $stmt = $pdo->prepare("INSERT INTO post_tags (post_id, tag_id) VALUES (?, ?)");
                $stmt->execute([$post_id, $tag_id]);
            }
            
            $pdo->commit();
            header("Location: post.php?id=$post_id");
            exit;
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Error creating post: ' . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Post - Reddify</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.12/cropper.min.css" rel="stylesheet">
    <style>
        .file-upload-area {
            border: 2px dashed #dee2e6;
            border-radius: 8px;
            padding: 3rem;
            text-align: center;
            background: #f8f9fa;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .file-upload-area:hover {
            border-color: #dc3545;
            background: #f8d7da;
        }
        
        .file-upload-area.dragover {
            border-color: #dc3545;
            background: #f8d7da;
        }
        
        .preview-item {
            position: relative;
            display: inline-block;
            margin: 0.5rem;
        }
        
        .preview-image {
            max-width: 200px;
            max-height: 150px;
            border-radius: 8px;
        }
        
        .preview-file {
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #dee2e6;
            max-width: 200px;
        }
        
        .remove-file {
            position: absolute;
            top: -8px;
            right: -8px;
            background: #dc3545;
            color: white;
            border: none;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            font-size: 12px;
            cursor: pointer;
        }
    </style>
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
                <a href="index.php" class="btn btn-outline-danger">Cancel</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card shadow-sm">
                    <div class="card-header bg-white">
                        <h4 class="mb-0">Create a post</h4>
                    </div>
                    <div class="card-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger" role="alert">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <?php echo htmlspecialchars($error); ?>
                            </div>
                        <?php endif; ?>

                        <form method="POST" enctype="multipart/form-data" id="postForm">
                            <!-- Community Selection -->
                            <div class="mb-4">
                                <label for="category_id" class="form-label fw-bold">Choose a community</label>
                                <select class="form-select form-select-lg" id="category_id" name="category_id" required>
                                    <option value="">Choose a community</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?php echo $category['id']; ?>">
                                            r/<?php echo htmlspecialchars($category['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Title -->
                            <div class="mb-4">
                                <label for="title" class="form-label fw-bold">Title</label>
                                <input type="text" class="form-control form-control-lg" id="title" name="title" 
                                       placeholder="An interesting title" maxlength="300" required>
                                <div class="form-text">
                                    <span id="titleCount">0</span>/300 characters
                                </div>
                            </div>

                            <!-- Content -->
                            <div class="mb-4">
                                <label for="content" class="form-label fw-bold">Text (optional)</label>
                                <textarea class="form-control" id="content" name="content" rows="8"
                                          placeholder="What are your thoughts?"></textarea>
                            </div>

                            <!-- File Upload -->
                            <div class="mb-4">
                                <label class="form-label fw-bold">Files & Media</label>
                                <div class="file-upload-area" id="fileUploadArea">
                                    <div class="mb-3">
                                        <i class="fas fa-cloud-upload-alt text-muted" style="font-size: 3rem;"></i>
                                    </div>
                                    <h5>Drag and drop files here</h5>
                                    <p class="text-muted">or click to upload</p>
                                    <input type="file" id="post_files" name="post_files[]" multiple style="display: none;">
                                </div>
                                <div id="filePreview" class="mt-3"></div>
                            </div>

                            <!-- Tags -->
                            <?php if (!empty($tags)): ?>
                            <div class="mb-4">
                                <label class="form-label fw-bold">Tags (optional)</label>
                                <div class="row">
                                    <?php foreach ($tags as $tag): ?>
                                        <div class="col-md-4 mb-2">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" 
                                                       id="tag_<?php echo $tag['id']; ?>" 
                                                       name="tags[]" value="<?php echo $tag['id']; ?>">
                                                <label class="form-check-label" for="tag_<?php echo $tag['id']; ?>">
                                                    <?php echo htmlspecialchars($tag['name']); ?>
                                                </label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- Form Actions -->
                            <div class="d-flex justify-content-end gap-2">
                                <a href="index.php" class="btn btn-outline-danger">Cancel</a>
                                <button type="submit" class="btn btn-danger btn-lg">
                                    <i class="fas fa-paper-plane me-2"></i>Post
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Image editing modal -->
    <div class="modal fade" id="imageEditModal" tabindex="-1" aria-labelledby="imageEditModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="imageEditModalLabel">Edit Image</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-8">
                            <img id="imageToEdit" src="/placeholder.svg" alt="Image to Edit" style="max-width: 100%;">
                        </div>
                        <div class="col-md-4">
                            <div id="imagePreviewCanvas" style="width: 100%; height: 200px; overflow: hidden;"></div>
                            <div class="mt-3">
                                <label class="form-label">Zoom:</label>
                                <input type="range" class="form-range" id="zoomRange" min="0" max="1" step="0.01" value="0">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="applyChangesBtn">Apply Changes</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.12/cropper.min.js"></script>
    <script>
        let selectedFiles = [];
        let cropper;
        let currentFileIndex = null;

        // Title character counter
        document.getElementById('title').addEventListener('input', function() {
            document.getElementById('titleCount').textContent = this.value.length;
        });

        // File upload handling
        const fileUploadArea = document.getElementById('fileUploadArea');
        const fileInput = document.getElementById('post_files');
        const filePreview = document.getElementById('filePreview');
        const imageEditModal = document.getElementById('imageEditModal');
        const imageToEdit = document.getElementById('imageToEdit');
        const imagePreviewCanvas = document.getElementById('imagePreviewCanvas');
        const zoomRange = document.getElementById('zoomRange');
        const applyChangesBtn = document.getElementById('applyChangesBtn');

        fileUploadArea.addEventListener('click', () => fileInput.click());

        fileUploadArea.addEventListener('dragover', (e) => {
            e.preventDefault();
            fileUploadArea.classList.add('dragover');
        });

        fileUploadArea.addEventListener('dragleave', () => {
            fileUploadArea.classList.remove('dragover');
        });

        fileUploadArea.addEventListener('drop', (e) => {
            e.preventDefault();
            fileUploadArea.classList.remove('dragover');
            
            const files = Array.from(e.dataTransfer.files);
            handleFiles(files);
        });

        fileInput.addEventListener('change', function() {
            const files = Array.from(this.files);
            handleFiles(files);
        });

        function handleFiles(files) {
            files.forEach(file => {
                selectedFiles.push(file);
                createPreview(file, selectedFiles.length - 1);
            });
            updateFileInput();
        }

        function createPreview(file, index) {
            const previewItem = document.createElement('div');
            previewItem.className = 'preview-item';
            
            if (file.type.startsWith('image/')) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    previewItem.innerHTML = `
                        <img src="${e.target.result}" class="preview-image" alt="Preview">
                        <div class="d-flex justify-content-between align-items-center mt-3">
                            <small class="text-muted">${file.name} (${(file.size / 1024 / 1024).toFixed(2)} MB)</small>
                            <div>
                                <button type="button" class="btn btn-outline-primary btn-sm me-2" onclick="editNewImage(${index}, '${e.target.result}')">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <button type="button" class="btn btn-outline-danger btn-sm" onclick="removeFile(${index})">
                                    <i class="fas fa-times"></i> Remove
                                </button>
                            </div>
                        </div>
                    `;
                };
                reader.readAsDataURL(file);
            } else {
                previewItem.innerHTML = `
                    <div class="preview-file">
                        <i class="fas fa-file me-2"></i>
                        <div class="small">${file.name}</div>
                        <div class="text-muted small">${(file.size / 1024 / 1024).toFixed(2)} MB</div>
                    </div>
                    <button type="button" class="remove-file" onclick="removeFile(${index})">
                        <i class="fas fa-times"></i>
                    </button>
                `;
            }
            
            filePreview.appendChild(previewItem);
        }

        function removeFile(index) {
            selectedFiles.splice(index, 1);
            updateFileInput();
            refreshPreviews();
        }

        function updateFileInput() {
            const dt = new DataTransfer();
            selectedFiles.forEach(file => dt.items.add(file));
            fileInput.files = dt.files;
        }

        function refreshPreviews() {
            filePreview.innerHTML = '';
            selectedFiles.forEach((file, index) => {
                createPreview(file, index);
            });
        }

        function editNewImage(index, imageSrc) {
            currentFileIndex = index;
            imageToEdit.src = imageSrc;

            // Initialize Cropper.js
            const image = document.getElementById('imageToEdit');

            cropper = new Cropper(image, {
                aspectRatio: 1,
                viewMode: 2,
                dragMode: 'move',
                autoCropArea: 1,
                restore: false,
                center: true,
                highlight: false,
                zoomable: true,
                scalable: false,
                ready: function () {
                    zoomRange.value = 0;
                },
                crop: function (event) {
                    const croppedCanvas = cropper.getCroppedCanvas();
                    imagePreviewCanvas.innerHTML = '';
                    imagePreviewCanvas.appendChild(croppedCanvas);
                }
            });

            // Zoom functionality
            zoomRange.addEventListener('input', function (e) {
                cropper.zoomTo(e.target.value);
            });

            // Apply changes
            applyChangesBtn.onclick = function () {
                const croppedCanvas = cropper.getCroppedCanvas();
                croppedCanvas.toBlob((blob) => {
                    const croppedFile = new File([blob], selectedFiles[currentFileIndex].name, {
                        type: selectedFiles[currentFileIndex].type,
                        lastModified: Date.now()
                    });

                    selectedFiles[currentFileIndex] = croppedFile;
                    updateFileInput();
                    refreshPreviews();

                    cropper.destroy();
                    cropper = null;
                    currentFileIndex = null;
                    imageToEdit.src = '';
                    imagePreviewCanvas.innerHTML = '';
                    zoomRange.value = 0;

                    const modal = bootstrap.Modal.getInstance(imageEditModal);
                    modal.hide();

                }, selectedFiles[currentFileIndex].type);
            };

            const modal = new bootstrap.Modal(imageEditModal);
            modal.show();
        }
    </script>
</body>
</html>
