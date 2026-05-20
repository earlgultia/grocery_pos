-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 20, 2026 at 10:26 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `grocery_pos`
--

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`root`@`localhost` PROCEDURE `GetTopSellingProducts` (IN `p_store_id` INT, IN `p_limit` INT)   BEGIN
    SELECT 
        p.id,
        p.name,
        p.category,
        SUM(ti.quantity) as total_quantity_sold,
        SUM(ti.subtotal) as total_revenue,
        COUNT(DISTINCT ti.transaction_id) as times_sold
    FROM products p
    JOIN transaction_items ti ON p.id = ti.product_id
    JOIN transactions t ON ti.transaction_id = t.id
    WHERE p.store_id = p_store_id AND t.status = 'completed'
    GROUP BY p.id, p.name, p.category
    ORDER BY total_revenue DESC
    LIMIT p_limit;
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Stand-in structure for view `daily_sales_report`
-- (See below for the actual view)
--
CREATE TABLE `daily_sales_report` (
`sale_date` date
,`store_id` bigint(20)
,`total_transactions` bigint(21)
,`total_revenue` decimal(32,2)
,`average_transaction` decimal(14,6)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `expiring_products`
-- (See below for the actual view)
--
CREATE TABLE `expiring_products` (
`store_name` varchar(255)
,`product_name` varchar(255)
,`category` varchar(100)
,`expiration_date` date
,`days_until_expiry` int(7)
,`current_stock` decimal(10,2)
,`unit` varchar(50)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `low_stock_products`
-- (See below for the actual view)
--
CREATE TABLE `low_stock_products` (
`store_name` varchar(255)
,`product_name` varchar(255)
,`category` varchar(100)
,`current_stock` decimal(10,2)
,`low_stock_threshold` int(11)
,`unit` varchar(50)
);

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` bigint(20) NOT NULL,
  `store_id` bigint(20) NOT NULL,
  `name` varchar(255) NOT NULL,
  `category` varchar(100) NOT NULL,
  `unit` varchar(50) NOT NULL,
  `quantity` decimal(10,2) DEFAULT 0.00,
  `price` decimal(10,2) NOT NULL,
  `cost_price` decimal(10,2) DEFAULT NULL,
  `expiration_date` date DEFAULT NULL,
  `supplier` varchar(255) DEFAULT NULL,
  `barcode` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `low_stock_threshold` int(11) DEFAULT 5,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Triggers `products`
--
DELIMITER $$
CREATE TRIGGER `prevent_negative_stock` BEFORE UPDATE ON `products` FOR EACH ROW BEGIN
    IF NEW.quantity < 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Stock quantity cannot be negative';
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `stores`
--

CREATE TABLE `stores` (
  `id` bigint(20) NOT NULL,
  `store_name` varchar(255) NOT NULL,
  `store_email` varchar(255) NOT NULL,
  `phone` varchar(50) NOT NULL,
  `address` text NOT NULL,
  `tax_number` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `stores`
--

INSERT INTO `stores` (`id`, `store_name`, `store_email`, `phone`, `address`, `tax_number`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'Main Street Grocery', 'store@maingrocery.com', '+1234567890', '123 Main Street, Downtown', 'TAX123456', 1, '2026-05-20 00:35:13', '2026-05-20 00:35:13'),
(2, 'Demo Grocery Store', '', '', '', NULL, 1, '2026-05-20 00:59:59', '2026-05-20 00:59:59'),
(5, 'Phil-Rose Store', 'philrosestore@gmail.com', '', '', NULL, 1, '2026-05-20 07:25:44', '2026-05-20 07:25:44');

-- --------------------------------------------------------

--
-- Table structure for table `transactions`
--

CREATE TABLE `transactions` (
  `id` bigint(20) NOT NULL,
  `invoice_number` varchar(100) DEFAULT NULL,
  `store_id` bigint(20) NOT NULL,
  `user_id` bigint(20) NOT NULL,
  `customer_name` varchar(255) DEFAULT 'Walk-in Customer',
  `customer_phone` varchar(50) DEFAULT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  `tax` decimal(10,2) DEFAULT 0.00,
  `discount` decimal(10,2) DEFAULT 0.00,
  `total_amount` decimal(10,2) NOT NULL,
  `payment_received` decimal(10,2) NOT NULL,
  `change_amount` decimal(10,2) DEFAULT 0.00,
  `payment_method` varchar(50) NOT NULL,
  `status` varchar(50) DEFAULT 'completed',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Triggers `transactions`
--
DELIMITER $$
CREATE TRIGGER `generate_invoice_number` BEFORE INSERT ON `transactions` FOR EACH ROW BEGIN
    DECLARE new_invoice VARCHAR(100);
    DECLARE next_number INT;
    
    -- Get the next sequence number
    SELECT COALESCE(MAX(CAST(SUBSTRING(invoice_number, -6) AS UNSIGNED)), 0) + 1 INTO next_number FROM transactions;
    
    -- Generate new invoice number
    SET new_invoice = CONCAT('INV-', DATE_FORMAT(CURDATE(), '%Y%m'), LPAD(next_number, 6, '0'));
    
    -- Set the invoice number
    SET NEW.invoice_number = new_invoice;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `transaction_items`
--

CREATE TABLE `transaction_items` (
  `id` bigint(20) NOT NULL,
  `transaction_id` bigint(20) NOT NULL,
  `product_id` bigint(20) NOT NULL,
  `product_name` varchar(255) NOT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Triggers `transaction_items`
--
DELIMITER $$
CREATE TRIGGER `check_stock_before_insert` BEFORE INSERT ON `transaction_items` FOR EACH ROW BEGIN
    DECLARE current_stock DECIMAL(10,2);
    
    SELECT quantity INTO current_stock FROM products WHERE id = NEW.product_id;
    
    IF current_stock < NEW.quantity THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Insufficient stock available';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `update_product_stock` AFTER INSERT ON `transaction_items` FOR EACH ROW BEGIN
    UPDATE products SET quantity = quantity - NEW.quantity WHERE id = NEW.product_id;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` bigint(20) NOT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` varchar(20) NOT NULL,
  `store_id` bigint(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `password`, `role`, `store_id`, `created_at`, `updated_at`) VALUES
(1, 'System Admin', 'admin@example.com', '$2y$12$1gcwRmPv9Y3eGwkj.9fhPuqL/kvowrMYVrlIG3VcijqOVMSFuGfRe', 'admin', 2, '2026-05-20 00:35:13', '2026-05-20 07:28:47'),
(2, 'Store User', 'store@example.com', '$2y$12$F6qyLtGcDrwgEQsvXVNLBub6oF0C3YdBNQCKcxJtJ4ADVj1zs8vwm', 'store_user', 2, '2026-05-20 00:35:13', '2026-05-20 07:28:46'),
(3, 'Philip Salabe', 'philrosestore@gmail.com', '$2y$12$k0Jt2hxZZhOaTOWDOHFj8eO4iR/P/HDvbmQgyYvRwOpgDft2S9lS.', 'store_user', 5, '2026-05-20 07:25:44', '2026-05-20 07:25:44');

-- --------------------------------------------------------

--
-- Table structure for table `user_logs`
--

CREATE TABLE `user_logs` (
  `id` bigint(20) NOT NULL,
  `user_id` bigint(20) DEFAULT NULL,
  `action` varchar(255) NOT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_logs`
--

INSERT INTO `user_logs` (`id`, `user_id`, `action`, `details`, `ip_address`, `user_agent`, `created_at`) VALUES
(1, 1, 'error', 'Create store user failed: SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry \'\' for key \'store_email\'', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-20 07:24:19'),
(2, 1, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-20 07:26:46'),
(3, 3, 'login', 'User logged in successfully', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-20 07:28:06'),
(4, 3, 'logout', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-20 07:28:46');

-- --------------------------------------------------------

--
-- Structure for view `daily_sales_report`
--
DROP TABLE IF EXISTS `daily_sales_report`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `daily_sales_report`  AS SELECT cast(`transactions`.`created_at` as date) AS `sale_date`, `transactions`.`store_id` AS `store_id`, count(0) AS `total_transactions`, sum(`transactions`.`total_amount`) AS `total_revenue`, avg(`transactions`.`total_amount`) AS `average_transaction` FROM `transactions` WHERE `transactions`.`status` = 'completed' GROUP BY cast(`transactions`.`created_at` as date), `transactions`.`store_id` ;

-- --------------------------------------------------------

--
-- Structure for view `expiring_products`
--
DROP TABLE IF EXISTS `expiring_products`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `expiring_products`  AS SELECT `s`.`store_name` AS `store_name`, `p`.`name` AS `product_name`, `p`.`category` AS `category`, `p`.`expiration_date` AS `expiration_date`, to_days(`p`.`expiration_date`) - to_days(curdate()) AS `days_until_expiry`, `p`.`quantity` AS `current_stock`, `p`.`unit` AS `unit` FROM (`products` `p` join `stores` `s` on(`p`.`store_id` = `s`.`id`)) WHERE `p`.`expiration_date` is not null AND `p`.`expiration_date` <= curdate() + interval 30 day AND `p`.`quantity` > 0 ORDER BY `p`.`expiration_date` ASC ;

-- --------------------------------------------------------

--
-- Structure for view `low_stock_products`
--
DROP TABLE IF EXISTS `low_stock_products`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `low_stock_products`  AS SELECT `s`.`store_name` AS `store_name`, `p`.`name` AS `product_name`, `p`.`category` AS `category`, `p`.`quantity` AS `current_stock`, `p`.`low_stock_threshold` AS `low_stock_threshold`, `p`.`unit` AS `unit` FROM (`products` `p` join `stores` `s` on(`p`.`store_id` = `s`.`id`)) WHERE `p`.`quantity` <= `p`.`low_stock_threshold` ORDER BY `p`.`quantity` ASC ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `barcode` (`barcode`),
  ADD KEY `idx_store_id` (`store_id`),
  ADD KEY `idx_category` (`category`),
  ADD KEY `idx_expiration_date` (`expiration_date`),
  ADD KEY `idx_products_name` (`name`(50)),
  ADD KEY `idx_products_price` (`price`);

--
-- Indexes for table `stores`
--
ALTER TABLE `stores`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `store_email` (`store_email`);

--
-- Indexes for table `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `invoice_number` (`invoice_number`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_store_id` (`store_id`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_transactions_payment_method` (`payment_method`);

--
-- Indexes for table `transaction_items`
--
ALTER TABLE `transaction_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `idx_transaction_id` (`transaction_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `store_id` (`store_id`);

--
-- Indexes for table `user_logs`
--
ALTER TABLE `user_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_user_logs_created_at` (`created_at`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `stores`
--
ALTER TABLE `stores`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `transaction_items`
--
ALTER TABLE `transaction_items`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `user_logs`
--
ALTER TABLE `user_logs`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `products_ibfk_1` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `transactions`
--
ALTER TABLE `transactions`
  ADD CONSTRAINT `transactions_ibfk_1` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `transactions_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `transaction_items`
--
ALTER TABLE `transaction_items`
  ADD CONSTRAINT `transaction_items_ibfk_1` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `transaction_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`);

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_logs`
--
ALTER TABLE `user_logs`
  ADD CONSTRAINT `user_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
