-- Runs once, against an empty mysql data volume.
-- Passwords must match docker/.env. mysql_native_password is required because
-- PHP 7.2's mysqlnd cannot negotiate caching_sha2_password.

CREATE DATABASE IF NOT EXISTS orderdb
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'order_user'@'%'
  IDENTIFIED WITH mysql_native_password BY 'order_pass';
GRANT ALL PRIVILEGES ON orderdb.* TO 'order_user'@'%';

USE orderdb;

CREATE TABLE IF NOT EXISTS customers (
  id          BIGINT       NOT NULL AUTO_INCREMENT,
  name        VARCHAR(120) NOT NULL,
  email       VARCHAR(160) NOT NULL,
  tier        VARCHAR(20)  NOT NULL DEFAULT 'STANDARD',
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_customers_email (email)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS orders (
  id            BIGINT         NOT NULL AUTO_INCREMENT,
  order_ref     VARCHAR(40)    NOT NULL,
  customer_id   BIGINT         NOT NULL,
  sku           VARCHAR(40)    NOT NULL,
  quantity      INT            NOT NULL,
  unit_price    DECIMAL(10, 2) NOT NULL,
  total_amount  DECIMAL(10, 2) NOT NULL,
  status        VARCHAR(20)    NOT NULL,
  created_at    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_orders_ref (order_ref),
  KEY idx_orders_customer (customer_id),
  CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
) ENGINE=InnoDB;

INSERT INTO customers (id, name, email, tier) VALUES
  (1, 'Ayesha Rahman',  'ayesha.rahman@example.com',  'GOLD'),
  (2, 'Tanvir Hasan',   'tanvir.hasan@example.com',   'STANDARD'),
  (3, 'Nusrat Jahan',   'nusrat.jahan@example.com',   'PLATINUM')
ON DUPLICATE KEY UPDATE name = VALUES(name);

CREATE DATABASE IF NOT EXISTS inventorydb
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'inventory_user'@'%'
  IDENTIFIED WITH mysql_native_password BY 'inventory_pass';
GRANT ALL PRIVILEGES ON inventorydb.* TO 'inventory_user'@'%';

USE inventorydb;

CREATE TABLE IF NOT EXISTS inventory (
  sku            VARCHAR(40)    NOT NULL,
  product_name   VARCHAR(160)   NOT NULL,
  unit_price     DECIMAL(10, 2) NOT NULL,
  available_qty  INT            NOT NULL,
  reserved_qty   INT            NOT NULL DEFAULT 0,
  warehouse      VARCHAR(40)    NOT NULL DEFAULT 'DHK-01',
  updated_at     TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP
                                ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (sku)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reservations (
  id          BIGINT      NOT NULL AUTO_INCREMENT,
  sku         VARCHAR(40) NOT NULL,
  order_ref   VARCHAR(40) NOT NULL,
  quantity    INT         NOT NULL,
  created_at  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_reservations_sku (sku),
  KEY idx_reservations_order (order_ref)
) ENGINE=InnoDB;

-- SKU-1004 is seeded low so an oversized order produces a failure trace.
INSERT INTO inventory (sku, product_name, unit_price, available_qty, warehouse) VALUES
  ('SKU-1001', 'Mechanical Keyboard 87-key', 89.00,  120, 'DHK-01'),
  ('SKU-1002', 'USB-C Docking Station',     149.50,   45, 'DHK-01'),
  ('SKU-1003', '27-inch 4K Monitor',        329.99,   18, 'CTG-02'),
  ('SKU-1004', 'Noise Cancelling Headset',  199.00,    3, 'CTG-02'),
  ('SKU-1005', 'Ergonomic Mouse',            45.25,  260, 'DHK-01')
ON DUPLICATE KEY UPDATE product_name = VALUES(product_name);

FLUSH PRIVILEGES;
