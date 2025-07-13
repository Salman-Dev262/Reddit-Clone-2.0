<?php
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$post_id = $_GET['id'] ?? 0;
$error = '';
$success = '';

// Fetch post to edit
$stmt = $pdo->prepare("SELECT * FROM posts WHERE id = ? AND user_id = ?");
$stmt->execute([$post_id, $_SESSION['user_id']]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$post) {
    header('Location: index.php');
    exit;
}

// Fetch existing files
$stmt = $pdo->prepare("SELECT * FROM files WHERE post_id = ? ORDER BY id");
$stmt->execute([$post_id]);
$existing_files = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch categories
$stmt = $pdo->query("SELECT * FROM categories ORDER BY name");
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $title = trim($_POST['title']);
    $content = trim($_POST['content']);
    $category_id = $_POST['category_id'];
    $files_to_delete = $_POST['delete_files'] ?? [];
    
    if (empty($title) || empty($category_id)) {
        $error = 'Title and community are required';
    } else {
        try {
            $pdo->beginTransaction();
            
            // Update post
            $stmt = $pdo->prepare("UPDATE posts SET title = ?, content = ?, category_id = ? WHERE id = ? AND user_id = ?");
            $stmt->execute([$title, $content, $category_id, $post_id, $_SESSION['user_id']]);
            
            // Delete selected files
            foreach ($files_to_delete as $file_id) {
                $stmt = $pdo->prepare("SELECT file_path FROM files WHERE id = ? AND post_id = ?");
                $stmt->execute([$file_id, $post_id]);
                $file = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($file && file_exists($file['file_path'])) {
                    unlink($file['file_path']);
                }
                
                $stmt = $pdo->prepare("DELETE FROM files WHERE id = ? AND post_id = ?");
                $stmt->execute([$file_id, $post_id]);
            }
            
            // Handle new file uploads
            if (isset($_FILES['new_files']) && !empty($_FILES['new_files']['name'][0])) {
                $upload_dir = 'uploads/post_' . $post_id . '/';
                
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $file_count = count($_FILES['new_files']['name']);
                for ($i = 0; $i < $file_count; $i++) {
                    if ($_FILES['new_files']['error'][$i] === UPLOAD_ERR_OK) {
                        $file_name = $_FILES['new_files']['name'][$i];
                        $file_type = $_FILES['new_files']['type'][$i];
                        $file_extension = pathinfo($file_name, PATHINFO_EXTENSION);
                        $new_file_name = uniqid() . '.' . $file_extension;
                        $file_path = $upload_dir . $new_file_name;
                        
                        if (move_uploaded_file($_FILES['new_files']['tmp_name'][$i], $file_path)) {
                            $stmt = $pdo->prepare("INSERT INTO files (post_id, file_name, file_path, file_type) VALUES (?, ?, ?, ?)");
                            $stmt->execute([$post_id, $file_name, $file_path, $file_type]);
                        }
                    }
                }
            }
            
            // Handle edited images
            if (isset($_POST['edited_images'])) {
                foreach ($_POST['edited_images'] as $file_id => $image_data) {
                    if (!empty($image_data)) {
                        // Decode base64 image data
                        $image_data = str_replace('data:image/png;base64,', '', $image_data);
                        $image_data = str_replace(' ', '+', $image_data);
                        $decoded_image = base64_decode($image_data);
                        
                        // Get original file info
                        $stmt = $pdo->prepare("SELECT file_path FROM files WHERE id = ? AND post_id = ?");
                        $stmt->execute([$file_id, $post_id]);
                        $file = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($file) {
                            // Save edited image
                            file_put_contents($file['file_path'], $decoded_image);
                        }
                    }
                }
            }
            
            $pdo->commit();
            header("Location: post.php?id=$post_id");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Error updating post: ' . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Post - Reddify</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.12/cropper.min.css" rel="stylesheet">
    <style>
        .file-item {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            background: #f8f9fa;
        }
        
        .file-preview {
            max-width: 200px;
            max-height: 150px;
            border-radius: 4px;
        }
        
        .file-upload-area {
            border: 2px dashed #dee2e6;
            border-radius: 8px;
            padding: 2rem;
            text-align: center;
            background: #f8f9fa;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .file-upload-area:hover {
            border-color: #dc3545;
            background: #f8d7da;
        }
        
        .image-editor-modal .modal-dialog {
            max-width: 90vw;
        }
        
        .cropper-container {
            max-height: 60vh;
        }
        
        .edit-tools {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-top: 1rem;
        }
        
        .filter-preview {
            width: 60px;
            height: 60px;
            border-radius: 4px;
            cursor: pointer;
            border: 2px solid transparent;
        }
        
        .filter-preview.active {
            border-color: #dc3545;
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
                <a href="post.php?id=<?php echo $post_id; ?>" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="card shadow-sm">
                    <div class="card-header bg-white">
                        <h4 class="mb-0">Edit Post</h4>
                    </div>
                    <div class="card-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger" role="alert">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <?php echo htmlspecialchars($error); ?>
                            </div>
                        <?php endif; ?>

                        <form method="POST" enctype="multipart/form-data" id="editPostForm">
                            <!-- Community Selection -->
                            <div class="mb-4">
                                <label for="category_id" class="form-label fw-bold">Community</label>
                                <select class="form-select form-select-lg" id="category_id" name="category_id" required>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?php echo $category['id']; ?>" 
                                                <?php echo $category['id'] == $post['category_id'] ? 'selected' : ''; ?>>
                                            r/<?php echo htmlspecialchars($category['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Title -->
                            <div class="mb-4">
                                <label for="title" class="form-label fw-bold">Title</label>
                                <input type="text" class="form-control form-control-lg" id="title" name="title" 
                                       value="<?php echo htmlspecialchars($post['title']); ?>" maxlength="300" required>
                            </div>

                            <!-- Content -->
                            <div class="mb-4">
                                <label for="content" class="form-label fw-bold">Text</label>
                                <textarea class="form-control" id="content" name="content" rows="8"><?php echo htmlspecialchars($post['content']); ?></textarea>
                            </div>

                            <!-- Existing Files -->
                            <?php if (!empty($existing_files)): ?>
                            <div class="mb-4">
                                <label class="form-label fw-bold">Current Files</label>
                                <div id="existingFiles">
                                    <?php foreach ($existing_files as $file): ?>
                                        <div class="file-item" data-file-id="<?php echo $file['id']; ?>">
                                            <div class="row align-items-center">
                                                <div class="col-md-3">
                                                    <?php if (strpos($file['file_type'], 'image/') === 0): ?>
                                                        <img src="<?php echo htmlspecialchars($file['file_path']); ?>" 
                                                             class="file-preview img-fluid" alt="File preview">
                                                    <?php else: ?>
                                                        <div class="text-center p-3">
                                                            <i class="fas fa-file fa-3x text-muted"></i>
                                                            <div class="small mt-2"><?php echo htmlspecialchars($file['file_name']); ?></div>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="col-md-6">
                                                    <h6><?php echo htmlspecialchars($file['file_name']); ?></h6>
                                                    <small class="text-muted"><?php echo $file['file_type']; ?></small>
                                                </div>
                                                <div class="col-md-3">
                                                    <div class="d-flex gap-2">
                                                        <?php if (strpos($file['file_type'], 'image/') === 0): ?>
                                                            <button type="button" class="btn btn-sm btn-outline-primary" 
                                                                    onclick="editImage(<?php echo $file['id']; ?>, '<?php echo htmlspecialchars($file['file_path']); ?>')">
                                                                <i class="fas fa-edit"></i> Edit
                                                            </button>
                                                        <?php endif; ?>
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="checkbox" 
                                                                   name="delete_files[]" value="<?php echo $file['id']; ?>"
                                                                   id="delete_<?php echo $file['id']; ?>">
                                                            <label class="form-check-label text-danger" for="delete_<?php echo $file['id']; ?>">
                                                                Delete
                                                            </label>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- Add New Files -->
                            <div class="mb-4">
                                <label class="form-label fw-bold">Add New Files</label>
                                <div class="file-upload-area" id="newFileUploadArea">
                                    <div class="mb-3">
                                        <i class="fas fa-cloud-upload-alt text-muted" style="font-size: 3rem;"></i>
                                    </div>
                                    <h5>Drag and drop new files here</h5>
                                    <p class="text-muted">or click to upload</p>
                                    <input type="file" id="new_files" name="new_files[]" multiple style="display: none;">
                                </div>
                                <div id="newFilePreview" class="mt-3"></div>
                            </div>

                            <!-- Hidden inputs for edited images -->
                            <div id="editedImagesContainer"></div>

                            <!-- Form Actions -->
                            <div class="d-flex justify-content-end gap-2">
                                <a href="post.php?id=<?php echo $post_id; ?>" class="btn btn-outline-secondary">Cancel</a>
                                <button type="submit" class="btn btn-danger btn-lg">
                                    <i class="fas fa-save me-2"></i>Update Post
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Image Editor Modal -->
    <div class="modal fade image-editor-modal" id="imageEditorModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Image</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-8">
                            <div class="image-container">
                                <img id="imageToEdit" src="/placeholder.svg" style="max-width: 100%;">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="edit-tools">
                                <h6>Tools</h6>
                                <div class="btn-group-vertical w-100 mb-3">
                                    <button type="button" class="btn btn-outline-secondary" onclick="cropImage()">
                                        <i class="fas fa-crop"></i> Crop
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary" onclick="rotateImage(90)">
                                        <i class="fas fa-redo"></i> Rotate Right
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary" onclick="rotateImage(-90)">
                                        <i class="fas fa-undo"></i> Rotate Left
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary" onclick="flipImage('horizontal')">
                                        <i class="fas fa-arrows-alt-h"></i> Flip Horizontal
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary" onclick="flipImage('vertical')">
                                        <i class="fas fa-arrows-alt-v"></i> Flip Vertical
                                    </button>
                                </div>

                                <h6>Filters</h6>
                                <div class="filters-grid mb-3">
                                    <div class="row g-2">
                                        <div class="col-4">
                                            <img class="filter-preview" data-filter="none" src="/placeholder.svg" alt="Original">
                                            <small class="d-block text-center">Original</small>
                                        </div>
                                        <div class="col-4">
                                            <img class="filter-preview" data-filter="grayscale" src="/placeholder.svg" alt="Grayscale" style="filter: grayscale(100%);">
                                            <small class="d-block text-center">Grayscale</small>
                                        </div>
                                        <div class="col-4">
                                            <img class="filter-preview" data-filter="sepia" src="/placeholder.svg" alt="Sepia" style="filter: sepia(100%);">
                                            <small class="d-block text-center">Sepia</small>
                                        </div>
                                        <div class="col-4">
                                            <img class="filter-preview" data-filter="blur" src="/placeholder.svg" alt="Blur" style="filter: blur(2px);">
                                            <small class="d-block text-center">Blur</small>
                                        </div>
                                        <div class="col-4">
                                            <img class="filter-preview" data-filter="brightness" src="/placeholder.svg" alt="Bright" style="filter: brightness(150%);">
                                            <small class="d-block text-center">Bright</small>
                                        </div>
                                        <div class="col-4">
                                            <img class="filter-preview" data-filter="contrast" src="/placeholder.svg" alt="Contrast" style="filter: contrast(150%);">
                                            <small class="d-block text-center">Contrast</small>
                                        </div>
                                    </div>
                                </div>

                                <h6>Adjustments</h6>
                                <div class="mb-2">
                                    <label class="form-label small">Brightness</label>
                                    <input type="range" class="form-range" id="brightnessSlider" min="0" max="200" value="100" oninput="adjustImage()">
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small">Contrast</label>
                                    <input type="range" class="form-range" id="contrastSlider" min="0" max="200" value="100" oninput="adjustImage()">
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small">Saturation</label>
                                    <input type="range" class="form-range" id="saturationSlider" min="0" max="200" value="100" oninput="adjustImage()">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="resetImage()">Reset</button>
                    <button type="button" class="btn btn-danger" onclick="saveEditedImage()">Save Changes</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.12/cropper.min.js"></script>
    <script>
        let cropper = null;
        let currentEditingFileId = null;
        let originalImageSrc = '';
        let currentFilter = 'none';
        let selectedFiles = [];

        // New file upload handling
        const newFileUploadArea = document.getElementById('newFileUploadArea');
        const newFileInput = document.getElementById('new_files');
        const newFilePreview = document.getElementById('newFilePreview');

        newFileUploadArea.addEventListener('click', () => newFileInput.click());

        newFileUploadArea.addEventListener('dragover', (e) => {
            e.preventDefault();
            newFileUploadArea.classList.add('dragover');
        });

        newFileUploadArea.addEventListener('dragleave', () => {
            newFileUploadArea.classList.remove('dragover');
        });

        newFileUploadArea.addEventListener('drop', (e) => {
            e.preventDefault();
            newFileUploadArea.classList.remove('dragover');
            
            const files = Array.from(e.dataTransfer.files);
            handleNewFiles(files);
        });

        newFileInput.addEventListener('change', function() {
            const files = Array.from(this.files);
            handleNewFiles(files);
        });

        function handleNewFiles(files) {
            files.forEach(file => {
                selectedFiles.push(file);
                createNewFilePreview(file, selectedFiles.length - 1);
            });
            updateNewFileInput();
        }

        function createNewFilePreview(file, index) {
            const previewItem = document.createElement('div');
            previewItem.className = 'file-item';
            
            if (file.type.startsWith('image/')) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    previewItem.innerHTML = `
                        <div class="row align-items-center">
                            <div class="col-md-3">
                                <img src="${e.target.result}" class="file-preview img-fluid" alt="Preview">
                            </div>
                            <div class="col-md-6">
                                <h6>${file.name}</h6>
                                <small class="text-muted">${file.type}</small>
                            </div>
                            <div class="col-md-3">
                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeNewFile(${index})">
                                    <i class="fas fa-times"></i> Remove
                                </button>
                            </div>
                        </div>
                    `;
                };
                reader.readAsDataURL(file);
            } else {
                previewItem.innerHTML = `
                    <div class="row align-items-center">
                        <div class="col-md-3">
                            <div class="text-center p-3">
                                <i class="fas fa-file fa-3x text-muted"></i>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <h6>${file.name}</h6>
                            <small class="text-muted">${file.type}</small>
                        </div>
                        <div class="col-md-3">
                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeNewFile(${index})">
                                <i class="fas fa-times"></i> Remove
                            </button>
                        </div>
                    </div>
                `;
            }
            
            newFilePreview.appendChild(previewItem);
        }

        function removeNewFile(index) {
            selectedFiles.splice(index, 1);
            updateNewFileInput();
            refreshNewFilePreviews();
        }

        function updateNewFileInput() {
            const dt = new DataTransfer();
            selectedFiles.forEach(file => dt.items.add(file));
            newFileInput.files = dt.files;
        }

        function refreshNewFilePreviews() {
            newFilePreview.innerHTML = '';
            selectedFiles.forEach((file, index) => {
                createNewFilePreview(file, index);
            });
        }

        // Image editing functions
        function editImage(fileId, imagePath) {
            currentEditingFileId = fileId;
            originalImageSrc = imagePath;
            
            const imageToEdit = document.getElementById('imageToEdit');
            imageToEdit.src = imagePath;
            
            // Initialize filter previews
            document.querySelectorAll('.filter-preview').forEach(preview => {
                preview.src = imagePath;
            });
            
            // Reset sliders
            document.getElementById('brightnessSlider').value = 100;
            document.getElementById('contrastSlider').value = 100;
            document.getElementById('saturationSlider').value = 100;
            
            currentFilter = 'none';
            document.querySelector('.filter-preview[data-filter="none"]').classList.add('active');
            
            new bootstrap.Modal(document.getElementById('imageEditorModal')).show();
            
            // Initialize cropper when modal is shown
            document.getElementById('imageEditorModal').addEventListener('shown.bs.modal', function() {
                if (cropper) {
                    cropper.destroy();
                }
                cropper = new Cropper(imageToEdit, {
                    viewMode: 1,
                    dragMode: 'move',
                    autoCropArea: 1,
                    restore: false,
                    guides: false,
                    center: false,
                    highlight: false,
                    cropBoxMovable: false,
                    cropBoxResizable: false,
                    toggleDragModeOnDblclick: false,
                });
            }, { once: true });
        }

        // Filter selection
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('filter-preview')) {
                document.querySelectorAll('.filter-preview').forEach(p => p.classList.remove('active'));
                e.target.classList.add('active');
                currentFilter = e.target.dataset.filter;
                adjustImage();
            }
        });

        function adjustImage() {
            const brightness = document.getElementById('brightnessSlider').value;
            const contrast = document.getElementById('contrastSlider').value;
            const saturation = document.getElementById('saturationSlider').value;
            
            let filterString = `brightness(${brightness}%) contrast(${contrast}%) saturate(${saturation}%)`;
            
            switch(currentFilter) {
                case 'grayscale':
                    filterString += ' grayscale(100%)';
                    break;
                case 'sepia':
                    filterString += ' sepia(100%)';
                    break;
                case 'blur':
                    filterString += ' blur(2px)';
                    break;
                case 'brightness':
                    filterString = `brightness(150%) contrast(${contrast}%) saturate(${saturation}%)`;
                    break;
                case 'contrast':
                    filterString = `brightness(${brightness}%) contrast(150%) saturate(${saturation}%)`;
                    break;
            }
            
            document.getElementById('imageToEdit').style.filter = filterString;
        }

        function cropImage() {
            if (cropper) {
                cropper.setDragMode('crop');
                cropper.setCropBoxData({
                    left: 50,
                    top: 50,
                    width: 200,
                    height: 200
                });
            }
        }

        function rotateImage(degrees) {
            if (cropper) {
                cropper.rotate(degrees);
            }
        }

        function flipImage(direction) {
            if (cropper) {
                if (direction === 'horizontal') {
                    cropper.scaleX(-cropper.getData().scaleX || -1);
                } else {
                    cropper.scaleY(-cropper.getData().scaleY || -1);
                }
            }
        }

        function resetImage() {
            if (cropper) {
                cropper.reset();
            }
            document.getElementById('brightnessSlider').value = 100;
            document.getElementById('contrastSlider').value = 100;
            document.getElementById('saturationSlider').value = 100;
            currentFilter = 'none';
            document.querySelectorAll('.filter-preview').forEach(p => p.classList.remove('active'));
            document.querySelector('.filter-preview[data-filter="none"]').classList.add('active');
            document.getElementById('imageToEdit').style.filter = '';
        }

        function saveEditedImage() {
            if (cropper) {
                const canvas = cropper.getCroppedCanvas({
                    width: 800,
                    height: 600,
                    imageSmoothingEnabled: true,
                    imageSmoothingQuality: 'high',
                });
                
                // Apply filters to canvas
                const ctx = canvas.getContext('2d');
                const brightness = document.getElementById('brightnessSlider').value;
                const contrast = document.getElementById('contrastSlider').value;
                const saturation = document.getElementById('saturationSlider').value;
                
                let filterString = `brightness(${brightness}%) contrast(${contrast}%) saturate(${saturation}%)`;
                
                switch(currentFilter) {
                    case 'grayscale':
                        filterString += ' grayscale(100%)';
                        break;
                    case 'sepia':
                        filterString += ' sepia(100%)';
                        break;
                    case 'blur':
                        filterString += ' blur(2px)';
                        break;
                }
                
                ctx.filter = filterString;
                ctx.drawImage(canvas, 0, 0);
                
                const editedImageData = canvas.toDataURL('image/png');
                
                // Store edited image data
                const container = document.getElementById('editedImagesContainer');
                let input = document.querySelector(`input[name="edited_images[${currentEditingFileId}]"]`);
                if (!input) {
                    input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = `edited_images[${currentEditingFileId}]`;
                    container.appendChild(input);
                }
                input.value = editedImageData;
                
                // Update preview
                const fileItem = document.querySelector(`[data-file-id="${currentEditingFileId}"]`);
                const preview = fileItem.querySelector('.file-preview');
                preview.src = editedImageData;
                
                bootstrap.Modal.getInstance(document.getElementById('imageEditorModal')).hide();
            }
        }

        // Clean up cropper when modal is hidden
        document.getElementById('imageEditorModal').addEventListener('hidden.bs.modal', function() {
            if (cropper) {
                cropper.destroy();
                cropper = null;
            }
        });
    </script>
</body>
</html>
