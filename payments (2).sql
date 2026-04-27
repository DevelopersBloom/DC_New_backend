-- phpMyAdmin SQL Dump
-- version 5.1.1deb5ubuntu1
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Apr 22, 2026 at 07:59 AM
-- Server version: 8.0.44-0ubuntu0.22.04.2
-- PHP Version: 8.1.2-1ubuntu2.23

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `test_lomb`
--

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` bigint UNSIGNED NOT NULL,
  `PGI_ID` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mother` decimal(25,10) NOT NULL DEFAULT '0.0000000000',
  `amount` decimal(25,10) DEFAULT NULL,
  `original_amount` decimal(25,10) DEFAULT NULL,
  `paid` decimal(25,10) DEFAULT NULL,
  `discount_amount` int NOT NULL DEFAULT '0',
  `effective_payment` decimal(25,10) NOT NULL DEFAULT '0.0000000000',
  `principal_payment` decimal(25,10) NOT NULL DEFAULT '0.0000000000',
  `original_principal_payment` decimal(25,10) NOT NULL DEFAULT '0.0000000000',
  `interest_payment` decimal(25,10) NOT NULL DEFAULT '0.0000000000',
  `original_interest_payment` decimal(25,10) NOT NULL DEFAULT '0.0000000000',
  `service_fee_payment` decimal(18,10) NOT NULL DEFAULT '0.0000000000',
  `remaining` decimal(25,10) NOT NULL DEFAULT '0.0000000000',
  `kasko_amount` decimal(15,6) DEFAULT NULL,
  `kasko_paid` tinyint(1) NOT NULL DEFAULT '0',
  `last_payment` tinyint(1) NOT NULL DEFAULT '0',
  `is_completed` tinyint(1) NOT NULL DEFAULT '0',
  `parent_id` bigint UNSIGNED DEFAULT NULL,
  `days` int DEFAULT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'regular',
  `name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cash` tinyint(1) NOT NULL DEFAULT '1',
  `surname` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `passport` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `another_payer` tinyint(1) NOT NULL DEFAULT '0',
  `penalty` int NOT NULL DEFAULT '0',
  `contract_id` int DEFAULT NULL,
  `pawnshop_id` int DEFAULT NULL,
  `date` date DEFAULT NULL,
  `from_date` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `to_date` date DEFAULT NULL,
  `status` enum('completed','initial') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'initial',
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`id`, `PGI_ID`, `mother`, `amount`, `original_amount`, `paid`, `discount_amount`, `effective_payment`, `principal_payment`, `original_principal_payment`, `interest_payment`, `original_interest_payment`, `service_fee_payment`, `remaining`, `kasko_amount`, `kasko_paid`, `last_payment`, `is_completed`, `parent_id`, `days`, `type`, `name`, `cash`, `surname`, `passport`, `phone`, `another_payer`, `penalty`, `contract_id`, `pawnshop_id`, `date`, `from_date`, `to_date`, `status`, `deleted_at`, `created_at`, `updated_at`) VALUES
(25, '1', '0.0000000000', '4863.9960000000', '11586.7990436160', '13186.8000000000', 0, '0.0000000000', '0.0000000000', '6186.7990436161', '4863.9960000000', '5400.0000000000', '0.0000000000', '93180.0000000000', '0.000000', 0, 0, 0, NULL, 30, 'regular', NULL, 1, NULL, NULL, NULL, 0, 0, 1, 1, '2026-05-21', '2026-04-21', '2026-05-21', 'initial', NULL, '2026-04-22 07:56:36', '2026-04-22 07:56:47'),
(26, '2', '0.0000000000', '10917.1280000000', '11586.7990436160', '633.2000000000', 0, '0.0000000000', '5549.9600000000', '6183.1586685284', '5367.1680000000', '5403.6403750877', '0.0000000000', '87630.0422878550', '0.000000', 0, 0, 0, NULL, 32, 'regular', NULL, 1, NULL, NULL, NULL, 0, 0, 1, 1, '2026-06-22', '2026-05-21', '2026-06-22', 'initial', NULL, '2026-04-22 07:56:36', '2026-04-22 07:56:47'),
(27, '3', '0.0000000000', '11586.7990436160', '11586.7990436160', NULL, 0, '0.0000000000', '7012.5108361901', '7012.5108361901', '4574.2882074261', '4574.2882074261', '0.0000000000', '80617.5314516650', '0.000000', 0, 0, 0, NULL, 29, 'regular', NULL, 1, NULL, NULL, NULL, 0, 0, 1, 1, '2026-07-21', '2026-06-22', '2026-07-21', 'initial', NULL, '2026-04-22 07:56:36', '2026-04-22 07:56:36'),
(28, '4', '0.0000000000', '11586.7990436160', '11586.7990436160', NULL, 0, '0.0000000000', '7088.3407886132', '7088.3407886132', '4498.4582550029', '4498.4582550029', '0.0000000000', '73529.1906630520', '0.000000', 0, 0, 0, NULL, 31, 'regular', NULL, 1, NULL, NULL, NULL, 0, 0, 1, 1, '2026-08-21', '2026-07-21', '2026-08-21', 'initial', NULL, '2026-04-22 07:56:36', '2026-04-22 07:56:36'),
(29, '5', '0.0000000000', '11586.7990436160', '11586.7990436160', NULL, 0, '0.0000000000', '7351.5176614243', '7351.5176614243', '4235.2813821918', '4235.2813821918', '0.0000000000', '66177.6730016280', '0.000000', 0, 0, 0, NULL, 32, 'regular', NULL, 1, NULL, NULL, NULL, 0, 0, 1, 1, '2026-09-22', '2026-08-21', '2026-09-22', 'initial', NULL, '2026-04-22 07:56:36', '2026-04-22 07:56:36'),
(30, '6', '0.0000000000', '11586.7943740000', '11586.7990436160', NULL, 0, '0.0000000000', '8132.3245129312', '8132.3245129312', '3454.4745306850', '3454.4745306850', '0.0000000000', '58045.3484886970', '0.000000', 0, 0, 0, NULL, 29, 'regular', NULL, 1, NULL, NULL, NULL, 0, 0, 1, 1, '2026-10-21', '2026-09-22', '2026-10-21', 'initial', NULL, '2026-04-22 07:56:36', '2026-04-22 07:56:47'),
(31, '7', '0.0000000000', '11586.7990436160', '11586.7990436160', NULL, 0, '0.0000000000', '8138.9053433875', '8138.9053433875', '3447.8937002286', '3447.8937002286', '0.0000000000', '49906.4431453090', '0.000000', 0, 0, 0, NULL, 33, 'regular', NULL, 1, NULL, NULL, NULL, 0, 0, 1, 1, '2026-11-23', '2026-10-21', '2026-11-23', 'initial', NULL, '2026-04-22 07:56:36', '2026-04-22 07:56:36'),
(32, '8', '0.0000000000', '11586.7945760000', '11586.7990436160', NULL, 0, '0.0000000000', '9071.5143090925', '9071.5143090925', '2515.2847345236', '2515.2847345236', '0.0000000000', '40834.9288362170', '0.000000', 0, 0, 0, NULL, 28, 'regular', NULL, 1, NULL, NULL, NULL, 0, 0, 1, 1, '2026-12-21', '2026-11-23', '2026-12-21', 'initial', NULL, '2026-04-22 07:56:36', '2026-04-22 07:56:47'),
(33, '9', '0.0000000000', '11586.7990436160', '11586.7990436160', NULL, 0, '0.0000000000', '9308.2100145552', '9308.2100145552', '2278.5890290609', '2278.5890290609', '0.0000000000', '31526.7188216610', '0.000000', 0, 0, 0, NULL, 31, 'regular', NULL, 1, NULL, NULL, NULL, 0, 0, 1, 1, '2027-01-21', '2026-12-21', '2027-01-21', 'initial', NULL, '2026-04-22 07:56:36', '2026-04-22 07:56:36'),
(34, '10', '0.0000000000', '11586.7990436160', '11586.7990436160', NULL, 0, '0.0000000000', '9770.8600394884', '9770.8600394884', '1815.9390041277', '1815.9390041277', '0.0000000000', '21755.8587821730', '0.000000', 0, 0, 0, NULL, 32, 'regular', NULL, 1, NULL, NULL, NULL, 0, 0, 1, 1, '2027-02-22', '2027-01-21', '2027-02-22', 'initial', NULL, '2026-04-22 07:56:36', '2026-04-22 07:56:36'),
(35, '11', '0.0000000000', '11586.7990436160', '11586.7990436160', NULL, 0, '0.0000000000', '10490.3037609950', '10490.3037609950', '1096.4952826215', '1096.4952826215', '0.0000000000', '11265.5550211780', '0.000000', 0, 0, 0, NULL, 28, 'regular', NULL, 1, NULL, NULL, NULL, 0, 0, 1, 1, '2027-03-22', '2027-02-22', '2027-03-22', 'initial', NULL, '2026-04-22 07:56:36', '2026-04-22 07:56:36'),
(36, '12', '0.0000000000', '11873.9002400000', '11873.8949923220', NULL, 0, '0.0000000000', '11265.5550211780', '11265.5550211780', '608.3399711436', '608.3399711436', '0.0000000000', '0.0000000000', '0.000000', 0, 0, 0, NULL, 30, 'regular', NULL, 1, NULL, NULL, NULL, 0, 0, 1, 1, '2027-04-21', '2027-03-22', '2027-04-21', 'initial', NULL, '2026-04-22 07:56:36', '2026-04-22 07:56:47');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=37;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
