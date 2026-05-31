-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 28, 2026 at 10:39 AM
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
-- Database: `smartstock`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_logs`
--

CREATE TABLE `activity_logs` (
  `id` int(11) NOT NULL,
  `user_name` varchar(150) NOT NULL,
  `branch_name` varchar(150) DEFAULT 'System',
  `action` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `activity_logs`
--

INSERT INTO `activity_logs` (`id`, `user_name`, `branch_name`, `action`, `created_at`) VALUES
(1, 'Super Admin', 'System', 'Created user: Maria Reyes (Staff)', '2026-05-11 01:14:00'),
(2, 'Super Admin', 'System', 'Added branch: RF Chein - SM Branch', '2026-05-10 08:30:00'),
(3, 'John Francis Busel', 'Main Branch', 'Added device: Samsung Galaxy A54', '2026-05-10 06:15:00'),
(4, 'James Mark Jariño', 'Lacson Branch', 'Recorded sale TXN-044', '2026-05-10 03:00:00'),
(5, 'Aljon Obediente', 'SM Branch', 'Updated stock: OPPO A78 → 2 units', '2026-05-09 07:45:00'),
(6, 'Super Admin', 'System', 'Assigned Almarie Ex to Main Branch', '2026-05-09 02:00:00'),
(7, 'Super Admin', 'System', 'Signed in', '2026-05-11 00:31:48'),
(8, 'Super Admin', 'System', 'Signed in', '2026-05-11 00:35:04'),
(9, 'Super Admin', 'System', 'Signed out', '2026-05-11 00:45:55'),
(10, 'John Francis Busel', 'System', 'Signed in', '2026-05-11 00:46:12'),
(11, 'John Francis Busel', 'System', 'Signed out', '2026-05-11 00:47:25'),
(12, 'Super Admin', 'System', 'Signed in', '2026-05-11 00:47:36'),
(13, 'Super Admin', 'System', 'Signed in', '2026-05-11 00:51:24'),
(14, 'John Francis Busel', 'System', 'Signed in', '2026-05-11 03:12:13'),
(15, 'Super Admin', 'System', 'Signed in', '2026-05-11 05:36:35'),
(16, 'Super Admin', 'System', 'Signed out', '2026-05-11 05:41:53'),
(17, 'Super Admin', 'System', 'Signed in', '2026-05-11 07:39:40'),
(18, 'John Francis Busel', 'System', 'Signed in', '2026-05-12 06:06:39'),
(19, 'John Francis Busel', 'System', 'Signed out', '2026-05-12 06:20:12'),
(20, 'Super Admin', 'System', 'Signed in', '2026-05-12 06:20:59'),
(21, 'Super Admin', 'System', 'Signed out', '2026-05-12 06:25:43'),
(22, 'Almarie Ex', 'System', 'Signed in', '2026-05-12 06:25:59'),
(23, 'Almarie Ex', 'System', 'Signed out', '2026-05-12 06:31:47'),
(24, 'Super Admin', 'System', 'Signed in', '2026-05-12 06:32:47'),
(25, 'Super Admin', 'System', 'Signed out', '2026-05-12 06:33:21'),
(26, 'John Francis Busel', 'System', 'Signed in', '2026-05-12 06:33:35'),
(27, 'John Francis Busel', 'System', 'Signed out', '2026-05-12 06:38:28'),
(28, 'Almarie Ex', 'System', 'Signed in', '2026-05-12 06:39:00'),
(29, 'Almarie Ex', 'System', 'Signed out', '2026-05-12 06:41:18'),
(30, 'Super Admin', 'System', 'Signed in', '2026-05-12 06:41:28'),
(31, 'Super Admin', 'System', 'Signed out', '2026-05-12 06:44:59'),
(32, 'Almarie Ex', 'System', 'Signed in', '2026-05-19 03:59:45'),
(33, 'Almarie Ex', 'System', 'Signed in', '2026-05-24 06:35:23'),
(34, 'Almarie Ex', 'System', 'Signed out', '2026-05-24 06:36:27'),
(35, 'Super Admin', 'System', 'Signed in', '2026-05-24 06:36:41'),
(36, 'Super Admin', 'Sales', 'Recorded sale TXN-048 — Apple iPhone 11', '2026-05-24 07:06:32'),
(37, 'Super Admin', 'Main Branch', 'Added device: Xiaomi Xiaomi Pad 8', '2026-05-24 07:08:01');

-- --------------------------------------------------------

--
-- Table structure for table `branches`
--

CREATE TABLE `branches` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `address` varchar(255) NOT NULL,
  `manager` varchar(150) DEFAULT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `branches`
--

INSERT INTO `branches` (`id`, `name`, `address`, `manager`, `phone`, `status`, `created_at`) VALUES
(1, 'RF Chein - Main Branch', 'Burgos St, Bacolod City', 'John Francis Busel', '0912-345-6789', 'Active', '2026-05-11 00:31:32'),
(2, 'RF Chein - Lacson Branch', 'Lacson St, Bacolod City', 'James Mark Jariño', '0923-456-7890', 'Active', '2026-05-11 00:31:32'),
(3, 'RF Chein - SM Branch', 'SM City Bacolod', 'Aljon Obediente', '0934-567-8901', 'Active', '2026-05-11 00:31:32');

-- --------------------------------------------------------

--
-- Table structure for table `phones`
--

CREATE TABLE `phones` (
  `id` int(11) NOT NULL,
  `brand` varchar(60) NOT NULL,
  `model` varchar(120) NOT NULL,
  `storage` varchar(20) NOT NULL,
  `ram` varchar(20) DEFAULT NULL,
  `color` varchar(60) DEFAULT NULL,
  `condition` enum('Excellent','Good','Fair','Poor') NOT NULL DEFAULT 'Good',
  `battery` int(11) DEFAULT 100,
  `selling_price` decimal(10,2) NOT NULL,
  `purchase_price` decimal(10,2) DEFAULT NULL,
  `stock` int(11) NOT NULL DEFAULT 1,
  `branch_id` int(11) DEFAULT NULL,
  `imei` varchar(40) DEFAULT NULL,
  `serial_number` varchar(80) DEFAULT NULL,
  `accessories` varchar(120) DEFAULT 'Unit only',
  `notes` text DEFAULT NULL,
  `emoji` varchar(10) DEFAULT '?',
  `is_listed` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `phones`
--

INSERT INTO `phones` (`id`, `brand`, `model`, `storage`, `ram`, `color`, `condition`, `battery`, `selling_price`, `purchase_price`, `stock`, `branch_id`, `imei`, `serial_number`, `accessories`, `notes`, `emoji`, `is_listed`, `created_at`) VALUES
(1, 'Samsung', 'Galaxy A54', '128GB', '8GB', 'Midnight Black', 'Good', 85, 4200.00, 3000.00, 12, 1, '35xxxxxxxxxxxxxx', NULL, 'Charger + earphones', 'Minor scratches on back, screen perfect.', '📱', 1, '2026-05-11 00:31:32'),
(2, 'Apple', 'iPhone 12', '64GB', '4GB', 'Black', 'Good', 79, 8500.00, 6800.00, 3, 2, '35xxxxxxxxxxxxxx', NULL, 'Charger only', 'Face ID works perfectly. Small dent on corner.', '📱', 1, '2026-05-11 00:31:32'),
(3, 'Xiaomi', 'Redmi Note 11', '128GB', '6GB', 'Graphite Gray', 'Fair', 91, 2800.00, 1800.00, 8, 1, '86xxxxxxxxxxxxxx', NULL, 'Charger only', 'Visible scratches on screen, no cracks.', '📱', 1, '2026-05-11 00:31:32'),
(4, 'OPPO', 'A78', '256GB', '8GB', 'Glowing Black', 'Excellent', 96, 5500.00, 4000.00, 2, 3, '35xxxxxxxxxxxxxx', NULL, 'Complete (box, charger, earphones)', 'Like new, barely used for 2 months.', '📱', 1, '2026-05-11 00:31:32'),
(5, 'Vivo', 'Y35', '128GB', '8GB', 'Dawn Gold', 'Good', 82, 3200.00, 2200.00, 0, 2, '86xxxxxxxxxxxxxx', NULL, 'Charger only', 'Good overall condition.', '📱', 1, '2026-05-11 00:31:32'),
(6, 'Samsung', 'Galaxy S21', '256GB', '8GB', 'Phantom Gray', 'Good', 77, 9800.00, 7500.00, 5, 1, '35xxxxxxxxxxxxxx', NULL, 'Charger only', 'No cracks, camera works great.', '📱', 1, '2026-05-11 00:31:32'),
(7, 'Realme', 'C35', '64GB', '4GB', 'Glowing Green', 'Fair', 88, 1900.00, 1200.00, 14, 3, '86xxxxxxxxxxxxxx', NULL, 'Unit only', 'Light scratches, fully functional.', '📱', 1, '2026-05-11 00:31:32'),
(8, 'Apple', 'iPhone 11', '64GB', '4GB', 'White', 'Fair', 72, 7200.00, 5500.00, 0, 2, '35xxxxxxxxxxxxxx', NULL, 'Charger only', 'Cracked back cover, screen is perfect.', '📱', 1, '2026-05-11 00:31:32'),
(9, 'Samsung', 'Galaxy A32', '128GB', '6GB', 'Awesome Black', 'Excellent', 93, 3800.00, 2500.00, 4, 1, '35xxxxxxxxxxxxxx', NULL, 'Charger + earphones', 'Excellent condition, no scratches.', '📱', 1, '2026-05-11 00:31:32'),
(10, 'Xiaomi', 'Redmi 10C', '128GB', '4GB', 'Mint Green', 'Good', 86, 2200.00, 1400.00, 6, 3, '86xxxxxxxxxxxxxx', NULL, 'Charger only', 'Good condition, slight wear on corners.', '📱', 1, '2026-05-11 00:31:32'),
(11, 'OPPO', 'Reno 6', '128GB', '8GB', 'Aurora', 'Good', 84, 6200.00, 4500.00, 3, 1, '35xxxxxxxxxxxxxx', NULL, 'Charger + earphones', 'Very good condition, AI camera works great.', '📱', 1, '2026-05-11 00:31:32'),
(12, 'Vivo', 'V23', '256GB', '12GB', 'Sunshine Gold', 'Excellent', 97, 7800.00, 6000.00, 2, 2, '86xxxxxxxxxxxxxx', NULL, 'Complete (box, charger, earphones)', 'Brand new condition, purchased 1 month ago.', '📱', 1, '2026-05-11 00:31:32'),
(13, 'Xiaomi', 'Xiaomi Pad 8', '32GB', '2GB', 'Black', 'Excellent', 90, 20000.00, 30000.00, 5, 1, '564564564564654645645', '4', 'Charger only', NULL, '📱', 1, '2026-05-24 07:08:01');

-- --------------------------------------------------------

--
-- Table structure for table `sales`
--

CREATE TABLE `sales` (
  `id` int(11) NOT NULL,
  `txn_id` varchar(20) NOT NULL,
  `phone_id` int(11) DEFAULT NULL,
  `product_name` varchar(200) NOT NULL,
  `customer` varchar(150) DEFAULT 'Walk-in customer',
  `price` decimal(10,2) NOT NULL,
  `payment_method` enum('Cash','GCash','Maya','Bank transfer') NOT NULL DEFAULT 'Cash',
  `sale_date` date NOT NULL,
  `status` enum('Completed','Pending','Refunded') NOT NULL DEFAULT 'Completed',
  `user_id` int(11) DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sales`
--

INSERT INTO `sales` (`id`, `txn_id`, `phone_id`, `product_name`, `customer`, `price`, `payment_method`, `sale_date`, `status`, `user_id`, `branch_id`, `created_at`) VALUES
(1, 'TXN-047', 1, 'Samsung Galaxy A54', 'Maria Santos', 4200.00, 'Cash', '2026-05-11', 'Completed', NULL, 1, '2026-05-11 00:31:32'),
(2, 'TXN-046', 2, 'iPhone 12', 'Jose Reyes', 8500.00, 'GCash', '2026-05-10', 'Completed', NULL, 2, '2026-05-11 00:31:32'),
(3, 'TXN-045', 3, 'Xiaomi Redmi Note 11', 'Ana Garcia', 2800.00, 'Cash', '2026-05-10', 'Completed', NULL, 1, '2026-05-11 00:31:32'),
(4, 'TXN-044', 4, 'OPPO A78', 'Pedro Cruz', 5500.00, 'Maya', '2026-05-09', 'Completed', NULL, 3, '2026-05-11 00:31:32'),
(5, 'TXN-043', 7, 'Realme C35', 'Rosa Flores', 1900.00, 'Cash', '2026-05-09', 'Completed', NULL, 3, '2026-05-11 00:31:32'),
(6, 'TXN-042', 6, 'Samsung Galaxy S21', 'Ramon Dela Cruz', 9800.00, 'Bank transfer', '2026-05-08', 'Completed', NULL, 1, '2026-05-11 00:31:32'),
(16, 'TXN-048', 8, 'Apple iPhone 11', 'joy', 7200.00, 'Cash', '2026-05-24', 'Completed', 1, 2, '2026-05-24 07:06:32');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `email` varchar(150) NOT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `username` varchar(80) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('Super Admin','Branch Admin','Staff','Viewer') NOT NULL DEFAULT 'Staff',
  `branch_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `username`, `password`, `role`, `branch_id`, `created_at`) VALUES
(1, 'Super Admin', 'admin@rfchein.com', 'admin', '$2y$10$AYF2b956pXsCsyO.emM5pOOi5sYCG4PvNd8DZPkdgZJ3OWKwXIMsS', 'Super Admin', NULL, '2026-05-11 00:31:32'),
(2, 'John Francis Busel', 'jfbusel@rfchein.com', 'jfbusel', '$2y$10$zM8vOtLJxv4/VJs3zbXxxuH136DnUvI1ZvRVB8RVweWfbxhk.jQtS', 'Branch Admin', 1, '2026-05-11 00:31:32'),
(3, 'James Mark Jariño', 'jmjarino@rfchein.com', 'jmjarino', 'password123', 'Branch Admin', 2, '2026-05-11 00:31:32'),
(4, 'Aljon Obediente', 'amobediente@rfchein.com', 'amobediente', 'password123', 'Branch Admin', 3, '2026-05-11 00:31:32'),
(5, 'Almarie Ex', 'adex@rfchein.com', 'adex', '$2y$10$jlmrgEi3bmYyTaeCRlp8oOgitgdFybXQoELYoPC3Z3W6H.04mPRq6', 'Staff', 1, '2026-05-11 00:31:32'),
(6, 'Maria Reyes', 'mreyes@rfchein.com', 'mreyes', 'password123', 'Staff', 2, '2026-05-11 00:31:32'),
(7, 'Pedro Cruz', 'pcruz@rfchein.com', 'pcruz', 'password123', 'Staff', 1, '2026-05-11 00:31:32'),
(8, 'Rosa Flores', 'rflores@rfchein.com', 'rflores', 'password123', 'Viewer', 3, '2026-05-11 00:31:32'),
(9, 'Ramon Garcia', 'rgarcia@rfchein.com', 'rgarcia', 'password123', 'Viewer', 1, '2026-05-11 00:31:32');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `branches`
--
ALTER TABLE `branches`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `phones`
--
ALTER TABLE `phones`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_phone_branch` (`branch_id`);

--
-- Indexes for table `sales`
--
ALTER TABLE `sales`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `txn_id` (`txn_id`),
  ADD KEY `fk_sale_phone` (`phone_id`),
  ADD KEY `fk_sale_user` (`user_id`),
  ADD KEY `fk_sale_branch` (`branch_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `fk_user_branch` (`branch_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_logs`
--
ALTER TABLE `activity_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=38;

--
-- AUTO_INCREMENT for table `branches`
--
ALTER TABLE `branches`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `phones`
--
ALTER TABLE `phones`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `sales`
--
ALTER TABLE `sales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `phones`
--
ALTER TABLE `phones`
  ADD CONSTRAINT `fk_phone_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `sales`
--
ALTER TABLE `sales`
  ADD CONSTRAINT `fk_sale_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_sale_phone` FOREIGN KEY (`phone_id`) REFERENCES `phones` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_sale_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_user_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL;

ALTER TABLE `branches`
  ADD COLUMN `email` varchar(150) DEFAULT NULL AFTER `phone`;

ALTER TABLE `phones`
  ADD COLUMN `supplier` varchar(150) DEFAULT NULL AFTER `purchase_price`,
  ADD COLUMN `image_url` varchar(255) DEFAULT NULL AFTER `emoji`,
  ADD COLUMN `last_moved_at` datetime DEFAULT NULL AFTER `is_listed`;

UPDATE `branches`
SET `email` = CASE `id`
  WHEN 1 THEN 'main@rfchein.com'
  WHEN 2 THEN 'lacson@rfchein.com'
  WHEN 3 THEN 'sm@rfchein.com'
  ELSE NULL
END;

UPDATE `phones`
SET `supplier` = CASE `id`
  WHEN 1 THEN 'Main trade-in desk'
  WHEN 2 THEN 'Lacson reseller network'
  WHEN 3 THEN 'Main walk-in seller'
  WHEN 4 THEN 'SM trade-in counter'
  WHEN 5 THEN 'Online marketplace'
  WHEN 6 THEN 'Main branch supplier'
  WHEN 7 THEN 'SM bulk acquisition'
  WHEN 8 THEN 'Lacson buyback desk'
  WHEN 9 THEN 'Main trade-in desk'
  WHEN 10 THEN 'SM reseller pool'
  WHEN 11 THEN 'Main branch supplier'
  WHEN 12 THEN 'Lacson premium source'
  ELSE 'Store acquisition'
END,
`last_moved_at` = `created_at`;

CREATE TABLE `stock_transfers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transfer_code` varchar(30) NOT NULL,
  `source_branch_id` int(11) NOT NULL,
  `destination_branch_id` int(11) NOT NULL,
  `requested_by` int(11) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `status` enum('Pending','Approved','In Transit','Completed','Rejected') NOT NULL DEFAULT 'Pending',
  `notes` text DEFAULT NULL,
  `rejection_reason` varchar(255) DEFAULT NULL,
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `approved_at` datetime DEFAULT NULL,
  `in_transit_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `transfer_code` (`transfer_code`),
  KEY `idx_transfer_status` (`status`),
  KEY `idx_transfer_scope` (`source_branch_id`,`destination_branch_id`),
  KEY `fk_transfer_requested_by` (`requested_by`),
  KEY `fk_transfer_approved_by` (`approved_by`),
  CONSTRAINT `fk_transfer_source_branch` FOREIGN KEY (`source_branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_transfer_destination_branch` FOREIGN KEY (`destination_branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_transfer_requested_by` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_transfer_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `transfer_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transfer_id` int(11) NOT NULL,
  `phone_id` int(11) NOT NULL,
  `product_name` varchar(200) NOT NULL,
  `imei` varchar(40) DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `unit_cost` decimal(10,2) DEFAULT NULL,
  `source_stock_before` int(11) DEFAULT NULL,
  `source_stock_after` int(11) DEFAULT NULL,
  `destination_stock_after` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_transfer_item_transfer` (`transfer_id`),
  KEY `fk_transfer_item_phone` (`phone_id`),
  CONSTRAINT `fk_transfer_item_transfer` FOREIGN KEY (`transfer_id`) REFERENCES `stock_transfers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_transfer_item_phone` FOREIGN KEY (`phone_id`) REFERENCES `phones` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `inventory_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `phone_id` int(11) DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `transfer_id` int(11) DEFAULT NULL,
  `event_type` enum('created','sale','transfer_out','transfer_in','adjustment','branch_update','status_change') NOT NULL DEFAULT 'adjustment',
  `quantity_change` int(11) NOT NULL DEFAULT 0,
  `stock_before` int(11) DEFAULT NULL,
  `stock_after` int(11) DEFAULT NULL,
  `reference_code` varchar(50) DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_inventory_branch_created` (`branch_id`,`created_at`),
  KEY `fk_inventory_log_phone` (`phone_id`),
  KEY `fk_inventory_log_user` (`user_id`),
  KEY `fk_inventory_log_transfer` (`transfer_id`),
  CONSTRAINT `fk_inventory_log_phone` FOREIGN KEY (`phone_id`) REFERENCES `phones` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_inventory_log_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_inventory_log_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_inventory_log_transfer` FOREIGN KEY (`transfer_id`) REFERENCES `stock_transfers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `flash_sales` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `phone_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `title` varchar(150) NOT NULL,
  `promo_label` varchar(60) NOT NULL DEFAULT 'Flash Sale',
  `sale_price` decimal(10,2) NOT NULL,
  `description` text DEFAULT NULL,
  `starts_at` datetime NOT NULL,
  `ends_at` datetime NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_flash_sale_branch_window` (`branch_id`,`is_active`,`starts_at`,`ends_at`),
  KEY `fk_flash_sale_phone` (`phone_id`),
  KEY `fk_flash_sale_user` (`created_by`),
  CONSTRAINT `fk_flash_sale_phone` FOREIGN KEY (`phone_id`) REFERENCES `phones` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_flash_sale_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_flash_sale_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `inquiries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `phone_id` int(11) DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `customer_name` varchar(150) NOT NULL,
  `contact_number` varchar(40) NOT NULL,
  `preferred_channel` enum('Phone','SMS','Call','Facebook','Email','Website') NOT NULL DEFAULT 'Website',
  `subject` varchar(150) DEFAULT NULL,
  `latest_message` text NOT NULL,
  `status` enum('New','Contacted','Resolved','Closed') NOT NULL DEFAULT 'New',
  `assigned_user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_inquiry_branch_status` (`branch_id`,`status`,`created_at`),
  KEY `fk_inquiry_phone` (`phone_id`),
  KEY `fk_inquiry_user` (`assigned_user_id`),
  CONSTRAINT `fk_inquiry_phone` FOREIGN KEY (`phone_id`) REFERENCES `phones` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_inquiry_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_inquiry_user` FOREIGN KEY (`assigned_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `inquiry_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `inquiry_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `sender_type` enum('Customer','Staff','System') NOT NULL DEFAULT 'Customer',
  `sender_name` varchar(150) NOT NULL,
  `message` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_inquiry_message_scope` (`inquiry_id`,`created_at`),
  KEY `fk_inquiry_message_user` (`user_id`),
  CONSTRAINT `fk_inquiry_message_inquiry` FOREIGN KEY (`inquiry_id`) REFERENCES `inquiries` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_inquiry_message_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `stock_transfers` (`id`, `transfer_code`, `source_branch_id`, `destination_branch_id`, `requested_by`, `approved_by`, `status`, `notes`, `requested_at`, `approved_at`, `in_transit_at`, `completed_at`, `rejected_at`) VALUES
(1, 'TRF-0001', 1, 2, 2, 1, 'Approved', 'Move one Reno 6 unit to Lacson Branch for weekend demand.', '2026-05-20 08:30:00', '2026-05-20 09:00:00', NULL, NULL, NULL),
(2, 'TRF-0002', 3, 1, 4, NULL, 'Pending', 'Restock Redmi inventory for Main Branch flash sale.', '2026-05-24 10:45:00', NULL, NULL, NULL, NULL);

INSERT INTO `transfer_items` (`id`, `transfer_id`, `phone_id`, `product_name`, `imei`, `quantity`, `unit_cost`, `source_stock_before`, `source_stock_after`, `destination_stock_after`, `created_at`) VALUES
(1, 1, 11, 'OPPO Reno 6', '35xxxxxxxxxxxxxx', 1, 4500.00, NULL, NULL, NULL, '2026-05-20 08:30:00'),
(2, 2, 10, 'Xiaomi Redmi 10C', '86xxxxxxxxxxxxxx', 2, 1400.00, NULL, NULL, NULL, '2026-05-24 10:45:00');

INSERT INTO `inventory_logs` (`id`, `phone_id`, `branch_id`, `user_id`, `transfer_id`, `event_type`, `quantity_change`, `stock_before`, `stock_after`, `reference_code`, `remarks`, `created_at`) VALUES
(1, 1, 1, 2, NULL, 'created', 12, 0, 12, NULL, 'Initial seeded inventory.', '2026-05-11 00:31:32'),
(2, 6, 1, 1, NULL, 'sale', -1, 6, 5, 'TXN-042', 'Seeded sale transaction.', '2026-05-11 00:31:32'),
(3, 11, 1, 1, 1, 'branch_update', 0, 3, 3, 'TRF-0001', 'Transfer request approved for Lacson Branch.', '2026-05-20 09:00:00');

INSERT INTO `flash_sales` (`id`, `phone_id`, `branch_id`, `title`, `promo_label`, `sale_price`, `description`, `starts_at`, `ends_at`, `is_active`, `created_by`, `created_at`) VALUES
(1, 2, 2, 'Weekend iPhone Push', 'Flash Sale', 7999.00, 'Limited-time Lacson promo for weekend walk-ins.', '2026-05-28 09:00:00', '2026-06-05 19:00:00', 1, 1, '2026-05-28 08:30:00'),
(2, 11, 1, 'Main Branch Reno Boost', 'Weekend Drop', 5799.00, 'Promotional price to speed up Reno 6 turnover.', '2026-06-06 09:00:00', '2026-06-08 19:00:00', 1, 1, '2026-05-30 09:00:00');

INSERT INTO `inquiries` (`id`, `phone_id`, `branch_id`, `customer_name`, `contact_number`, `preferred_channel`, `subject`, `latest_message`, `status`, `assigned_user_id`, `created_at`, `updated_at`, `resolved_at`) VALUES
(1, 2, 2, 'Karen Lopez', '0917-222-1100', 'Website', 'Inquiry for Apple iPhone 12', 'Hi, is this still available and can you hold it until Saturday?', 'New', NULL, '2026-05-29 10:15:00', '2026-05-29 10:15:00', NULL),
(2, 11, 1, 'Michael Sy', '0918-555-2211', 'Call', 'Inquiry for OPPO Reno 6', 'Yes, one unit is still available and the charger is included.', 'Contacted', 2, '2026-05-29 14:20:00', '2026-05-29 15:05:00', NULL);

INSERT INTO `inquiry_messages` (`id`, `inquiry_id`, `user_id`, `sender_type`, `sender_name`, `message`, `created_at`) VALUES
(1, 1, NULL, 'Customer', 'Karen Lopez', 'Hi, is this still available and can you hold it until Saturday?', '2026-05-29 10:15:00'),
(2, 2, NULL, 'Customer', 'Michael Sy', 'Do you still have the Reno 6 and does it include the charger?', '2026-05-29 14:20:00'),
(3, 2, 2, 'Staff', 'John Francis Busel', 'Yes, one unit is still available and the charger is included.', '2026-05-29 15:05:00');

CREATE OR REPLACE VIEW `branch_inventory` AS
SELECT
  `p`.`id` AS `inventory_item_id`,
  `p`.`id` AS `phone_id`,
  `p`.`branch_id` AS `branch_id`,
  `b`.`name` AS `branch_name`,
  `p`.`imei` AS `imei`,
  `p`.`brand` AS `brand`,
  `p`.`model` AS `model`,
  `p`.`storage` AS `storage`,
  `p`.`ram` AS `ram`,
  `p`.`battery` AS `battery`,
  `p`.`condition` AS `device_condition`,
  `p`.`selling_price` AS `selling_price`,
  `p`.`purchase_price` AS `purchase_price`,
  `p`.`supplier` AS `supplier`,
  `p`.`image_url` AS `image_url`,
  `p`.`stock` AS `stock`,
  `p`.`created_at` AS `date_added`,
  `p`.`last_moved_at` AS `last_transfer_at`,
  `p`.`is_listed` AS `is_listed`
FROM `phones` `p`
LEFT JOIN `branches` `b` ON `b`.`id` = `p`.`branch_id`;

CREATE OR REPLACE VIEW `branch_users` AS
SELECT
  `u`.`id` AS `user_id`,
  `u`.`name` AS `name`,
  `u`.`email` AS `email`,
  `u`.`phone` AS `phone`,
  `u`.`username` AS `username`,
  `u`.`role` AS `role`,
  `u`.`branch_id` AS `branch_id`,
  `b`.`name` AS `branch_name`,
  `b`.`status` AS `branch_status`,
  `u`.`created_at` AS `created_at`
FROM `users` `u`
LEFT JOIN `branches` `b` ON `b`.`id` = `u`.`branch_id`;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
