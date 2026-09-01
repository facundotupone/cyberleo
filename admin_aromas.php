<?php
require_once 'includes/auth_check.php';
require_once 'includes/config.php';
require_once 'includes/db.php';

// Crear tabla aroma_subcat si no existe
$pdo->query("CREATE TABLE IF NOT EXISTS aroma_subcat (
    id INT AUTO_INCREMENT PRIMARY KEY,
    aroma_id INT NOT NULL,
    subcat_id INT NOT NULL,
    FOREIGN KEY (aroma_id) REFERENCES aromas(id) ON DELETE CASCADE,
    FOREIGN KEY (subcat_id) REFERENCES subcategories(id) ON DELETE CASCADE
)");

$message = '';

// Procesar formulario para agregar aroma
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nombre']) && !isset($_POST['edit_id'])) {
    $nombre = trim($_POST['nombre']);
    if ($nombre !== '') {
        $stmt = $pdo->prepare("INSERT INTO aromas (nombre) VALUES (?)");
        $stmt->execute([$nombre]);
        $message = 'Aroma agregado exitosamente.';
    }
}

// Procesar edición de aroma
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_id'])) {
    $edit_id = intval($_POST['edit_id']);
    $nombre = trim($_POST['nombre']);
    if ($nombre !== '') {
        $stmt = $pdo->prepare("UPDATE aromas SET nombre = ? WHERE id = ?");
        $stmt->execute([$nombre, $edit_id]);
        $message = 'Aroma editado exitosamente.';
    }
}

// Eliminar aroma
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $stmt = $pdo->prepare("DELETE FROM aromas WHERE id = ?");
    $stmt->execute([$id]);
    $message = 'Aroma eliminado.';
}

// Guardar asociación aroma-subcategoría
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['asociar_aroma'])) {
    $aroma_id = intval($_POST['asociar_aroma']);
    $subcats = isset($_POST['subcats']) ? $_POST['subcats'] : [];

    // Eliminar asociaciones previas
    $pdo->prepare("DELETE FROM aroma_subcat WHERE aroma_id = ?")->execute([$aroma_id]);
    // Insertar nuevas
    if (!empty($subcats)) {
        $stmtInsert = $pdo->prepare("INSERT INTO aroma_subcat (aroma_id, subcat_id) VALUES (?, ?)");
        foreach ($subcats as $subcat_id) {
            $stmtInsert->execute([$aroma_id, $subcat_id]);
        }
    }
    $message = 'Asociaciones guardadas.';
}

// Obtener todos los aromas
$stmt = $pdo->query("SELECT id, nombre FROM aromas ORDER BY nombre");
$aromas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener todas las subcategorías
$stmtSub = $pdo->query("SELECT s.id, s.name, c.name AS category_name FROM subcategories s LEFT JOIN categories c ON s.category_id = c.id ORDER BY c.name, s.name");
$subcategories = $stmtSub->fetchAll(PDO::FETCH_ASSOC);

// Obtener asociaciones actuales
$aromaSubcats = [];
$stmtAsoc = $pdo->query("SELECT aroma_id, subcat_id FROM aroma_subcat");
foreach ($stmtAsoc->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $aromaSubcats[$row['aroma_id']][] = $row['subcat_id'];
}

// Para edición de aroma
$editAroma = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    $stmt = $pdo->prepare("SELECT * FROM aromas WHERE id = ?");
    $stmt->execute([$edit_id]);
    $editAroma = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Administrar Aromas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-light bg-light mb-4">
        <div class="container">
            <a class="navbar-brand fw-bold" href="#">Admin Rincon de Freya</a>
            <div class="collapse navbar-collapse">
                <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
                    <li class="nav-item"><a class="nav-link" href="admin_products.php">Productos</a></li>
                    <li class="nav-item"><a class="nav-link" href="admin_categories.php">Categorías</a></li>
                    <li class="nav-item"><a class="nav-link active" href="admin_aromas.php">Aromas</a></li>
                </ul>
            </div>
        </div>
    </nav>
    <div class="container my-4">
        <h1>Administrar Aromas</h1>
        <?php if ($message): ?>
            <div class="alert alert-info"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <!-- Formulario agregar/editar aroma -->
        <form method="POST" class="mb-4">
            <div class="input-group">
                <input type="text" name="nombre" class="form-control" placeholder="Nuevo aroma" required value="<?= $editAroma ? htmlspecialchars($editAroma['nombre']) : '' ?>">
                <?php if ($editAroma): ?>
                    <input type="hidden" name="edit_id" value="<?= $editAroma['id'] ?>">
                    <button type="submit" class="btn btn-warning">Editar Aroma</button>
                    <a href="admin_aromas.php" class="btn btn-secondary">Cancelar</a>
                <?php else: ?>
                    <button type="submit" class="btn btn-primary">Agregar Aroma</button>
                <?php endif; ?>
            </div>
        </form>

        <table class="table table-bordered">
            <thead>
                <tr>
                    
                    <th>Nombre</th>
                    <th>Subcategorías asociadas</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($aromas as $aroma): ?>
                <tr>
                    
                    <td><?= htmlspecialchars($aroma['nombre']) ?></td>
                    <td>
                        <form method="POST" class="d-flex flex-wrap align-items-center gap-2">
                            <input type="hidden" name="asociar_aroma" value="<?= $aroma['id'] ?>">
                            <select name="subcats[]" class="form-select form-select-sm" multiple style="min-width:200px;max-width:350px;">
                                <?php foreach ($subcategories as $subcat): ?>
                                    <option value="<?= $subcat['id'] ?>"
                                        <?= isset($aromaSubcats[$aroma['id']]) && in_array($subcat['id'], $aromaSubcats[$aroma['id']]) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($subcat['category_name']) ?> &raquo; <?= htmlspecialchars($subcat['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-success btn-sm">Guardar</button>
                        </form>
                    </td>
                    <td>
                        <a href="?edit=<?= $aroma['id'] ?>" class="btn btn-sm btn-warning">Editar</a>
                        <a href="?delete=<?= $aroma['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('¿Eliminar este aroma?')">Eliminar</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</body>
<?php require_once 'components/footer.php'; ?>
</html>