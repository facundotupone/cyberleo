<?php
/**
 * Featured products section fragment for the homepage.
 * Expects: $featured_products, $images_by_product, $themeSettings
 */
if (($themeSettings['show_featured_products'] ?? '1') !== '1') {
    return;
}
?>
        <h2 id="productos-destacados" class="text-center mb-3 mt-2 h4 fw-bold">Productos Destacados</h2>
        <section class="row g-4" aria-label="Productos destacados">
        <?php if (empty($featured_products)): ?>
        <div class="col-12">
            <div class="alert alert-info">
                No hay productos destacados disponibles.
            </div>
        </div>
        <?php else: ?>
        <?php foreach ($featured_products as $product):
            $images = isset($images_by_product[$product['id']]) ? $images_by_product[$product['id']] : [];
            if (empty($images) && !empty($product['image'])) {
                $images = [$product['image']];
            }
        ?>
        <div class="col-md-4">
            <div class="card product-card h-100">
                <div class="product-media">
                    <?php if (!empty($product['price_sale']) && $product['price_sale'] > 0): ?>
                        <div class="position-absolute top-0 start-0 bg-danger text-white px-2 py-1 rounded-end" style="z-index: 10; font-size: 0.75em; font-weight: bold;">
                            LIQUIDACIÓN
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($images)): ?>
                        <?php if (count($images) > 1): ?>
                            <div id="carousel-<?= $product['id'] ?>" class="carousel slide product-carousel w-100 h-100" data-bs-ride="carousel">
                                <div class="carousel-inner h-100">
                                    <?php foreach($images as $index => $image): ?>
                                        <div class="carousel-item h-100 <?= $index === 0 ? 'active' : '' ?>">
                                            <img src="<?= htmlspecialchars($image) ?>"
                                                 class="d-block w-100"
                                                 alt="<?= htmlspecialchars($product['name']) ?>"
                                                 loading="lazy">
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <button class="carousel-control-prev" type="button" data-bs-target="#carousel-<?= $product['id'] ?>" data-bs-slide="prev">
                                    <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                                    <span class="visually-hidden">Anterior</span>
                                </button>
                                <button class="carousel-control-next" type="button" data-bs-target="#carousel-<?= $product['id'] ?>" data-bs-slide="next">
                                    <span class="carousel-control-next-icon" aria-hidden="true"></span>
                                    <span class="visually-hidden">Siguiente</span>
                                </button>
                            </div>
                        <?php else: ?>
                            <img src="<?= htmlspecialchars($images[0]) ?>"
                                 class="single-product-image"
                                 alt="<?= htmlspecialchars($product['name']) ?>"
                                 loading="lazy">
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="product-image-empty" aria-hidden="true">
                            <i class="bi bi-cpu"></i>
                            <span>Sin imagen</span>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <div class="mb-2">
                        <a href="category.php?id=<?= $product['category_id'] ?>" class="text-decoration-none">
                            <span class="badge bg-warning text-dark">
                                Ver más de <?= htmlspecialchars($product['category_name']) ?>
                            </span>
                        </a>
                    </div>

                    <h5 class="card-title"><?php echo htmlspecialchars($product['name']); ?></h5>
                    <div class="description-container">
                        <?php
                        $full_description = htmlspecialchars($product['description']);
                        $short_description = mb_substr($full_description, 0, 200);
                        $has_more = strlen($full_description) > 200;
                        ?>
                       <p class="card-text">
                            <span class="short-description" style="white-space: pre-line;"><?php echo $short_description; ?></span>
                            <span class="ellipsis"><?php echo $has_more ? '...' : ''; ?></span>
                            <span class="full-description" style="display: none; white-space: pre-line;"><?php echo $full_description; ?></span>
                            <?php if ($has_more): ?>
                            <button class="btn btn-link p-0 ver-mas" onclick="toggleDescription(this)" data-showing-full="false">Ver más</button>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mt-auto pt-2 gap-2 flex-wrap">
                        <div class="d-flex align-items-center flex-wrap">
                            <?php if (!empty($product['price_sale']) && $product['price_sale'] > 0): ?>
                                <div class="d-flex flex-column me-2">
                                    <span class="price-old"><?php echo format_price($product['price']); ?></span>
                                    <span class="price-sale"><?php echo format_price($product['price_sale']); ?></span>
                                </div>
                            <?php else: ?>
                                <span class="price me-2"><?php echo format_price($product['price']); ?></span>
                            <?php endif; ?>
                            <small class="text-muted stock-display" data-product-id="<?php echo $product['id']; ?>">
                                (Stock: <?php echo $product['stock']; ?>)
                            </small>
                        </div>
                        <button class="btn btn-primary btn-sm add-to-cart"
                            data-product-id="<?php echo $product['id']; ?>"
                            data-product-name="<?php echo htmlspecialchars($product['name']); ?>"
                            data-product-price="<?php echo (!empty($product['price_sale']) && $product['price_sale'] > 0) ? $product['price_sale'] : $product['price']; ?>"
                            data-product-stock="<?php echo $product['stock']; ?>"
                            data-product-stock-original="<?php echo $product['stock']; ?>"
                            <?php echo ($product['stock'] <= 0) ? 'disabled' : ''; ?>>
                            <i class="bi bi-cart-plus"></i>
                            <?php echo ($product['stock'] <= 0) ? 'Sin stock' : 'Agregar al carrito'; ?>
                        </button>
                    </div>
                    <div class="mt-3">
                        <div class="d-flex justify-content-center gap-2">
                            <?php
                                $shareUrl = SITE_URL . "/category.php?id={$product['category_id']}&product_id={$product['id']}";
                                $shareText = "¡Mirá este producto! " . htmlspecialchars($product['name']);
                            ?>
                            <a href="https://wa.me/?text=<?= urlencode($shareText . ' ' . $shareUrl) ?>"
                            target="_blank" title="Compartir por WhatsApp" class="btn btn-success btn-whatsapp share-whatsapp btn-sm rounded-circle" style="width: 40px; height: 40px; display: flex; align-items: center; justify-content: center;">
                                <i class="bi bi-whatsapp"></i>
                            </a>
                            <a href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode($shareUrl) ?>"
                            target="_blank" title="Compartir en Facebook" class="btn btn-primary btn-sm rounded-circle" style="width: 40px; height: 40px; display: flex; align-items: center; justify-content: center;">
                                <i class="bi bi-facebook"></i>
                            </a>
                            <a href="#" onclick="navigator.clipboard.writeText('<?= $shareUrl ?>');
                               const toast = document.createElement('div');
                               toast.className = 'alert alert-success position-fixed top-0 start-50 translate-middle-x mt-3';
                               toast.style.zIndex = '9999';
                               toast.innerHTML = 'Link copiado al portapapeles';
                               document.body.appendChild(toast);
                               setTimeout(() => toast.remove(), 2000);
                               return false;"
                            title="Copiar link para Instagram" class="btn btn-gradient btn-sm rounded-circle" style="width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; background: linear-gradient(45deg, #f09433 0%,#e6683c 25%,#dc2743 50%,#cc2366 75%,#bc1888 100%); color: white; border: none;">
                                <i class="bi bi-instagram"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
        </section>
