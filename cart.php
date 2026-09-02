<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';
require_once 'includes/images.php';
require_once 'includes/checkout_display.php';

$categories = get_categories();
$storeSettings = get_store_settings();
$checkout = resolve_checkout_display_settings($storeSettings);
$paymentMethods = array_values(array_filter(array_map('trim', explode(',', (string) ($storeSettings['payment_methods'] ?? '')))));
$deliveryParsed = parse_checkout_delivery_methods((string) ($checkout['cart_delivery_methods'] ?? ''));
$deliveryMethods = is_array($deliveryParsed) ? $deliveryParsed : [];
$reservationMinutes = max(1, (int) ($storeSettings['reservation_minutes'] ?? 120));
$saleBadgeText = trim((string) ($storeSettings['product_sale_badge_text'] ?? 'LIQUIDACIÓN'));
if ($saleBadgeText === '') {
    $saleBadgeText = 'LIQUIDACIÓN';
}

$stmt = $pdo->prepare('SELECT id, name, price, price_sale, stock, image FROM products');
$stmt->execute();
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($products as &$product) {
    if (!is_safe_product_image_path($product['image'])) {
        $product['image'] = null;
    }
}
unset($product);

$productImages = [];
$stmtImages = $pdo->prepare('SELECT product_id, image_path FROM product_images ORDER BY is_main DESC');
$stmtImages->execute();
foreach ($stmtImages->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (!is_safe_product_image_path($row['image_path'])) {
        continue;
    }
    if (!isset($productImages[$row['product_id']])) {
        $productImages[$row['product_id']] = [];
    }
    $productImages[$row['product_id']][] = $row['image_path'];
}

$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR;
$checkoutBoot = $checkout;
$checkoutBoot['sale_badge_text'] = $saleBadgeText;
$checkoutBoot['reservation_minutes'] = (string) $reservationMinutes;
$checkoutBoot['payment_methods_list'] = $paymentMethods;
$checkoutBoot['delivery_methods_list'] = $deliveryMethods;

$pageClasses = [
    'cart-layout-' . preg_replace('/[^a-z]/', '', (string) $checkout['cart_layout']),
    'cart-image-fit-' . preg_replace('/[^a-z]/', '', (string) $checkout['cart_image_fit']),
    'cart-image-size-' . preg_replace('/[^a-z]/', '', (string) $checkout['cart_image_size']),
];
if ((string) $checkout['cart_summary_sticky'] === '1') {
    $pageClasses[] = 'cart-summary-sticky-enabled';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<?php require_once 'components/head.php'; ?>
</head>
<body>
<?php require_once 'components/nav.php'; ?>

<div class="container my-3 cart-page <?= htmlspecialchars(implode(' ', $pageClasses), ENT_QUOTES, 'UTF-8') ?>">
    <div class="row">
        <div class="col-12">
            <div class="d-flex align-items-center mb-3">
                <i class="bi bi-cart3 fs-3 text-primary me-2" aria-hidden="true"></i>
                <h1 class="cart-page-title"><?= htmlspecialchars((string) $checkout['cart_page_title'], ENT_QUOTES, 'UTF-8') ?></h1>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white border-0">
                    <h2 class="h5 mb-0 text-navy"><?= htmlspecialchars((string) $checkout['cart_items_title'], ENT_QUOTES, 'UTF-8') ?></h2>
                </div>
                <div class="card-body p-0">
                    <div id="cart-items"></div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card shadow-sm border-0 mb-4 cart-summary-card<?= ((string) $checkout['cart_summary_sticky'] === '1') ? ' cart-summary-sticky' : '' ?>">
                <div class="card-header">
                    <h2 class="h5 mb-0"><i class="bi bi-calculator me-2" aria-hidden="true"></i><?= htmlspecialchars((string) $checkout['cart_summary_title'], ENT_QUOTES, 'UTF-8') ?></h2>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h3 class="h4 mb-0"><?= htmlspecialchars((string) $checkout['cart_total_label'], ENT_QUOTES, 'UTF-8') ?></h3>
                        <h3 class="h4 mb-0 text-primary" id="cart-total">$0,00</h3>
                    </div>

                    <?php if ((string) $checkout['cart_show_delivery_info'] === '1'): ?>
                        <div class="bg-light p-3 rounded mb-3 border cart-delivery-block">
                            <h3 class="h6 mb-2"><i class="bi bi-info-circle me-2" aria-hidden="true"></i><?= htmlspecialchars((string) $checkout['cart_delivery_title'], ENT_QUOTES, 'UTF-8') ?></h3>
                            <p class="small mb-0"><?= htmlspecialchars((string) $checkout['cart_delivery_text'], ENT_QUOTES, 'UTF-8') ?></p>
                            <?php if ((string) $checkout['cart_show_delivery_methods'] === '1' && $deliveryMethods !== []): ?>
                                <p class="small mb-1 mt-2"><strong><?= htmlspecialchars((string) $checkout['cart_delivery_methods_title'], ENT_QUOTES, 'UTF-8') ?></strong></p>
                                <ul class="small mb-0 ps-3">
                                    <?php foreach ($deliveryMethods as $method): ?>
                                        <li><?= htmlspecialchars($method, ENT_QUOTES, 'UTF-8') ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ((string) $checkout['cart_show_payment_methods'] === '1'): ?>
                        <div class="mb-3 cart-payment-block">
                            <h3 class="h6"><i class="bi bi-credit-card me-2" aria-hidden="true"></i><?= htmlspecialchars((string) $checkout['cart_payment_title'], ENT_QUOTES, 'UTF-8') ?></h3>
                            <?php if ($paymentMethods !== []): ?>
                                <div class="d-flex flex-wrap gap-2 mb-1">
                                    <?php foreach ($paymentMethods as $method): ?>
                                        <span class="badge bg-info"><?= htmlspecialchars($method, ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <small class="text-muted"><?= htmlspecialchars((string) $checkout['cart_payment_note'], ENT_QUOTES, 'UTF-8') ?></small>
                        </div>
                    <?php endif; ?>

                    <?php if ((string) $checkout['cart_show_reservation_note'] === '1'): ?>
                        <p class="small text-muted mb-3" id="cart-reservation-note">
                            <?= htmlspecialchars(str_replace('{minutes}', (string) $reservationMinutes, (string) $checkout['cart_reservation_text']), ENT_QUOTES, 'UTF-8') ?>
                        </p>
                    <?php endif; ?>

                    <?php if ((string) $checkout['cart_terms_enabled'] === '1' && trim((string) $checkout['cart_terms_text']) !== ''): ?>
                        <div class="mb-3 small cart-terms-block">
                            <span><?= htmlspecialchars((string) $checkout['cart_terms_text'], ENT_QUOTES, 'UTF-8') ?></span>
                            <?php if (trim((string) $checkout['cart_terms_url']) !== ''): ?>
                                <a href="<?= htmlspecialchars((string) $checkout['cart_terms_url'], ENT_QUOTES, 'UTF-8') ?>" rel="noopener noreferrer" target="_blank">Ver más</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="d-grid gap-2">
                        <a href="#" id="whatsapp-order" class="btn btn-success btn-whatsapp btn-lg disabled" aria-disabled="true" tabindex="-1">
                            <i class="bi bi-whatsapp me-2" aria-hidden="true"></i><?= htmlspecialchars((string) $checkout['cart_order_button_text'], ENT_QUOTES, 'UTF-8') ?>
                        </a>
                        <a href="index.php" class="btn btn-primary">
                            <i class="bi bi-arrow-left me-2" aria-hidden="true"></i><?= htmlspecialchars((string) $checkout['cart_continue_button_text'], ENT_QUOTES, 'UTF-8') ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'components/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script type="application/json" id="cart-products-boot"><?= json_encode($products, $jsonFlags) ?></script>
<script type="application/json" id="cart-images-boot"><?= json_encode($productImages, $jsonFlags) ?></script>
<script type="application/json" id="cart-checkout-boot"><?= json_encode($checkoutBoot, $jsonFlags) ?></script>
<script src="assets/js/cart-checkout.js" defer></script>
</body>
</html>
