<?php
require_once 'includes/auth_check.php';
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';
require_once 'includes/security.php';

$message = '';
$categories = get_categories();

// Procesar el formulario de categoría y subcategoría
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'add_category') {
            $name = trim($_POST['name']);
            $icon = trim($_POST['icon']);
            if (!preg_match('/^bi bi-[a-z0-9-]{1,60}$/', $icon)) {
                $message = 'Icono inválido.';
            } elseif (!empty($name)) {
                $stmt = $pdo->prepare("INSERT INTO categories (name, icon) VALUES (?, ?)");
                if ($stmt->execute([$name, $icon])) {
                    $message = 'Categoría agregada exitosamente.';
                } else {
                    $message = 'Error al agregar la categoría.';
                }
            }
        } elseif ($_POST['action'] === 'add_subcategory') {
            $name = trim($_POST['subcategory_name']);
            $category_id = intval($_POST['parent_category_id']);
            if (!empty($name) && $category_id > 0) {
                $stmt = $pdo->prepare("INSERT INTO subcategories (name, category_id) VALUES (?, ?)");
                if ($stmt->execute([$name, $category_id])) {
                    $message = 'Subcategoría agregada exitosamente.';
                } else {
                    $message = 'Error al agregar la subcategoría.';
                }
            }
        } elseif ($_POST['action'] === 'delete_category' && isset($_POST['id'])) {
            $stmt = $pdo->prepare("DELETE FROM categories WHERE id = ?");
            if ($stmt->execute([$_POST['id']])) {
                $message = 'Categoría eliminada exitosamente.';
            } else {
                $message = 'Error al eliminar la categoría.';
            }
        } elseif ($_POST['action'] === 'delete_subcategory' && isset($_POST['id'])) {
            $stmt = $pdo->prepare("DELETE FROM subcategories WHERE id = ?");
            if ($stmt->execute([$_POST['id']])) {
                $message = 'Subcategoría eliminada exitosamente.';
            } else {
                $message = 'Error al eliminar la subcategoría.';
            }
        }
    }
}

// Obtener todas las categorías y subcategorías
$categories = get_categories();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administrar Categorías - <?= htmlspecialchars(STORE_NAME) ?></title>
    <!-- Bootstrap + Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">

    <!-- MISMO ESTILO QUE admin_products.php -->
    <style>
        body {
            background: linear-gradient(135deg, #f3f8fc 0%, #dceaf8 100%);
            min-height: 100vh;
        }

        .navbar {
            background: linear-gradient(135deg, #0057b8 0%, #071a33 100%);
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .navbar-brand {
            font-weight: 700;
            font-size: 1.5rem;
            letter-spacing: 1px;
        }

        .nav-link.active {
            color: #fff !important;
            border-bottom: 3px solid #00aeef;
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
            background: linear-gradient(135deg, #0057b8 0%, #071a33 100%) !important;
            color: #fff;
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
            border-color: #0057b8;
            box-shadow: 0 0 0 0.2rem rgba(0, 87, 184, 0.15);
        }

        .btn {
            border-radius: 10px;
            font-weight: 600;
            padding: 0.75rem 1.5rem;
            transition: all 0.3s ease;
            border: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, #0057b8 0%, #071a33 100%);
            box-shadow: 0 4px 15px rgba(0, 87, 184, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 87, 184, 0.4);
        }

        .btn-danger {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a6f 100%);
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(255, 107, 107, 0.3);
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
            border-color: #0057b8;
            background: rgba(0, 87, 184, 0.05);
            transform: translateX(5px);
        }

        /* Ajustes mobile para que se vea cómodo */
        @media (max-width: 767px) {
            .row > .col-md-6 {
                width: 100%;
                max-width: 100%;
                flex: 0 0 100%;
            }
            .card.shadow-sm.mb-4 {
                margin-bottom: 1.5rem !important;
            }
            .card-body .form-control, .card-body .form-select {
                font-size: 1.05rem;
                padding: 0.75rem 1rem;
            }
            h1, h2 {
                text-align: center;
            }
        }
    </style>
    <style>
    .icon-option {
        transition: .2s;
        border-radius: 10px;
    }

    .icon-option:hover {
        background: #e9ecef;
        transform: scale(1.15);
    }

    #iconList i {
        pointer-events: none;
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
                        <a class="nav-link " href="admin_products.php">
                            <i class="bi bi-box-seam"></i> Productos
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="admin_categories.php">
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
                    <li class="nav-item">
                        <a class="nav-link" href="admin_system.php">
                            <i class="bi bi-heartbeat"></i> Sistema
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container my-4">
        <h1>Administrar Categorías</h1>

        <?php if ($message): ?>
        <div class="alert alert-info"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <div class="row">
            <div class="col-md-6">
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-warning text-white">
                        <h2 class="h5 mb-0">Agregar Nueva Categoría</h2>
                    </div>
                    <div class="card-body">
                        <form method="POST" class="mb-4">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="add_category">
                            <div class="mb-3">
                                <label for="name" class="form-label">Nombre de la Categoría</label>
                                <input type="text" class="form-control" id="name" name="name" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Icono de categoría</label>

                                <div class="input-group">
                                    <input type="text" class="form-control" id="iconInput" name="icon" placeholder="bi bi-star" required>
                                    <button type="button" class="btn btn-outline-secondary" id="openIconPicker">
                                        <i class="bi bi-grid"></i>
                                    </button>
                                </div>

                                <div class="mt-2" id="iconPreview" style="font-size: 2rem;"></div>

                                <small class="text-muted">
                                    Elegí un icono o escribí manualmente. Ej: <code>bi bi-heart</code>
                                </small>
                            </div>


                            <button type="submit" class="btn btn-primary mb-3">Agregar Categoría</button>
                        </form>




                        <hr>
                        <h2 class="h5 mb-3">Agregar Nueva Subcategoría</h2>
                        <form method="POST" class="mb-4">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="add_subcategory">
                            <div class="mb-3">
                                <label for="parent_category_id" class="form-label">Categoría Padre</label>
                                <select class="form-control" id="parent_category_id" name="parent_category_id" required>
                                    <option value="">Seleccionar categoría</option>
                                    <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo $category['id']; ?>">
                                        <?php echo htmlspecialchars($category['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="subcategory_name" class="form-label">Nombre de la Subcategoría</label>
                                <input type="text" class="form-control" id="subcategory_name" name="subcategory_name" required>
                            </div>
                            <button type="submit" class="btn btn-primary">Agregar Subcategoría</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-warning text-white">
                        <h2 class="h5 mb-0">Categorías y Subcategorías Existentes</h2>
                    </div>

                    <div class="list-group">
                        <?php foreach ($categories as $category):
                            $subcategories = get_subcategories($category['id']);
                        ?>
                        <div class="list-group-item">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <?php if (!empty($category['icon'])): ?>
                                    <i class="<?php echo htmlspecialchars($category['icon']); ?>"></i>
                                    <?php endif; ?>
                                    <strong><?php echo htmlspecialchars($category['name']); ?></strong>
                                </div>
                                <form method="POST" class="d-inline">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="action" value="delete_category">
                                    <input type="hidden" name="id" value="<?php echo $category['id']; ?>">
                                    <button type="submit" class="btn btn-danger btn-sm"
                                            onclick="return confirm('¿Estás seguro de eliminar esta categoría y todas sus subcategorías?')">
                                        Eliminar
                                    </button>
                                </form>
                            </div>
                            <?php if (!empty($subcategories)): ?>
                            <div class="ms-4 mt-2">
                                <?php foreach ($subcategories as $subcategory): ?>
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span><?php echo htmlspecialchars($subcategory['name']); ?></span>
                                    <form method="POST" class="d-inline">
                                        <?= csrf_input() ?>
                                        <input type="hidden" name="action" value="delete_subcategory">
                                        <input type="hidden" name="id" value="<?php echo $subcategory['id']; ?>">
                                        <button type="submit" class="btn btn-danger btn-sm"
                                                onclick="return confirm('¿Estás seguro de eliminar esta subcategoría?')">
                                            Eliminar
                                        </button>
                                    </form>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
     <!-- MODAL ICONO -->

    <div class="modal fade" id="iconModal" tabindex="-1">
      <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">

          <div class="modal-header">
            <h5 class="modal-title">Seleccionar Icono</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>

          <div class="modal-body">

            <input type="text" class="form-control mb-3" id="iconSearch" placeholder="Buscar icono...">

            <div class="row row-cols-6 g-3" id="iconList"></div>

          </div>

        </div>
      </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* ============================================================================
   ÍCONOS DISPONIBLES PARA SELECCIÓN

   Esta es la lista de íconos que se mostrarán en el modal.
   Podés agregar, quitar o modificar según tus necesidades.

   El formato debe ser: "bi bi-nombre-icono" (Bootstrap Icons)
============================================================================ */

const icons = [

    // Aros
    "bi bi-circle",
    "bi bi-circle-fill",
    "bi bi-record-circle",
    "bi bi-record-fill",

    // Piercings
    "bi bi-dot",
    "bi bi-dot-circle",
    "bi bi-circle-square",
    "bi bi-record-btn",

    // Ear Cuffs
    "bi bi-ear",
    "bi bi-ear-fill",
    "bi bi-earbuds",
    "bi bi-chevron-compact-right",
    "bi bi-chevron-compact-left",

    // Pulseras
    "bi bi-dash-circle",
    "bi bi-dash-circle-fill",
    "bi bi-dash-square",
    "bi bi-record",
    "bi bi-record-fill",

    // Joyería / Elegancia
    "bi bi-gem",
    "bi bi-diamond",
    "bi bi-diamond-fill",
    "bi bi-star",
    "bi bi-star-fill",
    "bi bi-heart",
    "bi bi-heart-fill",
    "bi bi-flower1",
    "bi bi-flower2",
    "bi bi-flower3",
    "bi bi-moon",
    "bi bi-moon-stars",

    // Accesorios / moda
    "bi bi-handbag",
    "bi bi-bag",
    "bi bi-bag-heart",
    "bi bi-hearts",
    "bi bi-suit-heart",

    // Modernos / minimal
    "bi bi-brightness-high",
    "bi bi-brightness-alt-high",
    "bi bi-circle-half",
    "bi bi-circle-half-fill",
    "bi bi-sun",
    "bi bi-sun-fill",

    // Jóvenes / trendy
    "bi bi-lightning-charge",
    "bi bi-lightning-fill",
    "bi bi-stars",

];



/* ============================================================================
   FUNCIÓN: renderIcons()

   Dibuja la lista de íconos en el modal.
   Si se pasa un texto "filter", solo muestra íconos que lo contengan.

   Params:
     - filter (string): texto de búsqueda (opcional)
============================================================================ */

function renderIcons(filter = "") {
    const list = document.getElementById("iconList");

    // Limpiar contenido previo
    list.replaceChildren();

    // Filtrar e iterar íconos
    icons
        .filter(icon => icon.toLowerCase().includes(filter.toLowerCase()))
        .forEach(icon => {
            // Crear celda del ícono
            const div = document.createElement("div");
            div.className = "col icon-option";
            div.setAttribute("data-icon", icon);
            const iconNode = document.createElement('i');
            iconNode.className = icon;
            div.appendChild(iconNode);

            // Insertar en el DOM
            list.appendChild(div);
        });
}

// Render inicial (sin filtro)
renderIcons();


/* ============================================================================
   EVENTO: Búsqueda en tiempo real

   Cada vez que el usuario escribe en el input de búsqueda del modal,
   volvemos a renderizar los íconos con el filtro correspondiente.
============================================================================ */

document.getElementById("iconSearch").addEventListener("input", function(){
    renderIcons(this.value);
});


/* ============================================================================
   EVENTO: Abrir modal de selección

   Abre el modal de íconos cuando el usuario presiona el botón
   junto al input del formulario de categoría.
============================================================================ */

document.getElementById("openIconPicker").addEventListener("click", function(){
    const modal = new bootstrap.Modal(document.getElementById("iconModal"));
    modal.show();
});


/* ============================================================================
   EVENTO: Selección de ícono

   Listener global (delegado):
   Si el usuario hace clic en un ícono del modal:
     - Se asigna la clase del ícono al input
     - Se actualiza la previsualización
     - Se cierra el modal

   Nota: se usa delegación para evitar listeners por elemento.
============================================================================ */

document.addEventListener("click", function(e){

    // Verificar si clickeaste un icono
    const el = e.target.closest(".icon-option");
    if (!el) return;

    // Obtener clase del ícono seleccionado
    const icon = el.getAttribute("data-icon");

    // Setear input
    document.getElementById("iconInput").value = icon;

    // Mostrar preview
    const preview = document.getElementById("iconPreview");
    preview.replaceChildren();
    const previewIcon = document.createElement('i'); previewIcon.className = icon; preview.appendChild(previewIcon);

    // Cerrar modal
    bootstrap.Modal.getInstance(document.getElementById("iconModal")).hide();
});


/* ============================================================================
   EVENTO: Previsualización en tiempo real

   Cuando el usuario escribe manualmente un valor en el input,
   mostramos el ícono si es válido.
============================================================================ */

document.getElementById("iconInput").addEventListener("input", function(){
    const val = this.value.trim();
    const preview = document.getElementById("iconPreview");
    preview.replaceChildren();
    if (/^bi bi-[a-z0-9-]{1,60}$/.test(val)) { const node = document.createElement('i'); node.className = val; preview.appendChild(node); }
});

</script>
<?php require_once 'components/footer.php'; ?>
</body>

</html>
