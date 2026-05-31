-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 31, 2026 at 08:00 PM
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
(2, 'RF Chein - Lacson Branch', 'Lacson St, Bacolod City', NULL, '0923-456-7890', 'Active', '2026-05-11 00:31:32'),
(3, 'RF Chein - SM Branch', 'SM City Bacolod', NULL, '0934-567-8901', 'Active', '2026-05-11 00:31:32');

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
  `role` enum('Super Admin','Admin','Supervisor','Staff','Viewer') NOT NULL DEFAULT 'Staff',
  `branch_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `username`, `password`, `role`, `branch_id`, `created_at`) VALUES
(1, 'Super Admin', 'admin@rfchein.com', 'admin', 'password123', 'Super Admin', NULL, '2026-05-11 00:31:32'),
(2, 'Chief Executive Office', 'ceo@rfchein.com', 'ceo', 'password123', 'Admin', NULL, '2026-05-31 09:00:00'),
(3, 'John Francis Busel', 'jfbusel@rfchein.com', 'jfbusel', 'password123', 'Supervisor', 1, '2026-05-11 00:31:32'),
(4, 'Almarie Ex', 'adex@rfchein.com', 'adex', 'password123', 'Staff', 1, '2026-05-11 00:31:32');

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `branches`
--
ALTER TABLE `branches`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `phones`
--
ALTER TABLE `phones`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sales`
--
ALTER TABLE `sales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

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
