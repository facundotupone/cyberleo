<?php if (!isset($storeSettings)) { $storeSettings = get_store_settings(); } ?>
<footer class="footer pt-4 pb-3" role="contentinfo">
    <div class="container">
        <div class="text-center footer-brand">
            <a href="index.php" title="<?= htmlspecialchars($storeSettings['store_name']) ?>">
                <img
                    src="assets/images/brand/cyberleo-logo.png"
                    alt="CyberLeo"
                    class="brand-logo brand-logo-sm"
                    width="150"
                    height="42"
                    decoding="async"
                >
            </a>
        </div>
        <div class="row justify-content-center mb-3">
            <div class="col-lg-10">
                <div class="d-flex flex-column flex-md-row gap-3 justify-content-center align-items-stretch">
                    <div class="footer-banner footer-ig d-flex align-items-center gap-2 flex-grow-1">
                        <span class="footer-icon" aria-hidden="true"><i class="bi bi-instagram"></i></span>
                        <span>
                            Tecnología, periféricos y soluciones para tu equipo.
                            <?php if (!empty($storeSettings['instagram_url'])): ?>
                                <br><a href="<?= htmlspecialchars($storeSettings['instagram_url']) ?>" target="_blank" rel="noopener">Seguinos en Instagram</a>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="footer-banner footer-wa d-flex align-items-center gap-2 flex-grow-1">
                        <span class="footer-icon" aria-hidden="true"><i class="bi bi-whatsapp"></i></span>
                        <span>
                            Escribinos por cualquier consulta o para coordinar tu compra:<br>
                            <a href="https://wa.me/<?= htmlspecialchars($storeSettings['whatsapp_number']) ?>?text=<?= urlencode('Hola ' . $storeSettings['store_name'] . ', quisiera hacer una consulta.') ?>" target="_blank" rel="noopener">Contactar por WhatsApp</a>
                        </span>
                    </div>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col text-center">
                <small class="footer-copyright">&copy; <?php echo date('Y'); ?> <?= htmlspecialchars($storeSettings['store_name']) ?>. Todos los derechos reservados.</small>
            </div>
        </div>
    </div>
</footer>
