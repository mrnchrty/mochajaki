CREATE DATABASE IF NOT EXISTS mochajaki CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE mochajaki;
SET FOREIGN_KEY_CHECKS=0;
DROP TABLE IF EXISTS payment_submissions;
DROP TABLE IF EXISTS payments;
DROP TABLE IF EXISTS order_items;
DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS users;
SET FOREIGN_KEY_CHECKS=1;

CREATE TABLE users (
 id INT AUTO_INCREMENT PRIMARY KEY, full_name VARCHAR(120) NOT NULL,
 email VARCHAR(190) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL,
 role ENUM('customer','admin') NOT NULL DEFAULT 'customer',
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE products (
 id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(150) NOT NULL,
 category VARCHAR(80) NOT NULL, description TEXT,
 price DECIMAL(10,2) NOT NULL, image_url VARCHAR(500),
 is_available TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_products_category(category), INDEX idx_products_available(is_available)
) ENGINE=InnoDB;
CREATE TABLE orders (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, order_code VARCHAR(40) NOT NULL UNIQUE,
 customer_id INT NOT NULL, order_type ENUM('Pickup','Delivery') NOT NULL DEFAULT 'Pickup',
 delivery_address VARCHAR(500), subtotal DECIMAL(10,2) NOT NULL,
 delivery_fee DECIMAL(10,2) NOT NULL DEFAULT 0, total DECIMAL(10,2) NOT NULL,
 status ENUM('Order Placed','Confirmed','Preparing','Ready for Pickup','Out for Delivery','Completed','Cancelled') NOT NULL DEFAULT 'Order Placed',
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(customer_id) REFERENCES users(id), INDEX idx_orders_customer(customer_id), INDEX idx_orders_status(status)
) ENGINE=InnoDB;
CREATE TABLE order_items (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, order_id BIGINT NOT NULL, product_id INT NULL,
 product_name VARCHAR(150) NOT NULL, quantity INT NOT NULL,
 unit_price DECIMAL(10,2) NOT NULL, line_total DECIMAL(10,2) NOT NULL,
 customizations JSON NULL,
 FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
 FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE payments (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, order_id BIGINT NOT NULL,
 method ENUM('Cash','GCash','Maya') NOT NULL, amount DECIMAL(10,2) NOT NULL,
 status ENUM('Pending','Pending Verification','Paid','Failed','Refunded') NOT NULL DEFAULT 'Pending',
 gateway_reference VARCHAR(190), payment_screenshot VARCHAR(500), verified_by INT NULL,
 verified_at DATETIME NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
 FOREIGN KEY(verified_by) REFERENCES users(id) ON DELETE SET NULL,
 INDEX idx_payments_status(status)
) ENGINE=InnoDB;

INSERT INTO users(full_name,email,password_hash,role) VALUES
('Mochajaki Administrator','admin@mochajaki.local','$2y$12$93gPWlabb92aK9gxFDWO0uQuDBlrY6Uul/YKLh1W.AALCNo/U5X.O','admin');

INSERT INTO products(name,category,description,price,image_url) VALUES
('B1T1 Burger (CDO Patty)','Meryenda Meal','B1T1 burger using CDO patty',55,'https://images.unsplash.com/photo-1568901346375-23c9450c58cd?auto=format&fit=crop&w=600&q=80'),
('Single Burger (CDO Patty)','Meryenda Meal','Single burger using CDO patty',30,'https://images.unsplash.com/photo-1568901346375-23c9450c58cd?auto=format&fit=crop&w=600&q=80'),
('B1T1 Homemade Patty Burger','Meryenda Meal','B1T1 homemade patty burger',75,'https://images.unsplash.com/photo-1568901346375-23c9450c58cd?auto=format&fit=crop&w=600&q=80'),
('Single Homemade Patty Burger','Meryenda Meal','Single homemade patty burger',40,'https://images.unsplash.com/photo-1568901346375-23c9450c58cd?auto=format&fit=crop&w=600&q=80'),
('Burger Double Homemade Patty','Meryenda Meal','Burger with double homemade patty',65,'https://images.unsplash.com/photo-1568901346375-23c9450c58cd?auto=format&fit=crop&w=600&q=80'),
('Burger with Egg','Meryenda Meal','Burger with egg',55,'https://images.unsplash.com/photo-1568901346375-23c9450c58cd?auto=format&fit=crop&w=600&q=80'),
('Burger with Bacon','Meryenda Meal','Burger with bacon',60,'https://images.unsplash.com/photo-1568901346375-23c9450c58cd?auto=format&fit=crop&w=600&q=80'),
('Burger with Ham','Meryenda Meal','Burger with ham',55,'https://images.unsplash.com/photo-1568901346375-23c9450c58cd?auto=format&fit=crop&w=600&q=80'),
('Chicken Burger','Meryenda Meal','Chicken burger',40,'https://images.unsplash.com/photo-1606755962773-d324e0a13086?auto=format&fit=crop&w=600&q=80'),
('Ham Sandwich','Meryenda Meal','Ham sandwich',30,'https://images.unsplash.com/photo-1528735602780-2552fd46c7af?auto=format&fit=crop&w=600&q=80'),
('Bacon Sandwich','Meryenda Meal','Bacon sandwich',40,'https://images.unsplash.com/photo-1528735602780-2552fd46c7af?auto=format&fit=crop&w=600&q=80'),
('Egg Sandwich','Meryenda Meal','Egg sandwich',25,'https://images.unsplash.com/photo-1528735602780-2552fd46c7af?auto=format&fit=crop&w=600&q=80'),
('Hotdog B1T1 Overload','Hotdog Sandwich','B1T1 overload hotdog sandwich',65,'https://images.unsplash.com/photo-1612392062798-2dbaa8f4b4d7?auto=format&fit=crop&w=600&q=80'),
('Hotdog B1T1 Regular','Hotdog Sandwich','B1T1 regular hotdog sandwich',55,'https://images.unsplash.com/photo-1612392062798-2dbaa8f4b4d7?auto=format&fit=crop&w=600&q=80'),
('Hotdog Single Overload','Hotdog Sandwich','Single overload hotdog sandwich',35,'https://images.unsplash.com/photo-1612392062798-2dbaa8f4b4d7?auto=format&fit=crop&w=600&q=80'),
('Footlong B1T1 Overload','Hotdog Sandwich','B1T1 overload footlong sandwich',85,'https://images.unsplash.com/photo-1612392062798-2dbaa8f4b4d7?auto=format&fit=crop&w=600&q=80'),
('Footlong B1T1 Regular','Hotdog Sandwich','B1T1 regular footlong sandwich',75,'https://images.unsplash.com/photo-1612392062798-2dbaa8f4b4d7?auto=format&fit=crop&w=600&q=80'),
('Footlong Single Overload','Hotdog Sandwich','Single overload footlong sandwich',45,'https://images.unsplash.com/photo-1612392062798-2dbaa8f4b4d7?auto=format&fit=crop&w=600&q=80'),
('Footlong Single Regular','Hotdog Sandwich','Single regular footlong sandwich',40,'https://images.unsplash.com/photo-1612392062798-2dbaa8f4b4d7?auto=format&fit=crop&w=600&q=80'),
('Hungarian B1T1 Overload','Hotdog Sandwich','B1T1 overload Hungarian sandwich',120,'https://images.unsplash.com/photo-1612392062798-2dbaa8f4b4d7?auto=format&fit=crop&w=600&q=80'),
('Hungarian B1T1 Regular','Hotdog Sandwich','B1T1 regular Hungarian sandwich',100,'https://images.unsplash.com/photo-1612392062798-2dbaa8f4b4d7?auto=format&fit=crop&w=600&q=80'),
('Hungarian Single Overload','Hotdog Sandwich','Single overload Hungarian sandwich',65,'https://images.unsplash.com/photo-1612392062798-2dbaa8f4b4d7?auto=format&fit=crop&w=600&q=80'),
('Hungarian Single Regular','Hotdog Sandwich','Single regular Hungarian sandwich',55,'https://images.unsplash.com/photo-1612392062798-2dbaa8f4b4d7?auto=format&fit=crop&w=600&q=80'),
('Silog','Rice Meal','Silog rice meal',45,'https://images.unsplash.com/photo-1512058564366-18510be2db19?auto=format&fit=crop&w=600&q=80'),
('Siomai Rice','Rice Meal','Siomai with rice',45,'https://images.unsplash.com/photo-1512058564366-18510be2db19?auto=format&fit=crop&w=600&q=80'),
('Siomai Silog','Rice Meal','Siomai silog',70,'https://images.unsplash.com/photo-1512058564366-18510be2db19?auto=format&fit=crop&w=600&q=80'),
('Chicken Silog','Rice Meal','Chicken silog',110,'https://images.unsplash.com/photo-1512058564366-18510be2db19?auto=format&fit=crop&w=600&q=80'),
('Pork Silog','Rice Meal','Pork silog',120,'https://images.unsplash.com/photo-1512058564366-18510be2db19?auto=format&fit=crop&w=600&q=80'),
('Spam Silog','Rice Meal','Spam silog',80,'https://images.unsplash.com/photo-1512058564366-18510be2db19?auto=format&fit=crop&w=600&q=80'),
('Bacon Silog','Rice Meal','Bacon silog',80,'https://images.unsplash.com/photo-1512058564366-18510be2db19?auto=format&fit=crop&w=600&q=80'),
('Footlong Silog','Rice Meal','Footlong silog',70,'https://images.unsplash.com/photo-1512058564366-18510be2db19?auto=format&fit=crop&w=600&q=80'),
('Ham Silog','Rice Meal','Ham silog',70,'https://images.unsplash.com/photo-1512058564366-18510be2db19?auto=format&fit=crop&w=600&q=80'),
('Hotdog Silog','Rice Meal','Hotdog silog',80,'https://images.unsplash.com/photo-1512058564366-18510be2db19?auto=format&fit=crop&w=600&q=80'),
('Hungarian Silog','Rice Meal','Hungarian silog',100,'https://images.unsplash.com/photo-1512058564366-18510be2db19?auto=format&fit=crop&w=600&q=80'),
('Java Rice','Rice Meal','Java rice serving',25,'https://images.unsplash.com/photo-1512058564366-18510be2db19?auto=format&fit=crop&w=600&q=80'),
('Plain Rice','Rice Meal','Plain rice serving',20,'https://images.unsplash.com/photo-1512058564366-18510be2db19?auto=format&fit=crop&w=600&q=80'),
('Takoyaki Ham 4 pcs','Takoyaki','Four pieces',40,'https://images.unsplash.com/photo-1607301406259-dfb186e15de8?auto=format&fit=crop&w=600&q=80'),
('Takoyaki Ham 6 pcs','Takoyaki','Six pieces',60,'https://images.unsplash.com/photo-1607301406259-dfb186e15de8?auto=format&fit=crop&w=600&q=80'),
('Takoyaki Spam 4 pcs','Takoyaki','Four pieces',45,'https://images.unsplash.com/photo-1607301406259-dfb186e15de8?auto=format&fit=crop&w=600&q=80'),
('Takoyaki Spam 6 pcs','Takoyaki','Six pieces',65,'https://images.unsplash.com/photo-1607301406259-dfb186e15de8?auto=format&fit=crop&w=600&q=80'),
('Chicken Wings with Rice','Chicken Wings','Choose chicken pieces, flavor, and rice type',108,'https://images.unsplash.com/photo-1527477396000-e27163b481c2?auto=format&fit=crop&w=600&q=80'),
('Buldak Cheese','Buldak','Cheese flavor',89,'https://images.unsplash.com/photo-1569718212165-3a8278d5f624?auto=format&fit=crop&w=600&q=80'),
('Buldak Carbonara','Buldak','Carbonara flavor',89,'https://images.unsplash.com/photo-1569718212165-3a8278d5f624?auto=format&fit=crop&w=600&q=80'),
('Buldak Original','Buldak','Original flavor',80,'https://images.unsplash.com/photo-1569718212165-3a8278d5f624?auto=format&fit=crop&w=600&q=80'),
('Buldak Paluto','Buldak','Paluto',120,'https://images.unsplash.com/photo-1569718212165-3a8278d5f624?auto=format&fit=crop&w=600&q=80'),
('Cheese Stick (9 pcs)','Pica-Pica','Nine pieces',30,'https://images.unsplash.com/photo-1548340748-6d2b7d7da280?auto=format&fit=crop&w=600&q=80'),
('Chili Stick (15 pcs)','Pica-Pica','Fifteen pieces',50,'https://images.unsplash.com/photo-1548340748-6d2b7d7da280?auto=format&fit=crop&w=600&q=80'),
('Fries Medium','Pica-Pica','Medium fries',30,'https://images.unsplash.com/photo-1573080496219-bb080dd4f877?auto=format&fit=crop&w=600&q=80'),
('Fries Large','Pica-Pica','Large fries',50,'https://images.unsplash.com/photo-1573080496219-bb080dd4f877?auto=format&fit=crop&w=600&q=80'),
('Fishball (8 pcs)','Pica-Pica','Eight pieces',10,'https://images.unsplash.com/photo-1548340748-6d2b7d7da280?auto=format&fit=crop&w=600&q=80'),
('Kikam (4 pcs)','Pica-Pica','Four pieces',10,'https://images.unsplash.com/photo-1548340748-6d2b7d7da280?auto=format&fit=crop&w=600&q=80'),
('Kwek-Kwek','Pica-Pica','Kwek-kwek',5,'https://images.unsplash.com/photo-1548340748-6d2b7d7da280?auto=format&fit=crop&w=600&q=80'),
('Fries & Cheese Stick Combo','Finger Lick’n Good','Fries and cheese stick combo',50,'https://images.unsplash.com/photo-1573080496219-bb080dd4f877?auto=format&fit=crop&w=600&q=80'),
('Chicken Poppers','Finger Lick’n Good','Chicken poppers',80,'https://images.unsplash.com/photo-1527477396000-e27163b481c2?auto=format&fit=crop&w=600&q=80'),
('Fries & Chicken Poppers','Finger Lick’n Good','Fries and chicken poppers',100,'https://images.unsplash.com/photo-1573080496219-bb080dd4f877?auto=format&fit=crop&w=600&q=80'),
('Fries, Chili Stick & Chicken Poppers','Finger Lick’n Good','Fries, chili stick, and chicken poppers',130,'https://images.unsplash.com/photo-1573080496219-bb080dd4f877?auto=format&fit=crop&w=600&q=80'),
('Milkshake','Milkshake','Choose size and flavor; optional pearl and nata',40,'https://images.unsplash.com/photo-1572490122747-3968b75cc699?auto=format&fit=crop&w=600&q=80'),
('Fruit Soda','Fruit Soda','Choose size and flavor; optional nata',40,'https://images.unsplash.com/photo-1513558161293-cdaf765edfd4?auto=format&fit=crop&w=600&q=80'),
('Baked Macaroni Small','Pasta','Small serving',50,'https://images.unsplash.com/photo-1473093295043-cdd812d0e601?auto=format&fit=crop&w=600&q=80'),
('Baked Macaroni Big','Pasta','Big serving',70,'https://images.unsplash.com/photo-1473093295043-cdd812d0e601?auto=format&fit=crop&w=600&q=80'),
('Mac & Cheese Small','Pasta','Small serving',50,'https://images.unsplash.com/photo-1473093295043-cdd812d0e601?auto=format&fit=crop&w=600&q=80'),
('Mac & Cheese Big','Pasta','Big serving',70,'https://images.unsplash.com/photo-1473093295043-cdd812d0e601?auto=format&fit=crop&w=600&q=80'),
('Lasagna Small','Pasta','Small serving',50,'https://images.unsplash.com/photo-1473093295043-cdd812d0e601?auto=format&fit=crop&w=600&q=80'),
('Lasagna Big','Pasta','Big serving',70,'https://images.unsplash.com/photo-1473093295043-cdd812d0e601?auto=format&fit=crop&w=600&q=80'),
('Palabok Small','Pasta','Small serving',40,'https://images.unsplash.com/photo-1473093295043-cdd812d0e601?auto=format&fit=crop&w=600&q=80'),
('Palabok Big','Pasta','Big serving',60,'https://images.unsplash.com/photo-1473093295043-cdd812d0e601?auto=format&fit=crop&w=600&q=80'),
('A1 Burger Combo','Combos','Burger with egg, fries or cheese sticks, and 16 oz fruit soda',100,'https://images.unsplash.com/photo-1568901346375-23c9450c58cd?auto=format&fit=crop&w=600&q=80'),
('A2 Burger & Milkshake','Combos','Burger combo with 16 oz milkshake',105,'https://images.unsplash.com/photo-1572490122747-3968b75cc699?auto=format&fit=crop&w=600&q=80'),
('A3 Burger & Coke Float','Combos','Burger combo with 16 oz Coke Float',110,'https://images.unsplash.com/photo-1586816001966-79b736744398?auto=format&fit=crop&w=600&q=80'),
('B1 Hotdog Overload Combo','Combos','Hotdog overload with side and 16 oz fruit soda',85,'https://images.unsplash.com/photo-1612392062798-2dbaa8f4b4d7?auto=format&fit=crop&w=600&q=80'),
('B2 Hotdog & Milkshake','Combos','Hotdog combo with 16 oz milkshake',90,'https://images.unsplash.com/photo-1572490122747-3968b75cc699?auto=format&fit=crop&w=600&q=80'),
('B3 Hotdog & Coke Float','Combos','Hotdog combo with 16 oz Coke Float',90,'https://images.unsplash.com/photo-1586816001966-79b736744398?auto=format&fit=crop&w=600&q=80'),
('C1 Hungarian Overload Combo','Combos','Hungarian overload with side and 16 oz fruit soda',110,'https://images.unsplash.com/photo-1612392062798-2dbaa8f4b4d7?auto=format&fit=crop&w=600&q=80'),
('C2 Hungarian & Milkshake','Combos','Hungarian combo with 16 oz milkshake',115,'https://images.unsplash.com/photo-1572490122747-3968b75cc699?auto=format&fit=crop&w=600&q=80'),
('C3 Hungarian & Coke Float','Combos','Hungarian combo with 16 oz Coke Float',120,'https://images.unsplash.com/photo-1586816001966-79b736744398?auto=format&fit=crop&w=600&q=80'),
('D1 Footlong Overload Combo','Combos','Footlong overload with side and 16 oz fruit soda',95,'https://images.unsplash.com/photo-1612392062798-2dbaa8f4b4d7?auto=format&fit=crop&w=600&q=80'),
('D2 Footlong & Milkshake','Combos','Footlong combo with 16 oz milkshake',100,'https://images.unsplash.com/photo-1572490122747-3968b75cc699?auto=format&fit=crop&w=600&q=80'),
('D3 Footlong & Coke Float','Combos','Footlong combo with 16 oz Coke Float',105,'https://images.unsplash.com/photo-1586816001966-79b736744398?auto=format&fit=crop&w=600&q=80');
