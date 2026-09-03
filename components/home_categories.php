<?php
/**
 * Categories section fragment for the homepage.
 * Expects: $categories, $all_subcategories, $themeSettings
 */
if (($themeSettings['show_categories'] ?? '1') !== '1') {
    return;
}
?>
        <section id="categorias" class="row mb-1 mt-4" aria-label="Categorías y subcategorías">
            <?php foreach($categories as $category):
                $subcategories = isset($all_subcategories[$category['id']]) ? $all_subcategories[$category['id']] : [];
            ?>
            <div class="col-md-3 mb-3">
                <div class="card h-100 category-card">
                    <div class="card-body text-center">
                        <h3 class="card-title">
                            <i class="bi <?php echo htmlspecialchars($category['icon']); ?> category-icon" aria-hidden="true"></i>
                            <?php echo htmlspecialchars($category['name']); ?>
                        </h3>
                        <div class="subcategories-container mb-3">
                            <?php if (!empty($subcategories)): ?>
                                <?php foreach($subcategories as $subcategory): ?>
                                    <a href="category.php?id=<?php echo $category['id']; ?>&sub=<?php echo $subcategory['id']; ?>"
                                    class="badge text-decoration-none m-1">
                                        <?php echo htmlspecialchars($subcategory['name']); ?>
                                    </a>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="text-muted">No hay subcategorías disponibles</span>
                            <?php endif; ?>
                        </div>
                        <a href="category.php?id=<?php echo $category['id']; ?>" class="btn btn-primary">Ver productos</a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </section>
