SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE auth_rate_limits;
TRUNCATE TABLE order_rate_limits;
TRUNCATE TABLE order_items;
TRUNCATE TABLE orders;
TRUNCATE TABLE product_images;
TRUNCATE TABLE products;
TRUNCATE TABLE subcategories;
TRUNCATE TABLE categories;
TRUNCATE TABLE users;
TRUNCATE TABLE store_settings;
SET FOREIGN_KEY_CHECKS = 1;

INSERT INTO categories (id, name, icon) VALUES
    (1, 'HTTP fixtures', 'bi-cpu');
INSERT INTO subcategories (id, category_id, name) VALUES
    (1, 1, 'HTTP fixtures');
INSERT INTO users (id, username, password, mail) VALUES
    (1, 'http-admin', '$2y$10$4hCRlvBTSkoJ3mKATmmmteFgd39aJetr2M.XsbKjab.LpwOB6IV02', 'admin-http@example.test');
INSERT INTO products
    (id, name, description, price, price_sale, stock, image, is_active, category_id, subcategory_id, destacados)
VALUES
    (1, 'HTTP order product', 'Order fixture', 125.50, NULL, 8, NULL, 1, 1, 1, 1),
    (2, '<script>globalThis.xssExecuted=1;document.title="XSS_EXECUTED"</script>', '"><img src=x onerror=globalThis.xssExecuted=2>', 20.00, NULL, 2, NULL, 1, 1, 1, 2);
INSERT INTO store_settings (setting_key, setting_value) VALUES
    ('store_name', 'HTTP Test Store'),
    ('whatsapp_number', '5491100000000'),
    ('mail_from', 'store-http@example.test'),
    ('admin_email', 'admin-http@example.test'),
    ('reservation_minutes', '120');
