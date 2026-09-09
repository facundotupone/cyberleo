-- CyberLeo: set catalog grid to 4 columns on existing installs.
-- Idempotent. Does NOT modify products, categories, orders, or auth.
-- Review and run manually in phpMyAdmin / mysql client. Do not auto-apply.

INSERT INTO store_settings (setting_key, setting_value)
VALUES
  ('featured_columns', '4'),
  ('catalog_columns', '4')
ON DUPLICATE KEY UPDATE
  setting_value = VALUES(setting_value);
