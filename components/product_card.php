<?php
declare(strict_types=1);

/**
 * Shared product card.
 *
 * Expected:
 * - array $product
 * - list<string> $images (raw paths; filtered here)
 * - array $catalogDisplay (resolved)
 * - string $cardContext ('featured'|'catalog')
 * - ?string $categoryName (for featured badge)
 */
require_once __DIR__ . '/../includes/catalog_display.php';
require_once __DIR__ . '/../includes/images.php';

if (!isset($product) || !is_array($product)) {
    return;
}
if (!isset($catalogDisplay) || !is_array($catalogDisplay)) {
    $catalogDisplay = catalog_display_default_settings();
}
$cardContext = ($cardContext ?? 'catalog') === 'featured' ? 'featured' : 'catalog';
$images = catalog_safe_product_images(is_array($images ?? null) ? $images : []);
$categoryName = isset($categoryName) ? (string) $categoryName : (string) ($product['category_name'] ?? '');

$productId = (int) ($product['id'] ?? 0);
$productName = (string) ($product['name'] ?? '');
$description = (string) ($product['description'] ?? '');
$stock = (int) ($product['stock'] ?? 0);
$price = (float) ($product['price'] ?? 0);
$priceSale = isset($product['price_sale']) && $product['price_sale'] !== null && $product['price_sale'] !== ''
    ? (float) $product['price_sale']
    : 0.0;
$hasSale = $priceSale > 0;
$effectivePrice = $hasSale ? $priceSale : $price;
$categoryId = (int) ($product['category_id'] ?? 0);

$descMode = $catalogDisplay['product_description_mode'] ?? 'expandable';
$descLen = (int) ($catalogDisplay['product_description_length'] ?? 200);
if (!in_array($descLen, [100, 160, 200, 300], true)) {
    $descLen = 200;
}
$shortPlain = mb_substr($description, 0, $descLen);
$hasMore = mb_strlen($description) > $descLen;

$showBadge = $cardContext === 'featured'
    && ($catalogDisplay['product_show_category_badge'] ?? '1') === '1'
    && $categoryName !== ''
    && $categoryId > 0;
$showStock = ($catalogDisplay['product_show_stock'] ?? '1') === '1';
$showSaleBadge = ($catalogDisplay['product_show_sale_badge'] ?? '1') === '1' && $hasSale;
$showOldPrice = ($catalogDisplay['product_show_old_price'] ?? '1') === '1' && $hasSale;
$showShare = ($catalogDisplay['product_show_share_buttons'] ?? '1') === '1'
    && (
        ($catalogDisplay['product_share_whatsapp'] ?? '1') === '1'
        || ($catalogDisplay['product_share_facebook'] ?? '1') === '1'
        || ($catalogDisplay['product_share_copy'] ?? '1') === '1'
    );
$addText = (string) ($catalogDisplay['product_add_button_text'] ?? 'Agregar al carrito');
$oosText = (string) ($catalogDisplay['product_out_of_stock_text'] ?? 'Sin stock');
$saleBadgeText = (string) ($catalogDisplay['product_sale_badge_text'] ?? 'LIQUIDACIÓN');
$cardClass = catalog_card_classes($catalogDisplay);

$shareUrl = SITE_URL . '/category.php?id=' . $categoryId . '&product_id=' . $productId;
$shareText = '¡Mirá este producto! ' . $productName;
$carouselId = 'carousel-' . $cardContext . '-' . $productId;
?>
<div class="col product-grid-item">
    <div class="<?= htmlspecialchars($cardClass, ENT_QUOTES, 'UTF-8') ?>">
        <div class="product-media">
            <?php if ($showSaleBadge): ?>
                <div class="product-sale-badge" aria-label="<?= htmlspecialchars($saleBadgeText) ?>">
                    <?= htmlspecialchars($saleBadgeText) ?>
                </div>
            <?php endif; ?>
            <?php if ($images !== []): ?>
                <?php if (count($images) > 1): ?>
                    <div id="<?= htmlspecialchars($carouselId, ENT_QUOTES, 'UTF-8') ?>" class="carousel slide product-carousel w-100 h-100" data-bs-ride="carousel">
                        <div class="carousel-inner h-100">
                            <?php foreach ($images as $index => $image): ?>
                                <div class="carousel-item h-100<?= $index === 0 ? ' active' : '' ?>">
                                    <img src="<?= htmlspecialchars($image, ENT_QUOTES, 'UTF-8') ?>"
                                         class="d-block w-100 product-card-image"
                                         alt="<?= htmlspecialchars($productName) ?>"
                                         loading="lazy"
                                         decoding="async">
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button class="carousel-control-prev" type="button" data-bs-target="#<?= htmlspecialchars($carouselId, ENT_QUOTES, 'UTF-8') ?>" data-bs-slide="prev">
                            <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                            <span class="visually-hidden">Anterior</span>
                        </button>
                        <button class="carousel-control-next" type="button" data-bs-target="#<?= htmlspecialchars($carouselId, ENT_QUOTES, 'UTF-8') ?>" data-bs-slide="next">
                            <span class="carousel-control-next-icon" aria-hidden="true"></span>
                            <span class="visually-hidden">Siguiente</span>
                        </button>
                    </div>
                <?php else: ?>
                    <img src="<?= htmlspecialchars($images[0], ENT_QUOTES, 'UTF-8') ?>"
                         class="single-product-image product-card-image"
                         alt="<?= htmlspecialchars($productName) ?>"
                         loading="lazy"
                         decoding="async">
                <?php endif; ?>
            <?php else: ?>
                <div class="product-image-empty" aria-hidden="true">
                    <i class="bi bi-cpu"></i>
                    <span>Sin imagen</span>
                </div>
            <?php endif; ?>
        </div>
        <div class="card-body d-flex flex-column">
            <?php if ($showBadge): ?>
                <div class="mb-2">
                    <a href="category.php?id=<?= $categoryId ?>" class="text-decoration-none">
                        <span class="badge bg-warning text-dark">
                            Ver más de <?= htmlspecialchars($categoryName) ?>
                        </span>
                    </a>
                </div>
            <?php endif; ?>

            <h3 class="card-title h5"><?= htmlspecialchars($productName) ?></h3>

            <?php if ($descMode !== 'hidden' && $description !== ''): ?>
                <div class="description-container">
                    <p class="card-text">
                        <span class="short-description" style="white-space: pre-line;"><?= htmlspecialchars($shortPlain) ?></span>
                        <?php if ($descMode === 'expandable' && $hasMore): ?>
                            <span class="ellipsis">...</span>
                            <span class="full-description" hidden style="white-space: pre-line;"><?= htmlspecialchars($description) ?></span>
                            <button type="button" class="btn btn-link p-0 ver-mas" data-showing-full="false">Ver más</button>
                        <?php elseif ($hasMore): ?>
                            <span class="ellipsis">...</span>
                        <?php endif; ?>
                    </p>
                </div>
            <?php endif; ?>

            <div class="d-flex justify-content-between align-items-center mt-auto pt-2 gap-2 flex-wrap">
                <div class="d-flex align-items-center flex-wrap product-price-block">
                    <?php if ($hasSale): ?>
                        <div class="d-flex flex-column me-2">
                            <?php if ($showOldPrice): ?>
                                <span class="price-old"><?= format_price($price) ?></span>
                            <?php endif; ?>
                            <span class="price-sale"><?= format_price($priceSale) ?></span>
                        </div>
                    <?php else: ?>
                        <span class="price me-2"><?= format_price($price) ?></span>
                    <?php endif; ?>
                    <?php if ($showStock): ?>
                        <small class="text-muted stock-display" data-product-id="<?= $productId ?>">
                            (Stock: <?= $stock ?>)
                        </small>
                    <?php else: ?>
                        <span class="stock-display visually-hidden" data-product-id="<?= $productId ?>" aria-hidden="true"><?= $stock ?></span>
                    <?php endif; ?>
                </div>
                <button type="button"
                    class="btn btn-primary btn-sm add-to-cart"
                    data-product-id="<?= $productId ?>"
                    data-product-name="<?= htmlspecialchars($productName, ENT_QUOTES, 'UTF-8') ?>"
                    data-product-price="<?= htmlspecialchars((string) $effectivePrice, ENT_QUOTES, 'UTF-8') ?>"
                    data-product-stock="<?= $stock ?>"
                    data-product-stock-original="<?= $stock ?>"
                    data-add-text="<?= htmlspecialchars($addText, ENT_QUOTES, 'UTF-8') ?>"
                    data-oos-text="<?= htmlspecialchars($oosText, ENT_QUOTES, 'UTF-8') ?>"
                    <?= $stock <= 0 ? 'disabled' : '' ?>>
                    <i class="bi bi-cart-plus" aria-hidden="true"></i>
                    <span class="add-to-cart-label"><?= htmlspecialchars($stock <= 0 ? $oosText : $addText) ?></span>
                </button>
            </div>

            <?php if ($showShare): ?>
                <div class="mt-3 product-share">
                    <div class="d-flex justify-content-center gap-2">
                        <?php if (($catalogDisplay['product_share_whatsapp'] ?? '1') === '1'): ?>
                            <a href="https://wa.me/?text=<?= urlencode($shareText . ' ' . $shareUrl) ?>"
                               target="_blank" rel="noopener"
                               title="Compartir por WhatsApp"
                               aria-label="Compartir por WhatsApp"
                               class="btn btn-success btn-whatsapp share-whatsapp btn-sm rounded-circle product-share-btn">
                                <i class="bi bi-whatsapp" aria-hidden="true"></i>
                            </a>
                        <?php endif; ?>
                        <?php if (($catalogDisplay['product_share_facebook'] ?? '1') === '1'): ?>
                            <a href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode($shareUrl) ?>"
                               target="_blank" rel="noopener"
                               title="Compartir en Facebook"
                               aria-label="Compartir en Facebook"
                               class="btn btn-primary btn-sm rounded-circle product-share-btn">
                                <i class="bi bi-facebook" aria-hidden="true"></i>
                            </a>
                        <?php endif; ?>
                        <?php if (($catalogDisplay['product_share_copy'] ?? '1') === '1'): ?>
                            <button type="button"
                                class="btn btn-secondary btn-sm rounded-circle product-share-btn share-copy-link"
                                data-share-url="<?= htmlspecialchars($shareUrl, ENT_QUOTES, 'UTF-8') ?>"
                                title="Copiar enlace"
                                aria-label="Copiar enlace">
                                <i class="bi bi-link-45deg" aria-hidden="true"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
