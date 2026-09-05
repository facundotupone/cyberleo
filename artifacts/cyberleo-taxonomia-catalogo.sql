-- CyberLeo — taxonomía de catálogo (10 categorías / 69 subcategorías)
-- MySQL/MariaDB (Hostinger). Idempotente. Transaccional.
-- Sin IDs fijos. Sin DROP/TRUNCATE. No modifica products/orders/stock/precios/imágenes.
-- Revisar INSTRUCCIONES-TAXONOMIA.md antes de ejecutar.

SET NAMES utf8mb4;
START TRANSACTION;

-- Conflicto: Notebooks + Notebooks y PC
SET @cyberleo_tax_conflict := (
  SELECT COUNT(*) FROM categories c1
  WHERE c1.name = 'Notebooks'
    AND EXISTS (SELECT 1 FROM categories c2 WHERE c2.name = 'Notebooks y PC')
);
SET @cyberleo_tax_sql := IF(
  @cyberleo_tax_conflict > 0,
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''Conflicto taxonomia: nombre legado y canonico coexisten''',
  'SELECT 1'
);
PREPARE cyberleo_tax_stmt FROM @cyberleo_tax_sql;
EXECUTE cyberleo_tax_stmt;
DEALLOCATE PREPARE cyberleo_tax_stmt;

-- Renombrar legado conservando ID
UPDATE categories
SET name = 'Notebooks y PC', icon = 'bi-laptop'
WHERE name = 'Notebooks'
  AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM categories WHERE name = 'Notebooks y PC') x);

-- Conflicto: Componentes + Componentes y almacenamiento
SET @cyberleo_tax_conflict := (
  SELECT COUNT(*) FROM categories c1
  WHERE c1.name = 'Componentes'
    AND EXISTS (SELECT 1 FROM categories c2 WHERE c2.name = 'Componentes y almacenamiento')
);
SET @cyberleo_tax_sql := IF(
  @cyberleo_tax_conflict > 0,
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''Conflicto taxonomia: nombre legado y canonico coexisten''',
  'SELECT 1'
);
PREPARE cyberleo_tax_stmt FROM @cyberleo_tax_sql;
EXECUTE cyberleo_tax_stmt;
DEALLOCATE PREPARE cyberleo_tax_stmt;

-- Renombrar legado conservando ID
UPDATE categories
SET name = 'Componentes y almacenamiento', icon = 'bi-cpu'
WHERE name = 'Componentes'
  AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM categories WHERE name = 'Componentes y almacenamiento') x);

-- Insertar categorías canónicas faltantes + asegurar icono
INSERT INTO categories (name, icon)
SELECT 'Notebooks y PC', 'bi-laptop'
WHERE NOT EXISTS (SELECT 1 FROM categories WHERE name = 'Notebooks y PC');
UPDATE categories SET icon = 'bi-laptop' WHERE name = 'Notebooks y PC';

INSERT INTO categories (name, icon)
SELECT 'Componentes y almacenamiento', 'bi-cpu'
WHERE NOT EXISTS (SELECT 1 FROM categories WHERE name = 'Componentes y almacenamiento');
UPDATE categories SET icon = 'bi-cpu' WHERE name = 'Componentes y almacenamiento';

INSERT INTO categories (name, icon)
SELECT 'Carga y energía', 'bi-lightning-charge'
WHERE NOT EXISTS (SELECT 1 FROM categories WHERE name = 'Carga y energía');
UPDATE categories SET icon = 'bi-lightning-charge' WHERE name = 'Carga y energía';

INSERT INTO categories (name, icon)
SELECT 'Cables y conectividad', 'bi-usb-plug'
WHERE NOT EXISTS (SELECT 1 FROM categories WHERE name = 'Cables y conectividad');
UPDATE categories SET icon = 'bi-usb-plug' WHERE name = 'Cables y conectividad';

INSERT INTO categories (name, icon)
SELECT 'Audio', 'bi-headphones'
WHERE NOT EXISTS (SELECT 1 FROM categories WHERE name = 'Audio');
UPDATE categories SET icon = 'bi-headphones' WHERE name = 'Audio';

INSERT INTO categories (name, icon)
SELECT 'Periféricos', 'bi-keyboard'
WHERE NOT EXISTS (SELECT 1 FROM categories WHERE name = 'Periféricos');
UPDATE categories SET icon = 'bi-keyboard' WHERE name = 'Periféricos';

INSERT INTO categories (name, icon)
SELECT 'Gaming', 'bi-controller'
WHERE NOT EXISTS (SELECT 1 FROM categories WHERE name = 'Gaming');
UPDATE categories SET icon = 'bi-controller' WHERE name = 'Gaming';

INSERT INTO categories (name, icon)
SELECT 'Impresión y oficina', 'bi-printer'
WHERE NOT EXISTS (SELECT 1 FROM categories WHERE name = 'Impresión y oficina');
UPDATE categories SET icon = 'bi-printer' WHERE name = 'Impresión y oficina';

INSERT INTO categories (name, icon)
SELECT 'Iluminación y multimedia', 'bi-lightbulb'
WHERE NOT EXISTS (SELECT 1 FROM categories WHERE name = 'Iluminación y multimedia');
UPDATE categories SET icon = 'bi-lightbulb' WHERE name = 'Iluminación y multimedia';

INSERT INTO categories (name, icon)
SELECT 'Soportes, fundas y movilidad', 'bi-phone'
WHERE NOT EXISTS (SELECT 1 FROM categories WHERE name = 'Soportes, fundas y movilidad');
UPDATE categories SET icon = 'bi-phone' WHERE name = 'Soportes, fundas y movilidad';

-- Subcategorías (por nombre de categoría, sin IDs fijos)
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Notebooks'
FROM categories c
WHERE c.name = 'Notebooks y PC'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Notebooks'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Computadoras'
FROM categories c
WHERE c.name = 'Notebooks y PC'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Computadoras'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Monitores'
FROM categories c
WHERE c.name = 'Notebooks y PC'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Monitores'
  );

INSERT INTO subcategories (category_id, name)
SELECT c.id, 'SSD y discos rígidos'
FROM categories c
WHERE c.name = 'Componentes y almacenamiento'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'SSD y discos rígidos'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Discos externos'
FROM categories c
WHERE c.name = 'Componentes y almacenamiento'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Discos externos'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Pendrives'
FROM categories c
WHERE c.name = 'Componentes y almacenamiento'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Pendrives'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Tarjetas de memoria'
FROM categories c
WHERE c.name = 'Componentes y almacenamiento'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Tarjetas de memoria'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Fuentes ATX'
FROM categories c
WHERE c.name = 'Componentes y almacenamiento'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Fuentes ATX'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Refrigeración y pasta térmica'
FROM categories c
WHERE c.name = 'Componentes y almacenamiento'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Refrigeración y pasta térmica'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Limpieza y mantenimiento'
FROM categories c
WHERE c.name = 'Componentes y almacenamiento'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Limpieza y mantenimiento'
  );

INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Cargadores para notebooks'
FROM categories c
WHERE c.name = 'Carga y energía'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Cargadores para notebooks'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Cargadores de pared'
FROM categories c
WHERE c.name = 'Carga y energía'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Cargadores de pared'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Estaciones de carga'
FROM categories c
WHERE c.name = 'Carga y energía'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Estaciones de carga'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Cargadores para auto'
FROM categories c
WHERE c.name = 'Carga y energía'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Cargadores para auto'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Power banks'
FROM categories c
WHERE c.name = 'Carga y energía'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Power banks'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Protección eléctrica'
FROM categories c
WHERE c.name = 'Carga y energía'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Protección eléctrica'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Pilas y cargadores'
FROM categories c
WHERE c.name = 'Carga y energía'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Pilas y cargadores'
  );

INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Cables USB y USB-C'
FROM categories c
WHERE c.name = 'Cables y conectividad'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Cables USB y USB-C'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Cables HDMI'
FROM categories c
WHERE c.name = 'Cables y conectividad'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Cables HDMI'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Cables DisplayPort'
FROM categories c
WHERE c.name = 'Cables y conectividad'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Cables DisplayPort'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Cables VGA y DVI'
FROM categories c
WHERE c.name = 'Cables y conectividad'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Cables VGA y DVI'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Cables de red'
FROM categories c
WHERE c.name = 'Cables y conectividad'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Cables de red'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Cables de audio'
FROM categories c
WHERE c.name = 'Cables y conectividad'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Cables de audio'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Adaptadores y convertidores'
FROM categories c
WHERE c.name = 'Cables y conectividad'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Adaptadores y convertidores'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Hubs USB y lectores de memoria'
FROM categories c
WHERE c.name = 'Cables y conectividad'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Hubs USB y lectores de memoria'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Adaptadores Wi-Fi USB'
FROM categories c
WHERE c.name = 'Cables y conectividad'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Adaptadores Wi-Fi USB'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Routers, switches y extensores'
FROM categories c
WHERE c.name = 'Cables y conectividad'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Routers, switches y extensores'
  );

INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Auriculares Bluetooth'
FROM categories c
WHERE c.name = 'Audio'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Auriculares Bluetooth'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Auriculares con cable'
FROM categories c
WHERE c.name = 'Audio'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Auriculares con cable'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Parlantes Bluetooth'
FROM categories c
WHERE c.name = 'Audio'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Parlantes Bluetooth'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Parlantes con cable'
FROM categories c
WHERE c.name = 'Audio'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Parlantes con cable'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Parlantes de 12 pulgadas'
FROM categories c
WHERE c.name = 'Audio'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Parlantes de 12 pulgadas'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Micrófonos y streaming'
FROM categories c
WHERE c.name = 'Audio'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Micrófonos y streaming'
  );

INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Teclados'
FROM categories c
WHERE c.name = 'Periféricos'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Teclados'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Teclados numéricos'
FROM categories c
WHERE c.name = 'Periféricos'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Teclados numéricos'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Combos teclado y mouse'
FROM categories c
WHERE c.name = 'Periféricos'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Combos teclado y mouse'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Mouse'
FROM categories c
WHERE c.name = 'Periféricos'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Mouse'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Joysticks'
FROM categories c
WHERE c.name = 'Periféricos'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Joysticks'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Mouse pads'
FROM categories c
WHERE c.name = 'Periféricos'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Mouse pads'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Lápices ópticos'
FROM categories c
WHERE c.name = 'Periféricos'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Lápices ópticos'
  );

INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Combos gamer'
FROM categories c
WHERE c.name = 'Gaming'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Combos gamer'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Mouse pads gamer y RGB'
FROM categories c
WHERE c.name = 'Gaming'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Mouse pads gamer y RGB'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Consolas retro'
FROM categories c
WHERE c.name = 'Gaming'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Consolas retro'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Sillas gamer'
FROM categories c
WHERE c.name = 'Gaming'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Sillas gamer'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Escritorios gamer'
FROM categories c
WHERE c.name = 'Gaming'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Escritorios gamer'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Gabinetes gamer'
FROM categories c
WHERE c.name = 'Gaming'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Gabinetes gamer'
  );

INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Impresoras'
FROM categories c
WHERE c.name = 'Impresión y oficina'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Impresoras'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Cartuchos'
FROM categories c
WHERE c.name = 'Impresión y oficina'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Cartuchos'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Tintas'
FROM categories c
WHERE c.name = 'Impresión y oficina'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Tintas'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Tóner'
FROM categories c
WHERE c.name = 'Impresión y oficina'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Tóner'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Resmas y papel'
FROM categories c
WHERE c.name = 'Impresión y oficina'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Resmas y papel'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Calculadoras'
FROM categories c
WHERE c.name = 'Impresión y oficina'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Calculadoras'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'CD y DVD'
FROM categories c
WHERE c.name = 'Impresión y oficina'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'CD y DVD'
  );

INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Lámparas'
FROM categories c
WHERE c.name = 'Iluminación y multimedia'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Lámparas'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Proyectores astronauta'
FROM categories c
WHERE c.name = 'Iluminación y multimedia'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Proyectores astronauta'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Aros de luz'
FROM categories c
WHERE c.name = 'Iluminación y multimedia'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Aros de luz'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Tiras LED'
FROM categories c
WHERE c.name = 'Iluminación y multimedia'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Tiras LED'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'TV Stick'
FROM categories c
WHERE c.name = 'Iluminación y multimedia'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'TV Stick'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Controles universales'
FROM categories c
WHERE c.name = 'Iluminación y multimedia'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Controles universales'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Radios y teléfonos'
FROM categories c
WHERE c.name = 'Iluminación y multimedia'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Radios y teléfonos'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Timbres inalámbricos'
FROM categories c
WHERE c.name = 'Iluminación y multimedia'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Timbres inalámbricos'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Punteros láser'
FROM categories c
WHERE c.name = 'Iluminación y multimedia'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Punteros láser'
  );

INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Soportes para celular'
FROM categories c
WHERE c.name = 'Soportes, fundas y movilidad'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Soportes para celular'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Soportes para tablet'
FROM categories c
WHERE c.name = 'Soportes, fundas y movilidad'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Soportes para tablet'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Bases para notebook'
FROM categories c
WHERE c.name = 'Soportes, fundas y movilidad'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Bases para notebook'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Soportes para TV'
FROM categories c
WHERE c.name = 'Soportes, fundas y movilidad'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Soportes para TV'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Fundas para tablet'
FROM categories c
WHERE c.name = 'Soportes, fundas y movilidad'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Fundas para tablet'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Mochilas'
FROM categories c
WHERE c.name = 'Soportes, fundas y movilidad'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Mochilas'
  );
INSERT INTO subcategories (category_id, name)
SELECT c.id, 'Maletines'
FROM categories c
WHERE c.name = 'Soportes, fundas y movilidad'
  AND NOT EXISTS (
    SELECT 1 FROM subcategories s
    WHERE s.category_id = c.id AND s.name = 'Maletines'
  );

-- Resumen final
SELECT
  c.name AS categoria,
  c.icon AS icono,
  (SELECT COUNT(*) FROM subcategories s WHERE s.category_id = c.id) AS subcategorias,
  (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS productos
FROM categories c
ORDER BY c.name;

COMMIT;
