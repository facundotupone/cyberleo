<?php
require_once 'includes/auth_check.php';
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';
require_once 'includes/security.php';
require_once 'includes/images.php';

// --- ENDPOINT AJAX PARA PRODUCTOS DESTACADOS ---
if (isset($_GET['action']) && $_GET['action'] === 'get_featured_products') {
    header('Content-Type: application/json; charset=UTF-8');
    try {
        $rows = $pdo->query('SELECT id, name, image, destacados FROM products WHERE destacados > 0 AND is_active = 1 ORDER BY destacados')->fetchAll(PDO::FETCH_ASSOC);
        $featured = [];
        foreach ($rows as $row) $featured[] = ['id'=>(int)$row['id'], 'name'=>(string)$row['name'], 'image'=>is_safe_product_image_path($row['image']) ? $row['image'] : null, 'destacados'=>(int)$row['destacados']];
        echo json_encode($featured, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        error_log('Featured products error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success'=>false,'message'=>'No se pudieron cargar productos.']);
    }
    exit;
}

// --- ENDPOINT PARA GUARDAR ORDEN DE DESTACADOS ---
if (isset($_GET['action']) && $_GET['action'] === 'save_featured_order') {
    require_csrf(true);
    header('Content-Type: application/json');
    $data = json_decode(file_get_contents('php://input'), true);
    $success = true;
    if (is_array($data)) {
        foreach ($data as $item) {
            $id = intval($item['id']);
            $destacados = intval($item['destacados']);
            $stmt = $pdo->prepare("UPDATE products SET destacados = ? WHERE id = ?");
            if (!$stmt->execute([$destacados, $id])) {
                $success = false;
            }
        }
    } else {
        $success = false;
    }
    echo json_encode(['success' => $success]);
    exit;
}

$message = '';
$categories = get_categories();
$storeSettings = get_store_settings();

// Obtener todas las subcategorías
$stmtSub = $pdo->query("SELECT s.id, s.name, s.category_id FROM subcategories s ORDER BY s.name");
$subcategories = $stmtSub->fetchAll(PDO::FETCH_ASSOC);

// Procesar el formulario de producto
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (isset($_POST['action'])) {
        // Agregar nuevo producto
        if ($_POST['action'] === 'add') {
            $name = trim($_POST['name']);
            $description = trim($_POST['description']);
            $price = floatval($_POST['price']);
            $price_sale = isset($_POST['price_sale']) && $_POST['price_sale'] !== '' ? floatval($_POST['price_sale']) : 0;
            $stock = max(0, intval($_POST['stock'] ?? 0));
            $category_id = intval($_POST['category_id']);
            $subcategory_id = !empty($_POST['subcategory_id']) ? intval($_POST['subcategory_id']) : null;
                $destacados = 0;

            $pdo->beginTransaction();
            try {
                // Insertar producto sin imagen principal aún
                $stmt = $pdo->prepare("INSERT INTO products (name, description, price, price_sale, stock, category_id, subcategory_id, destacados, image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL)");
                $stmt->execute([$name, $description, $price, $price_sale, $stock, $category_id, $subcategory_id, $destacados]);
                $product_id = $pdo->lastInsertId();

                $main_image_path = null;
                if (!empty($_FILES['images']['name'][0])) {
                    $upload_dir = 'assets/images/products/';
                    foreach ($_FILES['images']['tmp_name'] as $key => $tmp_name) {
                        if ($_FILES['images']['error'][$key] === UPLOAD_ERR_OK) {
                            $image_path = store_safe_image($tmp_name, $_FILES['images']['error'][$key], $_FILES['images']['size'][$key], rtrim($upload_dir, '/'));
                            if ($image_path) {
                                $is_main = ($key === 0) ? 1 : 0;
                                $stmt = $pdo->prepare("INSERT INTO product_images (product_id, image_path, is_main) VALUES (?, ?, ?)");
                                $stmt->execute([$product_id, $image_path, $is_main]);
                                if ($is_main) {
                                    $main_image_path = $image_path;
                                }
                            }
                        }
                    }
                }
                // Si hay imagen principal, actualizar el campo image en products
                if ($main_image_path) {
                    $stmt = $pdo->prepare("UPDATE products SET image = ? WHERE id = ?");
                    $stmt->execute([$main_image_path, $product_id]);
                }
                $pdo->commit();
                $message = 'Producto agregado exitosamente.';
            } catch (Exception $e) {
                $pdo->rollBack();
                $message = 'Error al agregar el producto: ' . $e->getMessage();
            }
        }
        // Editar producto
        elseif ($_POST['action'] === 'edit') {
            $id = intval($_POST['id']);
            $name = trim($_POST['name']);
            $description = trim($_POST['description']);
            $price = floatval($_POST['price']);
            $price_sale = !empty($_POST['price_sale']) ? floatval($_POST['price_sale']) : null;
            $category_id = intval($_POST['category_id']);
            $subcategory_id = !empty($_POST['subcategory_id']) ? intval($_POST['subcategory_id']) : null;
            $destacados = isset($_POST['destacados']) ? 1 : 0;
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $main_image_id = isset($_POST['main_image']) ? intval($_POST['main_image']) : null;

            $pdo->beginTransaction();

            try {
                $stmt = $pdo->prepare("UPDATE products SET name = ?, description = ?, price = ?, price_sale = ?, category_id = ?, subcategory_id = ?, destacados = ?, is_active = ? WHERE id = ?");
                $stmt->execute([$name, $description, $price, $price_sale, $category_id, $subcategory_id, $destacados, $is_active, $id]);

                if ($main_image_id) {
                    $mainImage = $pdo->prepare('SELECT image_path FROM product_images WHERE id = ? AND product_id = ? FOR UPDATE');
                    $mainImage->execute([$main_image_id, $id]);
                    $mainImagePath = $mainImage->fetchColumn();
                    if (!$mainImagePath) throw new RuntimeException('La imagen principal no pertenece al producto.');
                    $stmt = $pdo->prepare("UPDATE product_images SET is_main = 0 WHERE product_id = ?");
                    $stmt->execute([$id]);
                    $stmt = $pdo->prepare("UPDATE product_images SET is_main = 1 WHERE id = ? AND product_id = ?");
                    $stmt->execute([$main_image_id, $id]);
                    $pdo->prepare('UPDATE products SET image = ? WHERE id = ?')->execute([$mainImagePath, $id]);
                }

                if (!empty($_FILES['new_images']['name'][0])) {
                    $upload_dir = 'assets/images/products/';
                    foreach ($_FILES['new_images']['tmp_name'] as $key => $tmp_name) {
                        if ($_FILES['new_images']['error'][$key] === UPLOAD_ERR_OK) {
                            $image_path = store_safe_image($tmp_name, $_FILES['new_images']['error'][$key], $_FILES['new_images']['size'][$key], rtrim($upload_dir, '/'));
                            if ($image_path) {
                                $is_main = 0;
                                $stmt = $pdo->prepare("INSERT INTO product_images (product_id, image_path, is_main) VALUES (?, ?, ?)");
                                $stmt->execute([$id, $image_path, $is_main]);
                            }
                        }
                    }
                }

                $pdo->commit();
                $message = 'Producto actualizado exitosamente.';
            } catch (Exception $e) {
                $pdo->rollBack();
                $message = 'Error al actualizar el producto: ' . $e->getMessage();
            }
        }
        // Eliminar producto
        elseif ($_POST['action'] === 'delete' && isset($_POST['id'])) {
            $product_id = intval($_POST['id']);

            $pdo->beginTransaction();

            try {
                $stmt = $pdo->prepare("SELECT id FROM products WHERE id = ? FOR UPDATE");
                $stmt->execute([$product_id]);
                if (!$stmt->fetchColumn()) throw new RuntimeException('Producto no encontrado.');
                $stmt = $pdo->prepare("SELECT image_path FROM product_images WHERE product_id = ? FOR UPDATE");
                $stmt->execute([$product_id]);
                $images = $stmt->fetchAll(PDO::FETCH_COLUMN);

                $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
                $success = $stmt->execute([$product_id]);

                if ($success) {
                    $pdo->commit();
                    foreach ($images as $image_path) delete_image_if_unreferenced($pdo, $image_path);
                    $message = 'Producto eliminado exitosamente.';
                } else {
                    $pdo->rollBack();
                    $message = 'Error al eliminar el producto.';
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                $message = 'Error al eliminar el producto: ' . $e->getMessage();
            }
        }
        // Otras acciones (toggle_featured, update_stock, etc.)

        elseif ($_POST['action'] === 'toggle_featured' && isset($_POST['product_id'])) {
            $product_id = intval($_POST['product_id']);
            $current_status = isset($_POST['current_status']) ? intval($_POST['current_status']) : 0;
            $new_status = $current_status ? 0 : 1;

            $stmt = $pdo->prepare("UPDATE products SET destacados = ? WHERE id = ?");
            $stmt->execute([$new_status, $product_id]);
            $message = $new_status ? 'Producto marcado como destacado.' : 'Producto quitado de destacados.';
        }
        // Actualizar stock
        elseif ($_POST['action'] === 'update_stock' && isset($_POST['id'])) {
            $product_id = intval($_POST['id']);
            $change = isset($_POST['change']) ? intval($_POST['change']) : 0;

            // Obtener stock actual
            $stmt = $pdo->prepare("SELECT stock FROM products WHERE id = ?");
            $stmt->execute([$product_id]);
            $current_stock = $stmt->fetchColumn();

            if ($current_stock !== false) {
                $new_stock = max(0, $current_stock + $change);
                $stmt = $pdo->prepare("UPDATE products SET stock = ? WHERE id = ?");
                $stmt->execute([$new_stock, $product_id]);
                $message = 'Stock actualizado correctamente.';
            } else {
                $message = 'Producto no encontrado.';
            }
        }
        // SOBREESCRIBIR STOCK MANUALMENTE
        elseif ($_POST['action'] === 'set_stock' && isset($_POST['id'], $_POST['new_stock'])) {
            $product_id = intval($_POST['id']);
            $new_stock = max(0, intval($_POST['new_stock']));
            $stmt = $pdo->prepare("UPDATE products SET stock = ? WHERE id = ?");
            $stmt->execute([$new_stock, $product_id]);
            $message = 'Stock actualizado manualmente.';
        }
    }
}

$stmt = $pdo->query("
    SELECT p.*, c.name as category_name, s.name as subcategory_name,
           (SELECT COUNT(*) FROM product_images WHERE product_id = p.id) as image_count
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN subcategories s ON p.subcategory_id = s.id
    ORDER BY c.name, p.name
");
$products = $stmt->fetchAll();

// Agrupar productos por categoría
$productsByCategory = [];
foreach ($products as $product) {
    $cat = $product['category_name'] ?: 'Sin categoría';
    if (!isset($productsByCategory[$cat])) $productsByCategory[$cat] = [];
    $productsByCategory[$cat][] = $product;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administrar Productos - <?= htmlspecialchars($storeSettings['store_name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
        }

        .navbar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .navbar-brand {
            font-weight: 700;
            font-size: 1.5rem;
            letter-spacing: 1px;
        }

        .nav-link.active {
            color: #fff !important;
            border-bottom: 3px solid #ffd700;
            padding-bottom: 0.5rem;
        }

        .container {
            margin-top: 2rem;
        }

        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            background: rgba(255, 255, 255, 0.98);
            transition: all 0.3s ease;
        }

        .card:hover {
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
            transform: translateY(-2px);
        }

        .card-header {
            border-radius: 15px 15px 0 0 !important;
            font-weight: 600;
            letter-spacing: 0.5px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
        }

        .card-body {
            padding: 2rem;
        }

        h1 {
            color: #333;
            font-weight: 700;
            margin-bottom: 1.5rem;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.05);
        }

        h2 {
            color: #555;
            font-weight: 600;
            margin-bottom: 1.5rem;
        }

        .form-control, .form-select {
            border-radius: 10px;
            border: 2px solid #e0e0e0;
            padding: 0.75rem 1rem;
            font-size: 0.95rem;
            transition: all 0.3s ease;
        }

        .form-control:focus, .form-select:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.15);
        }

        .form-floating > label {
            color: #999;
            font-weight: 500;
        }

        .btn {
            border-radius: 10px;
            font-weight: 600;
            padding: 0.75rem 1.5rem;
            transition: all 0.3s ease;
            border: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
        }

        .btn-info {
            background: linear-gradient(135deg, #00d2fc 0%, #0087be 100%);
            color: white;
            border: none;
        }

        .btn-info:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 135, 190, 0.3);
        }

        .btn-danger {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a6f 100%);
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(255, 107, 107, 0.3);
        }

        .btn-warning {
            background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%);
            color: white;
        }

        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(255, 193, 7, 0.3);
        }

        .btn-outline-secondary {
            border: 2px solid #ddd;
            color: #666;
        }

        .btn-outline-secondary:hover {
            background: #f5f5f5;
            border-color: #667eea;
            color: #667eea;
        }

        .btn-success {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(40, 167, 69, 0.3);
        }

        .btn-outline-danger {
            border: 2px solid #ff6b6b;
            color: #ff6b6b;
        }

        .btn-outline-danger:hover {
            background: #ff6b6b;
            color: white;
        }

        .btn-outline-success {
            border: 2px solid #28a745;
            color: #28a745;
        }

        .btn-outline-success:hover {
            background: #28a745;
            color: white;
        }

        .btn-outline-primary {
            border: 2px solid #667eea;
            color: #667eea;
        }

        .btn-outline-primary:hover {
            background: #667eea;
            color: white;
        }

        .table {
            border-radius: 10px;
            overflow: hidden;
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .table-dark {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .table-warning {
            background: rgba(255, 193, 7, 0.1) !important;
            font-weight: 600;
        }

        .table th {
            font-weight: 700;
            letter-spacing: 0.5px;
            padding: 1.25rem;
            border: none;
        }

        .table td {
            padding: 1rem;
            border-color: #f0f0f0;
            vertical-align: middle;
        }

        .table tbody tr:hover {
            background: rgba(102, 126, 234, 0.05);
        }

        .badge {
            padding: 0.5em 0.8em;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.85em;
            letter-spacing: 0.5px;
        }

        .badge.bg-info {
            background: linear-gradient(135deg, #00d2fc 0%, #0087be 100%) !important;
        }

        .badge.bg-success {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%) !important;
        }

        .badge.bg-danger {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a6f 100%) !important;
        }

        .badge.bg-warning {
            background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%) !important;
            color: white;
        }

        .badge.bg-secondary {
            background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%) !important;
        }

        .image-thumbnail {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border: 3px solid #ddd;
            border-radius: 10px;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .image-thumbnail:hover {
            border-color: #667eea;
            box-shadow: 0 4px 10px rgba(102, 126, 234, 0.2);
            transform: scale(1.05);
        }

        .main-image {
            border-color: #ffc107;
            box-shadow: 0 0 0 3px rgba(255, 193, 7, 0.3);
        }

        .image-container {
            position: relative;
            display: inline-block;
            margin-right: 15px;
            margin-bottom: 15px;
            padding: 8px;
            background: rgba(0,0,0,0.02);
            border-radius: 12px;
            transition: all 0.3s ease;
        }

        .image-container:hover {
            background: rgba(102, 126, 234, 0.08);
            transform: translateY(-2px);
        }

        .delete-image-btn {
            position: absolute;
            top: -8px;
            right: -8px;
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a6f 100%);
            color: white;
            border-radius: 50%;
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(255, 107, 107, 0.3);
            transition: all 0.3s ease;
            border: 2px solid white;
        }

        .delete-image-btn:hover {
            transform: scale(1.1);
            box-shadow: 0 4px 12px rgba(255, 107, 107, 0.4);
        }

        .alert {
            border-radius: 10px;
            border: none;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .alert-info {
            background: linear-gradient(135deg, rgba(13, 202, 240, 0.1), rgba(13, 202, 240, 0.05));
            color: #0c5460;
            border-left: 4px solid #0dcaf0;
        }

        .list-group-item {
            border: 2px solid #f0f0f0;
            border-radius: 10px;
            margin-bottom: 0.5rem;
            transition: all 0.3s ease;
        }

        .list-group-item:hover {
            border-color: #667eea;
            background: rgba(102, 126, 234, 0.05);
            transform: translateX(5px);
        }

        .modal-header {
            border-radius: 15px 15px 0 0 !important;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
            border: none;
        }

        .modal-content {
            border-radius: 15px;
            border: none;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }

        .modal-footer {
            border-top: 2px solid #f0f0f0;
            padding: 1.5rem;
        }

        .modal-body {
            padding: 2rem;
        }

        .form-check-input {
            width: 1.25em;
            height: 1.25em;
            border: 2px solid #ddd;
            border-radius: 4px;
        }

        .form-check-input:checked {
            background-color: #667eea;
            border-color: #667eea;
        }

        .product-name {
            font-weight: 500;
            color: #333;
        }

        @media (max-width: 991px) {
            .col-md-4, .col-md-8 {
                margin-bottom: 2rem;
            }
        }

        small.text-muted {
            color: #999 !important;
            font-weight: 500;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand fw-bold" href="#">
                <i class="bi bi-box-seam"></i> Panel de Admin
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#adminNavbar">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="adminNavbar">
                <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
                    <li class="nav-item">
                        <a class="nav-link active" href="admin_products.php">
                            <i class="bi bi-box-seam"></i> Productos
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="admin_categories.php">
                            <i class="bi bi-tags"></i> Categorías
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="admin_orders.php">
                            <i class="bi bi-receipt"></i> Pedidos
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="admin_settings.php">
                            <i class="bi bi-sliders"></i> Configuración
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid my-4">
        <div class="d-flex align-items-center gap-3 mb-4">
            <i class="bi bi-cube" style="font-size: 2.5rem; color: #667eea;"></i>
            <div>
                <h1 class="mb-0">Administrar Productos</h1>
                <small class="text-muted">Gestiona tu catálogo</small>
            </div>
        </div>

<?php if ($message): ?>
        <div class="alert alert-info mb-4">
            <i class="bi bi-check-circle"></i> <?= htmlspecialchars($message) ?>
        </div>
<?php endif; ?>

        <div class="row">
            <div class="col-md-4">
                <div class="card shadow-sm mb-4">
                    <div class="card-header">
                        <h5 class="mb-0 text-white">
                            <i class="bi bi-plus-circle"></i> Agregar Nuevo Producto
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data" class="mb-4">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="add">

                            <div class="mb-3 form-floating">
                                <input type="text" class="form-control" id="name" name="name" placeholder="Nombre" required>
                                <label for="name"><i class="bi bi-alphabet"></i> Nombre</label>
                            </div>
                            <div class="mb-3 form-floating">
                                <textarea class="form-control" id="description" name="description" placeholder="Descripción" style="height: 100px" required></textarea>
                                <label for="description"><i class="bi bi-chat-dots"></i> Descripción</label>
                            </div>
                            <div class="mb-3 form-floating">
                                <input type="number" class="form-control" id="price" name="price" step="0.01" placeholder="Precio" required>
                                <label for="price"><i class="bi bi-currency-dollar"></i> Precio</label>
                            </div>
                            <div class="mb-3 form-floating">
                                <input type="number" class="form-control" id="price_sale" name="price_sale" step="0.01" placeholder="Precio Promoción (opcional)">
                                <label for="price_sale"><i class="bi bi-tag"></i> Precio Promoción</label>
                            </div>
                            <div class="mb-3 form-floating">
                                <input type="number" class="form-control" id="stock" name="stock" min="0" value="0" placeholder="Stock" required>
                                <label for="stock"><i class="bi bi-boxes"></i> Stock inicial</label>
                            </div>
                            <div class="mb-3">
                                <label for="category_id" class="form-label fw-600"><i class="bi bi-folder"></i> Categoría</label>
                                <select class="form-select" id="category_id" name="category_id" required>
                                    <option value="">Seleccionar categoría</option>
<?php foreach ($categories as $category): ?>
                                    <option value="<?= $category['id'] ?>"><?= htmlspecialchars($category['name']) ?></option>
<?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="subcategory_id" class="form-label fw-600"><i class="bi bi-diagram-3"></i> Subcategoría</label>
                                <select class="form-select" id="subcategory_id" name="subcategory_id" required>
                                    <option value="">Seleccionar</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="images" class="form-label fw-600"><i class="bi bi-images"></i> Imágenes (Múltiples)</label>
                                <input type="file" class="form-control" id="images" name="images[]" multiple accept="image/*" required>
                                <small class="text-muted d-block mt-2">La primera imagen será la principal</small>
                            </div>
                            <button type="submit" class="btn btn-primary w-100" id="addProductBtn">
                                <i class="bi bi-check-circle"></i> Agregar Producto
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0 text-white">
                            <i class="bi bi-list-ul"></i> Lista de Productos
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-4">
                            <div class="input-group">
                                <span class="input-group-text" style="border-radius: 10px 0 0 10px; background: white; border: 2px solid #e0e0e0;">
                                    <i class="bi bi-search"></i>
                                </span>
                                <input type="text" id="searchAdminProducts" class="form-control" placeholder="Buscar producto por nombre..." style="border: 2px solid #e0e0e0; border-left: none; border-radius: 0 10px 10px 0;">
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover align-middle shadow-sm" id="products-table">
                                <thead class="table-dark">
                                    <tr>
                                        <th><i class="bi bi-image"></i> Imágenes</th>
                                        <th><i class="bi bi-file-text"></i> Nombre</th>
                                        <th><i class="bi bi-folder"></i> Categoría</th>
                                        <th><i class="bi bi-currency-dollar"></i> Precio</th>
                                        <th><i class="bi bi-boxes"></i> Stock</th>
                                        <th><i class="bi bi-gear"></i> Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
<?php foreach ($productsByCategory as $catName => $catProducts): ?>
                            <tr class="table-warning">
                                <td colspan="6"><strong><?= htmlspecialchars($catName) ?></strong></td>
                            </tr>
<?php foreach ($catProducts as $product):
                                $stmt = $pdo->prepare("SELECT * FROM product_images WHERE product_id = ? ORDER BY is_main DESC");
                                $stmt->execute([$product['id']]);
                                $images = $stmt->fetchAll();

                            ?>
                            <tr>
                                <td>
<?php if (!empty($images)): ?>
                                        <div class="d-flex flex-wrap gap-1">
<?php foreach ($images as $image): ?>
<?php if (is_safe_product_image_path($image['image_path'])): ?>
                                                    <img src="<?= htmlspecialchars($image['image_path'], ENT_QUOTES, 'UTF-8') ?>"
                                                        class="image-thumbnail <?= $image['is_main'] ? 'main-image' : '' ?>"
                                                        title="<?= $image['is_main'] ? 'Imagen principal' : '' ?>">
<?php endif; ?>
<?php endforeach; ?>
                                        </div>
                                        <small><?= count($images) ?> <?= count($images) === 1 ? 'imagen' : 'imágenes' ?></small>
<?php else: ?>
                                        <span class="text-muted">Sin imágenes</span>
<?php endif; ?>
                                </td>
                                <td class="product-name">
                                    <span class="fw-bold"><?= htmlspecialchars($product['name']) ?></span>
                                </td>
                                <td>
                                    <span class="badge bg-info"><?= htmlspecialchars($product['category_name']) ?></span>
<?php if (!empty($product['subcategory_name'])): ?>
                                        <small class="d-block text-muted"><?= htmlspecialchars($product['subcategory_name']) ?></small>
<?php endif; ?>
                                </td>
                                <td>
<?php if (!empty($product['price_sale']) && $product['price_sale'] > 0): ?>
                                        <div class="d-flex flex-column align-items-start gap-1">
                                            <span class="badge bg-danger">LIQUIDACIÓN</span>
                                            <div>
                                                <span class="badge bg-success text-decoration-line-through me-1">$<?= number_format($product['price'], 2) ?></span>
                                                <span class="badge bg-danger fs-6">$<?= number_format($product['price_sale'], 2) ?></span>
                                            </div>
                                        </div>
<?php else: ?>
                                        <span class="badge bg-success">$<?= number_format($product['price'], 2) ?></span>
<?php endif; ?>
                                </td>
                                <td>
<?php if ($product['stock'] == 0): ?>
                                        <span class="badge bg-danger">Sin stock</span>
<?php elseif ($product['stock'] < 5): ?>
                                        <span class="badge bg-warning text-dark"><?= $product['stock'] ?></span>
<?php else: ?>
                                        <span class="badge bg-secondary"><?= $product['stock'] ?></span>
<?php endif; ?>
                                    <div class="d-flex align-items-center mt-2">
                                        <form method="POST" class="me-1">
                                            <?= csrf_input() ?>
                                            <input type="hidden" name="action" value="update_stock">
                                            <input type="hidden" name="change" value="-1">
                                            <input type="hidden" name="id" value="<?= $product['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" <?= $product['stock'] <= 0 ? 'disabled' : '' ?> title="Restar 1" data-bs-toggle="tooltip">-</button>
                                        </form>
                                        <form method="POST" class="d-flex align-items-center mx-2" style="gap:4px;">
                                            <?= csrf_input() ?>
                                            <input type="hidden" name="action" value="set_stock">
                                            <input type="hidden" name="id" value="<?= $product['id'] ?>">
                                            <input type="number" name="new_stock" value="<?= $product['stock'] ?>" min="0" class="form-control form-control-sm" style="width:60px;">
                                            <button type="submit" class="btn btn-sm btn-outline-primary" title="Guardar nuevo stock" data-bs-toggle="tooltip">
                                                <i class="bi bi-save"></i>
                                            </button>
                                        </form>
                                        <form method="POST">
                                            <?= csrf_input() ?>
                                            <input type="hidden" name="action" value="update_stock">
                                            <input type="hidden" name="change" value="1">
                                            <input type="hidden" name="id" value="<?= $product['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-success" title="Sumar 1" data-bs-toggle="tooltip">+</button>
                                        </form>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column gap-2">
                                        <button type="button" class="btn btn-info btn-sm edit-product-btn" title="Editar producto" data-bs-toggle="tooltip"
                                            data-product="<?= htmlspecialchars(json_encode($product, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>"
                                            data-images="<?= htmlspecialchars(json_encode($images, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>">
                                            <i class="bi bi-pencil-square"></i> Editar
                                        </button>
                                        <form method="POST" class="delete-product-form" style="margin: 0;">
                                            <?= csrf_input() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= $product['id'] ?>">
                                            <button type="submit" class="btn btn-danger btn-sm w-100" title="Eliminar producto" data-bs-toggle="tooltip">
                                                <i class="bi bi-trash"></i> Eliminar
                                            </button>
                                        </form>
                                        <button type="button" class="btn btn-sm featured-order-btn <?= $product['destacados'] ? 'btn-warning' : 'btn-outline-secondary' ?>" title="Destacar/Quitar destacado" data-bs-toggle="tooltip" data-product-id="<?= (int) $product['id'] ?>">
                                            <?= $product['destacados'] ? '<i class="bi bi-star-fill"></i> Destacado' : '<i class="bi bi-star"></i> Destacar' ?>
                                        </button>
                                    </div>
                                </td>
                            </tr>
<?php endforeach; ?>
<?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="featuredOrderModal" tabindex="-1" aria-labelledby="featuredOrderModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-lg">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="featuredOrderModalLabel">
                <i class="bi bi-star-fill"></i> Ordenar Productos Destacados
            </h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
          </div>
          <div class="modal-body">
            <div id="featuredOrderList" class="list-group">
              <!-- Aquí se cargará la lista drag & drop con JS -->
            </div>
            <small class="text-muted d-block mt-3"><i class="bi bi-info-circle"></i> Usa los botones para reordenar. El primero será el más destacado.</small>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="button" class="btn btn-primary" id="saveFeaturedOrderBtn">
                <i class="bi bi-check-circle"></i> Guardar orden
            </button>
          </div>
        </div>
      </div>
    </div>
    <div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-pencil-square"></i> Editar Producto
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <?= csrf_input() ?>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="id" id="edit_id">

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label fw-600"><i class="bi bi-alphabet"></i> Nombre</label>
                                    <input type="text" class="form-control" id="edit_name" name="name" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-600"><i class="bi bi-chat-dots"></i> Descripción</label>
                                    <textarea class="form-control" id="edit_description" name="description" rows="3" required></textarea>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-600"><i class="bi bi-currency-dollar"></i> Precio</label>
                                    <input type="number" class="form-control" id="edit_price" name="price" step="0.01" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-600"><i class="bi bi-tag"></i> Precio Promoción</label>
                                    <input type="number" class="form-control" id="edit_price_sale" name="price_sale" step="0.01" placeholder="Opcional">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label fw-600"><i class="bi bi-folder"></i> Categoría</label>
                                    <select class="form-select" id="edit_category_id" name="category_id" required>
                                        <option value="">Seleccionar</option>
<?php foreach ($categories as $category): ?>
                                        <option value="<?= $category['id'] ?>"><?= htmlspecialchars($category['name']) ?></option>
<?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-600"><i class="bi bi-diagram-3"></i> Subcategoría</label>
                                    <select class="form-select" id="edit_subcategory_id" name="subcategory_id">
                                        <option value="">Seleccionar</option>
                                    </select>
                                </div>
                                <div class="mb-3 form-check">
                                    <label class="form-label fw-600" for="edit_destacados"><i class="bi bi-star"></i> Orden Destacado</label>
                                    <input type="number" class="form-control" id="edit_destacados" name="destacados" min="0" max="99" value="0">
                                    <small class="text-muted d-block mt-2">0 = no destacado, 1-9 = orden de prioridad</small>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="edit_is_active" name="is_active" value="1">
                                    <label class="form-check-label" for="edit_is_active">Publicado y visible en la tienda</label>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-600"><i class="bi bi-image"></i> Imágenes Actuales</label>
                            <div id="current_images_container" class="d-flex flex-wrap gap-3 mb-3"></div>

                            <label class="form-label fw-600 mt-4">Agregar Nuevas Imágenes</label>
                            <input type="file" class="form-control" name="new_images[]" multiple accept="image/*">
                            <small class="text-muted d-block mt-2">Seleccione imágenes adicionales (opcional)</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle"></i> Guardar Cambios
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>


    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Confirmación antes de agregar producto para evitar duplicados
    document.addEventListener('DOMContentLoaded', function() {
        var addForm = document.querySelector('form[method="POST"][enctype="multipart/form-data"].mb-4');
        if (addForm) {
            addForm.addEventListener('submit', function(e) {
                if (!confirm('¿Estás seguro de que quieres agregar este producto?\nVerifica que no esté repetido.')) {
                    e.preventDefault();
                }
            });
        }
    });
    </script>
    <script>
// --- Orden y guardado de destacados ---
let featuredOrderList = null;
let featuredOrderModal = null;
const DEFAULT_PRODUCT_IMAGE = 'assets/images/products/default.jpg';
const PRODUCT_IMAGE_PATH = /^assets\/images\/products\/(?:[a-f0-9]{13}|[a-f0-9]{32})\.(?:jpg|jpeg|png|webp)$/i;

function validId(value) {
    return typeof value === 'string' || typeof value === 'number'
        ? /^[1-9]\d*$/.test(String(value)) && Number.isSafeInteger(Number(value))
        : false;
}

function safeProductImagePath(value) {
    return typeof value === 'string' && PRODUCT_IMAGE_PATH.test(value) ? value : DEFAULT_PRODUCT_IMAGE;
}

function icon(className) {
    const element = document.createElement('i');
    element.className = className;
    return element;
}

function actionButton(className, title, iconClass, listener) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = className;
    button.title = title;
    button.setAttribute('aria-label', title);
    button.appendChild(icon(iconClass));
    button.addEventListener('click', listener);
    return button;
}

function createFeaturedItem(product, position) {
    const item = document.createElement('div');
    item.className = 'list-group-item list-group-item-action d-flex align-items-center gap-2';
    item.dataset.id = String(product.id);

    const image = document.createElement('img');
    image.src = safeProductImagePath(product.image);
    image.alt = '';
    image.style.cssText = 'width:40px;height:40px;object-fit:cover;border-radius:6px;';

    const name = document.createElement('span');
    name.textContent = typeof product.name === 'string' ? product.name : 'Producto';

    const badge = document.createElement('span');
    badge.className = 'badge bg-warning ms-auto';
    badge.textContent = String(position);

    item.append(
        image,
        name,
        badge,
        actionButton('btn btn-sm btn-outline-secondary ms-2', 'Subir', 'bi bi-arrow-up', () => {
            if (item.previousElementSibling) featuredOrderList.insertBefore(item, item.previousElementSibling);
        }),
        actionButton('btn btn-sm btn-outline-secondary ms-1', 'Bajar', 'bi bi-arrow-down', () => {
            if (item.nextElementSibling) featuredOrderList.insertBefore(item.nextElementSibling, item);
        }),
        actionButton('btn btn-sm btn-outline-danger ms-2', 'Quitar de destacados', 'bi bi-x-lg', () => item.remove())
    );
    return item;
}

function openFeaturedOrderModal(productId, sourceButton) {
    if (!validId(productId)) return;
    if (!featuredOrderList) featuredOrderList = document.getElementById('featuredOrderList');
    if (!featuredOrderModal) featuredOrderModal = new bootstrap.Modal(document.getElementById('featuredOrderModal'));
    featuredOrderModal.show();

    fetch('admin_products.php?action=get_featured_products')
        .then(r => r.ok ? r.json() : Promise.reject(new Error('No se pudieron cargar los destacados')))
        .then(data => {
            const products = Array.isArray(data) ? data.filter(product => product && validId(product.id)) : [];
            const already = products.some(product => String(product.id) === String(productId));
            if (!already && sourceButton instanceof HTMLElement) {
                const row = sourceButton.closest('tr');
                const name = row?.querySelector('.product-name span.fw-bold')?.textContent || 'Producto';
                const tableImage = row?.querySelector('img.image-thumbnail')?.getAttribute('src');
                products.push({id: productId, name, image: safeProductImagePath(tableImage)});
            }
            featuredOrderList.replaceChildren(...products.map((product, index) => createFeaturedItem(product, index + 1)));
        })
        .catch(error => console.error('Error loading featured products:', error));
}

// Inicializar listeners una sola vez
document.addEventListener('DOMContentLoaded', function() {
    featuredOrderList = document.getElementById('featuredOrderList');
    featuredOrderModal = new bootstrap.Modal(document.getElementById('featuredOrderModal'));
    document.getElementById('saveFeaturedOrderBtn').addEventListener('click', saveFeaturedOrder);
    document.querySelectorAll('.featured-order-btn').forEach(button => {
        button.addEventListener('click', () => openFeaturedOrderModal(button.dataset.productId, button));
    });
});

function saveFeaturedOrder() {
    // Obtener ids y orden de los destacados actuales
    const destacadosArr = Array.from(featuredOrderList.children)
        .filter(el => validId(el.dataset.id))
        .map((el, idx) => ({id: el.dataset.id, destacados: idx + 1}));
    // Obtener todos los ids de productos destacados antes de cambios (para saber cuáles fueron quitados)
    fetch('admin_products.php?action=get_featured_products')
        .then(r => r.json())
        .then(originalData => {
            const originalIds = Array.isArray(originalData)
                ? originalData.filter(product => product && validId(product.id)).map(product => String(product.id))
                : [];
            const currentIds = destacadosArr.map(p => String(p.id));
            // Los ids que estaban y ya no están deben ir con destacados=0
            const removed = originalIds.filter(id => !currentIds.includes(id)).map(id => ({id:id, destacados:0}));
            // Unir los arrays
            const payload = [...destacadosArr, ...removed];
            fetch('admin_products.php?action=save_featured_order', {
                method: 'POST',
                headers: {'Content-Type':'application/json', 'X-CSRF-Token': '<?= csrf_token() ?>'},
                body: JSON.stringify(payload)
            })
            .then(r=>r.json())
            .then(resp=>{
                if(resp.success){
                    featuredOrderModal.hide();
                    location.reload();
                }else{
                    alert('Error al guardar el orden');
                }
            });
        });
}

// Cargar subcategorías al cambiar categoría (alta)
document.getElementById('category_id').addEventListener('change', function() {
    const categoryId = this.value;
    const subcategorySelect = document.getElementById('subcategory_id');
    setSubcategoryMessage(subcategorySelect, 'Seleccionar');
    if (validId(categoryId)) {
        fetch(`get_subcategories.php?category_id=${encodeURIComponent(categoryId)}`)
            .then(response => response.json())
            .then(data => {
                if (!Array.isArray(data) || data.length === 0) {
                    setSubcategoryMessage(subcategorySelect, 'No hay subcategorías', true);
                } else {
                    data.forEach(subcategory => {
                        if (!subcategory || !validId(subcategory.id)) return;
                        const option = document.createElement('option');
                        option.value = subcategory.id;
                        option.textContent = typeof subcategory.name === 'string' ? subcategory.name : '';
                        subcategorySelect.appendChild(option);
                    });
                }
            });
    }
});

function setSubcategoryMessage(select, message, disabled = false) {
    const option = document.createElement('option');
    option.value = '';
    option.textContent = message;
    option.disabled = disabled;
    select.replaceChildren(option);
}

function parseEditData(button) {
    try {
        const product = JSON.parse(button.dataset.product || '');
        const images = JSON.parse(button.dataset.images || '[]');
        if (!product || !validId(product.id) || !validId(product.category_id) || !Array.isArray(images)) return null;
        return {
            product,
            images: images.filter(image => image && validId(image.id)).map(image => ({
                id: String(image.id),
                image_path: safeProductImagePath(image.image_path),
                is_main: Number(image.is_main) === 1
            }))
        };
    } catch {
        return null;
    }
}

// Modal de edición: cargar subcategorías dinámicamente
function editProduct(product, images) {
    if (!product || !validId(product.id) || !validId(product.category_id)) return;
    document.getElementById('edit_id').value = product.id;
    document.getElementById('edit_name').value = typeof product.name === 'string' ? product.name : '';
    document.getElementById('edit_description').value = typeof product.description === 'string' ? product.description : '';
    document.getElementById('edit_price').value = product.price;
    document.getElementById('edit_price_sale').value = product.price_sale || '';
    document.getElementById('edit_is_active').checked = Number(product.is_active) === 1;
    document.getElementById('edit_category_id').value = product.category_id;
    document.getElementById('edit_destacados').value = product.destacados || 0;

    // Cargar subcategorías para la categoría seleccionada
    const subcategorySelect = document.getElementById('edit_subcategory_id');
    setSubcategoryMessage(subcategorySelect, 'Seleccionar');

    if (validId(product.category_id)) {
        fetch(`get_subcategories.php?category_id=${encodeURIComponent(product.category_id)}`)
            .then(response => response.json())
            .then(data => {
                if (!Array.isArray(data) || data.length === 0) {
                    setSubcategoryMessage(subcategorySelect, 'No hay subcategorías');
                } else {
                    data.forEach(subcategory => {
                        if (!subcategory || !validId(subcategory.id)) return;
                        const option = document.createElement('option');
                        option.value = subcategory.id;
                        option.textContent = typeof subcategory.name === 'string' ? subcategory.name : '';
                        if (subcategory.id == product.subcategory_id) {
                            option.selected = true;
                        }
                        subcategorySelect.appendChild(option);
                    });
                }
            })
            .catch(error => {
                console.error('Error loading subcategories:', error);
                setSubcategoryMessage(subcategorySelect, 'Error al cargar');
            });
    }

    // Mostrar imágenes actuales
    const imagesContainer = document.getElementById('current_images_container');
    const content = [];
    if (Array.isArray(images) && images.length > 0) {
        images.forEach(image => {
            if (!image || !validId(image.id)) return;
            const imageDiv = document.createElement('div');
            imageDiv.className = 'image-container';
            const thumbnail = document.createElement('img');
            thumbnail.src = safeProductImagePath(image.image_path);
            thumbnail.alt = 'Imagen del producto';
            thumbnail.className = `image-thumbnail${Number(image.is_main) === 1 ? ' main-image' : ''}`;
            thumbnail.style.cssText = 'width: 80px; height: 80px;';
            const checkContainer = document.createElement('div');
            checkContainer.className = 'form-check mt-2 text-center';
            const mainImageInput = document.createElement('input');
            mainImageInput.className = 'form-check-input';
            mainImageInput.type = 'radio';
            mainImageInput.name = 'main_image';
            mainImageInput.value = String(image.id);
            mainImageInput.checked = Number(image.is_main) === 1;
            const mainImageLabel = document.createElement('label');
            mainImageLabel.className = 'form-check-label';
            mainImageLabel.textContent = 'Principal';
            checkContainer.append(mainImageInput, mainImageLabel);
            const deleteButton = document.createElement('button');
            deleteButton.type = 'button';
            deleteButton.className = 'delete-image-btn';
            deleteButton.textContent = '×';
            deleteButton.setAttribute('aria-label', 'Eliminar imagen');
            deleteButton.addEventListener('click', () => deleteImage(image.id, deleteButton));
            imageDiv.append(thumbnail, checkContainer, deleteButton);
            content.push(imageDiv);
        });
    }
    if (content.length === 0) {
        const emptyMessage = document.createElement('p');
        emptyMessage.className = 'text-muted';
        emptyMessage.textContent = 'No hay imágenes';
        content.push(emptyMessage);
    }
    imagesContainer.replaceChildren(...content);

    // Mostrar modal
    const editModal = new bootstrap.Modal(document.getElementById('editModal'));
    editModal.show();
}

// Eliminar imagen en el modal de edición
function deleteImage(imageId, button) {
    if (!validId(imageId) || !(button instanceof HTMLElement)) return;
    if (confirm('¿Eliminar esta imagen?')) {
        fetch('delete_image.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': '<?= csrf_token() ?>',
            },
            body: new URLSearchParams({image_id: String(imageId)}).toString()
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                button.closest('.image-container').remove();
            } else {
                alert('Error al eliminar la imagen');
            }
        });
    }
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.edit-product-btn').forEach(button => {
        button.addEventListener('click', () => {
            const data = parseEditData(button);
            if (data) editProduct(data.product, data.images);
        });
    });
    document.querySelectorAll('.delete-product-form').forEach(form => {
        form.addEventListener('submit', event => {
            if (!confirm('¿Eliminar este producto?')) event.preventDefault();
        });
    });
});

// Funcion para busqueda de productos
function normalize(str) {
    return str.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase();
}

document.getElementById('searchAdminProducts').addEventListener('input', function(e) {
    const searchTerm = normalize(e.target.value);
    document.querySelectorAll('#products-table tr').forEach(function(row) {
        const nameCell = row.querySelector('.product-name');
        if (nameCell) {
            const productName = normalize(nameCell.textContent);
            row.style.display = productName.includes(searchTerm) ? '' : 'none';
        }
    });
});
</script>
</body>
<?php require_once 'components/footer.php'; ?>
</html>