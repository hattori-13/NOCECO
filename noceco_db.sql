-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Apr 27, 2026 at 04:21 PM
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
-- Database: `noceco_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `billing_invoices`
--

CREATE TABLE `billing_invoices` (
  `invoice_no` varchar(20) NOT NULL,
  `account_no` varchar(20) NOT NULL,
  `billing_month` varchar(20) NOT NULL,
  `reading_date` date NOT NULL,
  `previous_reading` int(11) NOT NULL,
  `current_reading` int(11) NOT NULL,
  `kwh_used` int(11) NOT NULL,
  `total_sales` decimal(10,2) NOT NULL,
  `amount_due` decimal(10,2) NOT NULL,
  `due_date` date NOT NULL,
  `penalty_surcharge` decimal(10,2) DEFAULT 0.00,
  `status` enum('Unpaid','Paid','Overdue') DEFAULT 'Unpaid',
  `meter_reader_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `billing_invoices`
--

INSERT INTO `billing_invoices` (`invoice_no`, `account_no`, `billing_month`, `reading_date`, `previous_reading`, `current_reading`, `kwh_used`, `total_sales`, `amount_due`, `due_date`, `penalty_surcharge`, `status`, `meter_reader_id`, `created_at`) VALUES
('2604262192', '26-328-66378', 'April 2026', '2026-04-26', 0, 127, 127, 1527.07, 1684.08, '2026-05-05', 0.00, 'Paid', 2, '2026-04-26 08:53:13'),
('2604264472', '26-374-49571', 'April 2026', '2026-04-26', 0, 0, 0, 6.20, 6.32, '2026-04-25', 0.32, 'Paid', 2, '2026-04-26 11:15:07'),
('2604265356', '26-362-81351', 'March2026', '2026-03-12', 0, 1, 1, 16.98, 18.22, '2026-04-22', 0.91, 'Paid', 2, '2026-03-12 11:05:58'),
('2604271884', '26-300-70123', 'April 2026', '2026-04-27', 0, 125, 125, 1513.60, 1668.14, '2026-05-06', 0.00, 'Unpaid', 2, '2026-04-27 12:43:06'),
('2604276849', '26-362-81351', 'April 2026', '2026-04-27', 1, 127, 126, 1525.58, 1681.36, '2026-05-06', 0.00, 'Unpaid', 2, '2026-04-27 03:58:58');

-- --------------------------------------------------------

--
-- Table structure for table `billing_rates_catalog`
--

CREATE TABLE `billing_rates_catalog` (
  `rate_id` int(11) NOT NULL,
  `charge_description` varchar(100) NOT NULL,
  `effective_month` varchar(50) DEFAULT 'Standard',
  `charge_type` enum('Per_KWH','Per_Customer','Percentage','Fixed') NOT NULL,
  `current_rate` decimal(10,4) NOT NULL,
  `is_vatable` tinyint(1) DEFAULT 0,
  `status` enum('Active','Archived') DEFAULT 'Active',
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `billing_rates_catalog`
--

INSERT INTO `billing_rates_catalog` (`rate_id`, `charge_description`, `effective_month`, `charge_type`, `current_rate`, `is_vatable`, `status`, `last_updated`) VALUES
(27, 'Generation System Charge', '2026-04', 'Per_KWH', 6.6024, 0, 'Active', '2026-04-26 08:42:23'),
(28, 'Franchise/Benefit to Host', '2026-04', 'Per_KWH', 0.0000, 0, 'Active', '2026-04-26 08:42:23'),
(29, 'GRAM', '2026-04', 'Per_KWH', 0.0000, 0, 'Active', '2026-04-26 08:42:23'),
(30, 'ICERA', '2026-04', 'Per_KWH', 0.0000, 0, 'Active', '2026-04-26 08:42:23'),
(31, 'Power Act Reduction', '2026-04', 'Per_KWH', 0.0000, 0, 'Active', '2026-04-26 08:42:23'),
(32, 'Transmission Demand Charge', '2026-04', 'Per_KWH', 0.0000, 0, 'Active', '2026-04-26 08:42:23'),
(33, 'Transmission System Charge', '2026-04', 'Per_KWH', 1.8428, 0, 'Active', '2026-04-26 08:42:23'),
(34, 'System Loss Charge', '2026-04', 'Per_KWH', 1.0503, 0, 'Active', '2026-04-26 08:42:23'),
(35, 'Distribution Demand Charge', '2026-04', 'Per_KWH', 0.0000, 0, 'Active', '2026-04-26 08:42:23'),
(36, 'Distribution System Charge', '2026-04', 'Per_KWH', 0.5782, 0, 'Active', '2026-04-26 08:42:23'),
(37, 'Supply Retail Cust. Charge', '2026-04', 'Per_Customer', 0.0000, 0, 'Active', '2026-04-26 08:42:23'),
(38, 'Supply System Charge', '2026-04', 'Per_KWH', 0.6001, 0, 'Active', '2026-04-26 08:42:23'),
(39, 'Metering Retail Charge', '2026-04', 'Per_Customer', 5.0000, 0, 'Active', '2026-04-26 08:42:23'),
(40, 'Metering System Charge', '2026-04', 'Per_KWH', 0.4326, 0, 'Active', '2026-04-26 08:42:23'),
(41, 'Missionary Electrification', '2026-04', 'Per_KWH', 0.2763, 0, 'Active', '2026-04-26 08:42:23'),
(42, 'Environmental Charge', '2026-04', 'Per_KWH', 0.0025, 0, 'Active', '2026-04-26 08:42:23'),
(43, 'NPC Stranded Contract Cost', '2026-04', 'Per_KWH', 0.0000, 0, 'Active', '2026-04-26 08:42:23'),
(44, 'NPC Stranded Debts', '2026-04', 'Per_KWH', 0.0428, 0, 'Active', '2026-04-26 08:42:23'),
(45, 'UC - Fit All', '2026-04', 'Per_KWH', 0.2011, 0, 'Active', '2026-04-26 08:42:23'),
(46, 'GEA-ALL', '2026-04', 'Per_KWH', 0.0371, 0, 'Active', '2026-04-26 08:42:23'),
(47, 'REC Recovery', '2026-04', 'Per_KWH', 0.0181, 0, 'Active', '2026-04-26 08:42:23'),
(48, 'Inter Class Cross Subsidy', '2026-04', 'Per_KWH', 0.0000, 0, 'Active', '2026-04-26 08:42:23'),
(49, 'Lifeline Rate Subsidy', '2026-04', 'Per_KWH', 0.0100, 0, 'Active', '2026-04-26 08:42:23'),
(50, 'Loan Condonation Per KWH', '2026-04', 'Per_KWH', 0.0000, 0, 'Active', '2026-04-26 08:42:23'),
(51, 'Loan Condonation Per Conn', '2026-04', 'Per_Customer', 0.0000, 0, 'Active', '2026-04-26 08:42:23'),
(52, 'Power Cost Adj. Refund', '2026-04', 'Per_KWH', 0.0000, 0, 'Active', '2026-04-26 08:42:23'),
(53, 'Rein. Fund for Sus. CAPEX', '2026-04', 'Per_KWH', 0.2904, 0, 'Active', '2026-04-26 08:42:23'),
(54, 'Senior Citizen Subsidy', '2026-04', 'Per_KWH', 0.0001, 0, 'Active', '2026-04-26 08:42:23'),
(55, 'Generation VAT', '2026-04', 'Per_KWH', 0.8048, 0, 'Active', '2026-04-26 08:42:23'),
(56, 'Transmission VAT', '2026-04', 'Per_KWH', 0.2323, 0, 'Active', '2026-04-26 08:42:23'),
(57, 'DSM VAT', '2026-04', 'Per_KWH', 0.1992, 0, 'Active', '2026-04-26 08:42:23'),
(58, 'Dev Fee', '2026-04', 'Per_KWH', 2.1000, 0, 'Archived', '2026-04-27 02:25:30'),
(59, 'web dev fee', '2026-04', 'Per_Customer', 10.5000, 0, 'Active', '2026-04-27 02:34:01');

-- --------------------------------------------------------

--
-- Table structure for table `clients`
--

CREATE TABLE `clients` (
  `client_id` int(11) NOT NULL,
  `account_no` varchar(20) NOT NULL,
  `id_number` varchar(10) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `middle_name` varchar(50) DEFAULT NULL,
  `date_of_birth` date NOT NULL,
  `address` text NOT NULL,
  `contact_number` varchar(20) NOT NULL,
  `meter_no` varchar(50) NOT NULL,
  `consumer_type` enum('RESIDENTIAL','COMMERCIAL','INDUSTRIAL') DEFAULT 'RESIDENTIAL',
  `status` enum('Connected','Disconnected') DEFAULT 'Connected',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `clients`
--

INSERT INTO `clients` (`client_id`, `account_no`, `id_number`, `password_hash`, `last_name`, `first_name`, `middle_name`, `date_of_birth`, `address`, `contact_number`, `meter_no`, `consumer_type`, `status`, `created_at`) VALUES
(1, '26-328-66378', '2604267980', '$2y$10$xuGBXMcA7BXJxBNjZI1T3.cQO.pkEaqmPJdoCLQO/yCQH0tw/RMge', 'LUNA', 'CHRISTIAN MARK', '', '2004-09-21', 'BANTOD, Brgy. San Pedro (Pob.), Binalbagan', '09649644695', '260426843', 'RESIDENTIAL', 'Disconnected', '2026-04-26 07:29:17'),
(2, '26-362-81351', '2604266585', '$2y$10$fsmNQgti2gw2mBIU557.WuLT/oifxnDIzMf74QtSA.Rrzr3qfJ/6C', 'DINGCONG', 'KERT BRYAN', '', '2004-05-13', 'PRK 4, Brgy. Sara-et, City Of Himamaylan', '09649644695', '260426875', 'RESIDENTIAL', 'Connected', '2026-04-26 11:04:27'),
(3, '26-374-49571', '2604261327', '$2y$10$HjKsYCfF0z2M2bFIrkSZTezXk870VfgMNbdE7XsqMRa/g.OATbC42', 'ATIDO', 'ALYN', '', '2004-12-12', 'CROSSING BAG O, Brgy. Bagroy, Binalbagan', '09649644695', '260426208', 'RESIDENTIAL', 'Connected', '2026-04-26 11:14:08'),
(4, '26-300-70123', '2604274467', '$2y$10$W2nPrLZl2hH/L8tGWaz.TudSKEyPnaWlbnagQzyU09U5CROeU1gyK', 'GEREZOLA', 'GRAZIE', '', '2001-02-15', 'Sambag, Brgy. Bi-ao, Binalbagan', '09162587231', '260427361', 'RESIDENTIAL', 'Connected', '2026-04-27 02:35:39');

-- --------------------------------------------------------

--
-- Table structure for table `invoice_line_items`
--

CREATE TABLE `invoice_line_items` (
  `line_item_id` int(11) NOT NULL,
  `invoice_no` varchar(20) NOT NULL,
  `charge_description` varchar(100) NOT NULL,
  `rate_applied` decimal(10,4) NOT NULL,
  `calculated_amount` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `invoice_line_items`
--

INSERT INTO `invoice_line_items` (`line_item_id`, `invoice_no`, `charge_description`, `rate_applied`, `calculated_amount`) VALUES
(2, '2604262192', 'Generation System Charge', 6.6024, 838.50),
(3, '2604262192', 'Franchise/Benefit to Host', 0.0000, 0.00),
(4, '2604262192', 'GRAM', 0.0000, 0.00),
(5, '2604262192', 'ICERA', 0.0000, 0.00),
(6, '2604262192', 'Power Act Reduction', 0.0000, 0.00),
(7, '2604262192', 'Transmission Demand Charge', 0.0000, 0.00),
(8, '2604262192', 'Transmission System Charge', 1.8428, 234.04),
(9, '2604262192', 'System Loss Charge', 1.0503, 133.39),
(10, '2604262192', 'Distribution Demand Charge', 0.0000, 0.00),
(11, '2604262192', 'Distribution System Charge', 0.5782, 73.43),
(12, '2604262192', 'Supply Retail Cust. Charge', 0.0000, 0.00),
(13, '2604262192', 'Supply System Charge', 0.6001, 76.21),
(14, '2604262192', 'Metering Retail Charge', 5.0000, 5.00),
(15, '2604262192', 'Metering System Charge', 0.4326, 54.94),
(16, '2604262192', 'Missionary Electrification', 0.2763, 35.09),
(17, '2604262192', 'Environmental Charge', 0.0025, 0.32),
(18, '2604262192', 'NPC Stranded Contract Cost', 0.0000, 0.00),
(19, '2604262192', 'NPC Stranded Debts', 0.0428, 5.44),
(20, '2604262192', 'UC - Fit All', 0.2011, 25.54),
(21, '2604262192', 'GEA-ALL', 0.0371, 4.71),
(22, '2604262192', 'REC Recovery', 0.0181, 2.30),
(23, '2604262192', 'Inter Class Cross Subsidy', 0.0000, 0.00),
(24, '2604262192', 'Lifeline Rate Subsidy', 0.0100, 1.27),
(25, '2604262192', 'Loan Condonation Per KWH', 0.0000, 0.00),
(26, '2604262192', 'Loan Condonation Per Conn', 0.0000, 0.00),
(27, '2604262192', 'Power Cost Adj. Refund', 0.0000, 0.00),
(28, '2604262192', 'Rein. Fund for Sus. CAPEX', 0.2904, 36.88),
(29, '2604262192', 'Senior Citizen Subsidy', 0.0001, 0.01),
(30, '2604262192', 'Generation VAT', 0.8048, 102.21),
(31, '2604262192', 'Transmission VAT', 0.2323, 29.50),
(32, '2604262192', 'DSM VAT', 0.1992, 25.30),
(64, '2604265356', 'Generation System Charge', 6.6024, 6.60),
(65, '2604265356', 'Franchise/Benefit to Host', 0.0000, 0.00),
(66, '2604265356', 'GRAM', 0.0000, 0.00),
(67, '2604265356', 'ICERA', 0.0000, 0.00),
(68, '2604265356', 'Power Act Reduction', 0.0000, 0.00),
(69, '2604265356', 'Transmission Demand Charge', 0.0000, 0.00),
(70, '2604265356', 'Transmission System Charge', 1.8428, 1.84),
(71, '2604265356', 'System Loss Charge', 1.0503, 1.05),
(72, '2604265356', 'Distribution Demand Charge', 0.0000, 0.00),
(73, '2604265356', 'Distribution System Charge', 0.5782, 0.58),
(74, '2604265356', 'Supply Retail Cust. Charge', 0.0000, 0.00),
(75, '2604265356', 'Supply System Charge', 0.6001, 0.60),
(76, '2604265356', 'Metering Retail Charge', 5.0000, 5.00),
(77, '2604265356', 'Metering System Charge', 0.4326, 0.43),
(78, '2604265356', 'Missionary Electrification', 0.2763, 0.28),
(79, '2604265356', 'Environmental Charge', 0.0025, 0.00),
(80, '2604265356', 'NPC Stranded Contract Cost', 0.0000, 0.00),
(81, '2604265356', 'NPC Stranded Debts', 0.0428, 0.04),
(82, '2604265356', 'UC - Fit All', 0.2011, 0.20),
(83, '2604265356', 'GEA-ALL', 0.0371, 0.04),
(84, '2604265356', 'REC Recovery', 0.0181, 0.02),
(85, '2604265356', 'Inter Class Cross Subsidy', 0.0000, 0.00),
(86, '2604265356', 'Lifeline Rate Subsidy', 0.0100, 0.01),
(87, '2604265356', 'Loan Condonation Per KWH', 0.0000, 0.00),
(88, '2604265356', 'Loan Condonation Per Conn', 0.0000, 0.00),
(89, '2604265356', 'Power Cost Adj. Refund', 0.0000, 0.00),
(90, '2604265356', 'Rein. Fund for Sus. CAPEX', 0.2904, 0.29),
(91, '2604265356', 'Senior Citizen Subsidy', 0.0001, 0.00),
(92, '2604265356', 'Generation VAT', 0.8048, 0.80),
(93, '2604265356', 'Transmission VAT', 0.2323, 0.23),
(94, '2604265356', 'DSM VAT', 0.1992, 0.20),
(95, '2604264472', 'Generation System Charge', 6.6024, 0.66),
(96, '2604264472', 'Franchise/Benefit to Host', 0.0000, 0.00),
(97, '2604264472', 'GRAM', 0.0000, 0.00),
(98, '2604264472', 'ICERA', 0.0000, 0.00),
(99, '2604264472', 'Power Act Reduction', 0.0000, 0.00),
(100, '2604264472', 'Transmission Demand Charge', 0.0000, 0.00),
(101, '2604264472', 'Transmission System Charge', 1.8428, 0.18),
(102, '2604264472', 'System Loss Charge', 1.0503, 0.11),
(103, '2604264472', 'Distribution Demand Charge', 0.0000, 0.00),
(104, '2604264472', 'Distribution System Charge', 0.5782, 0.06),
(105, '2604264472', 'Supply Retail Cust. Charge', 0.0000, 0.00),
(106, '2604264472', 'Supply System Charge', 0.6001, 0.06),
(107, '2604264472', 'Metering Retail Charge', 5.0000, 5.00),
(108, '2604264472', 'Metering System Charge', 0.4326, 0.04),
(109, '2604264472', 'Missionary Electrification', 0.2763, 0.03),
(110, '2604264472', 'Environmental Charge', 0.0025, 0.00),
(111, '2604264472', 'NPC Stranded Contract Cost', 0.0000, 0.00),
(112, '2604264472', 'NPC Stranded Debts', 0.0428, 0.00),
(113, '2604264472', 'UC - Fit All', 0.2011, 0.02),
(114, '2604264472', 'GEA-ALL', 0.0371, 0.00),
(115, '2604264472', 'REC Recovery', 0.0181, 0.00),
(116, '2604264472', 'Inter Class Cross Subsidy', 0.0000, 0.00),
(117, '2604264472', 'Lifeline Rate Subsidy', 0.0100, 0.00),
(118, '2604264472', 'Loan Condonation Per KWH', 0.0000, 0.00),
(119, '2604264472', 'Loan Condonation Per Conn', 0.0000, 0.00),
(120, '2604264472', 'Power Cost Adj. Refund', 0.0000, 0.00),
(121, '2604264472', 'Rein. Fund for Sus. CAPEX', 0.2904, 0.03),
(122, '2604264472', 'Senior Citizen Subsidy', 0.0001, 0.00),
(123, '2604264472', 'Generation VAT', 0.8048, 0.08),
(124, '2604264472', 'Transmission VAT', 0.2323, 0.02),
(125, '2604264472', 'DSM VAT', 0.1992, 0.02),
(190, '2604276849', 'Generation System Charge', 6.6024, 831.90),
(191, '2604276849', 'Franchise/Benefit to Host', 0.0000, 0.00),
(192, '2604276849', 'GRAM', 0.0000, 0.00),
(193, '2604276849', 'ICERA', 0.0000, 0.00),
(194, '2604276849', 'Power Act Reduction', 0.0000, 0.00),
(195, '2604276849', 'Transmission Demand Charge', 0.0000, 0.00),
(196, '2604276849', 'Transmission System Charge', 1.8428, 232.19),
(197, '2604276849', 'System Loss Charge', 1.0503, 132.34),
(198, '2604276849', 'Distribution Demand Charge', 0.0000, 0.00),
(199, '2604276849', 'Distribution System Charge', 0.5782, 72.85),
(200, '2604276849', 'Supply Retail Cust. Charge', 0.0000, 0.00),
(201, '2604276849', 'Supply System Charge', 0.6001, 75.61),
(202, '2604276849', 'Metering Retail Charge', 5.0000, 5.00),
(203, '2604276849', 'Metering System Charge', 0.4326, 54.51),
(204, '2604276849', 'Missionary Electrification', 0.2763, 34.81),
(205, '2604276849', 'Environmental Charge', 0.0025, 0.32),
(206, '2604276849', 'NPC Stranded Contract Cost', 0.0000, 0.00),
(207, '2604276849', 'NPC Stranded Debts', 0.0428, 5.39),
(208, '2604276849', 'UC - Fit All', 0.2011, 25.34),
(209, '2604276849', 'GEA-ALL', 0.0371, 4.67),
(210, '2604276849', 'REC Recovery', 0.0181, 2.28),
(211, '2604276849', 'Inter Class Cross Subsidy', 0.0000, 0.00),
(212, '2604276849', 'Lifeline Rate Subsidy', 0.0100, 1.26),
(213, '2604276849', 'Loan Condonation Per KWH', 0.0000, 0.00),
(214, '2604276849', 'Loan Condonation Per Conn', 0.0000, 0.00),
(215, '2604276849', 'Power Cost Adj. Refund', 0.0000, 0.00),
(216, '2604276849', 'Rein. Fund for Sus. CAPEX', 0.2904, 36.59),
(217, '2604276849', 'Senior Citizen Subsidy', 0.0001, 0.01),
(218, '2604276849', 'Generation VAT', 0.8048, 101.40),
(219, '2604276849', 'Transmission VAT', 0.2323, 29.27),
(220, '2604276849', 'DSM VAT', 0.1992, 25.10),
(221, '2604276849', 'web dev fee', 10.5000, 10.50),
(222, '2604271884', 'Generation System Charge', 6.6024, 825.30),
(223, '2604271884', 'Franchise/Benefit to Host', 0.0000, 0.00),
(224, '2604271884', 'GRAM', 0.0000, 0.00),
(225, '2604271884', 'ICERA', 0.0000, 0.00),
(226, '2604271884', 'Power Act Reduction', 0.0000, 0.00),
(227, '2604271884', 'Transmission Demand Charge', 0.0000, 0.00),
(228, '2604271884', 'Transmission System Charge', 1.8428, 230.35),
(229, '2604271884', 'System Loss Charge', 1.0503, 131.29),
(230, '2604271884', 'Distribution Demand Charge', 0.0000, 0.00),
(231, '2604271884', 'Distribution System Charge', 0.5782, 72.28),
(232, '2604271884', 'Supply Retail Cust. Charge', 0.0000, 0.00),
(233, '2604271884', 'Supply System Charge', 0.6001, 75.01),
(234, '2604271884', 'Metering Retail Charge', 5.0000, 5.00),
(235, '2604271884', 'Metering System Charge', 0.4326, 54.08),
(236, '2604271884', 'Missionary Electrification', 0.2763, 34.54),
(237, '2604271884', 'Environmental Charge', 0.0025, 0.31),
(238, '2604271884', 'NPC Stranded Contract Cost', 0.0000, 0.00),
(239, '2604271884', 'NPC Stranded Debts', 0.0428, 5.35),
(240, '2604271884', 'UC - Fit All', 0.2011, 25.14),
(241, '2604271884', 'GEA-ALL', 0.0371, 4.64),
(242, '2604271884', 'REC Recovery', 0.0181, 2.26),
(243, '2604271884', 'Inter Class Cross Subsidy', 0.0000, 0.00),
(244, '2604271884', 'Lifeline Rate Subsidy', 0.0100, 1.25),
(245, '2604271884', 'Loan Condonation Per KWH', 0.0000, 0.00),
(246, '2604271884', 'Loan Condonation Per Conn', 0.0000, 0.00),
(247, '2604271884', 'Power Cost Adj. Refund', 0.0000, 0.00),
(248, '2604271884', 'Rein. Fund for Sus. CAPEX', 0.2904, 36.30),
(249, '2604271884', 'Senior Citizen Subsidy', 0.0001, 0.01),
(250, '2604271884', 'Generation VAT', 0.8048, 100.60),
(251, '2604271884', 'Transmission VAT', 0.2323, 29.04),
(252, '2604271884', 'DSM VAT', 0.1992, 24.90),
(253, '2604271884', 'web dev fee', 10.5000, 10.50);

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `payment_id` varchar(50) NOT NULL,
  `invoice_no` varchar(20) NOT NULL,
  `account_no` varchar(20) NOT NULL,
  `amount_paid` decimal(10,2) NOT NULL,
  `payment_method` varchar(20) DEFAULT 'GCash',
  `payment_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('Success','Pending','Failed','Voided') NOT NULL DEFAULT 'Pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`payment_id`, `invoice_no`, `account_no`, `amount_paid`, `payment_method`, `payment_date`, `status`) VALUES
('CASH-69EE0EE8CC709', '2604262192', '26-328-66378', 1684.08, 'Over-the-Counter', '2026-04-26 13:11:04', 'Success'),
('CASH-69EE16FD0996B', '2604264472', '26-374-49571', 6.64, 'Over-the-Counter', '2026-04-26 13:45:33', 'Success'),
('CASH-69EED331A6B61', '2604265356', '26-362-81351', 18.22, 'Over-the-Counter', '2026-04-27 03:08:33', 'Voided'),
('CASH-69EED8A817691', '2604262192', '26-328-66378', 1684.08, 'Over-the-Counter', '2026-04-27 03:31:52', 'Voided'),
('CASH-69EEE5085431C', '2604265356', '26-362-81351', 19.13, 'Over-the-Counter', '2026-04-27 04:24:40', 'Success'),
('CASH-69EF570D7AB71', '2604262192', '26-328-66378', 1684.08, 'Over-the-Counter', '2026-04-27 12:31:09', 'Success');

-- --------------------------------------------------------

--
-- Table structure for table `sms_logs`
--

CREATE TABLE `sms_logs` (
  `log_id` int(11) NOT NULL,
  `account_no` varchar(20) NOT NULL,
  `contact_number` varchar(20) NOT NULL,
  `message_type` enum('New Bill','Due Date Reminder','Overdue Alert','Payment Success') NOT NULL,
  `message_content` text NOT NULL,
  `sent_status` enum('Sent','Failed') DEFAULT 'Sent',
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sms_logs`
--

INSERT INTO `sms_logs` (`log_id`, `account_no`, `contact_number`, `message_type`, `message_content`, `sent_status`, `sent_at`) VALUES
(12, '26-328-66378', '09649644695', 'Payment Success', 'NOCECO: We received your payment of Php 1,684.08 for Invoice(s): 2604262192. Thank you!', 'Sent', '2026-04-27 03:23:34'),
(13, '26-328-66378', '09649644695', 'Payment Success', 'NOCECO: We received your payment of Php 1,684.08 for Invoice(s): 2604262192. Thank you!', 'Sent', '2026-04-27 03:31:52'),
(14, '26-362-81351', '09649644695', 'New Bill', 'NOCECO Alert: Your bill for April 2026 is Php 1,681.36. Due date is 2026-05-06. KWH Used: 126.', 'Sent', '2026-04-27 03:58:58'),
(15, '26-362-81351', '09649644695', 'Payment Success', 'NOCECO: We received your payment of Php 19.13 for Invoice(s): 2604265356. Thank you!', 'Sent', '2026-04-27 04:24:40'),
(16, '26-328-66378', '09649644695', 'Payment Success', 'NOCECO: We received your payment of Php 1,684.08 for Invoice(s): 2604262192. Thank you!', 'Sent', '2026-04-27 12:31:09'),
(17, '26-300-70123', '09162587231', 'New Bill', 'NOCECO Alert: Your bill for April 2026 is Php 1,668.14. Due date is 2026-05-06. KWH Used: 125.', 'Sent', '2026-04-27 12:43:06');

-- --------------------------------------------------------

--
-- Table structure for table `system_staff`
--

CREATE TABLE `system_staff` (
  `staff_id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `role` enum('Main Administrator','Cashier','Meter Reader') NOT NULL,
  `status` enum('Active','Inactive') DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `system_staff`
--

INSERT INTO `system_staff` (`staff_id`, `username`, `password_hash`, `full_name`, `role`, `status`, `created_at`) VALUES
(1, 'kert', '$2y$10$5t9WwnBwC5jEMPVUytwREeEdJeeWB1hKwbbNifau7X7j/ddd9euwC', 'KERT BRYAN DINGCONG', 'Main Administrator', 'Active', '2026-04-26 05:38:23'),
(2, 'john123', '$2y$10$jpUnRViwM414hMrHob9i6OZJZxjjy2ZQ1PF0I17CAjrAyp9hGWu8O', 'JOHN DOE', 'Meter Reader', 'Active', '2026-04-26 07:40:34'),
(3, 'alyn', '$2y$10$.KOh57O.W9PeOX44pFC19ud9yEnQxrd.1hsD0e3ApUu0f2huYYMKK', 'ALYN ATIDO', 'Cashier', 'Active', '2026-04-26 13:10:03');

-- --------------------------------------------------------

--
-- Table structure for table `website_content`
--

CREATE TABLE `website_content` (
  `id` int(11) NOT NULL,
  `type` varchar(50) NOT NULL COMMENT 'Announcement, Rate, Blog, News, Interruption',
  `title` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `image_url` varchar(255) DEFAULT NULL,
  `status` varchar(20) DEFAULT 'Active' COMMENT 'Active, Inactive',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `website_content`
--

INSERT INTO `website_content` (`id`, `type`, `title`, `content`, `image_url`, `status`, `created_at`) VALUES
(1, 'Announcements', 'SCHEDULED POWER INTERRUPTION: Kabankalan City', 'Please be informed of a scheduled power interruption on Saturday, May 2, 2026, from 8:00 AM to 5:00 PM affecting Brgy. Binicuil and Brgy. Talubangi. Reason: Comprehensive line clearing and replacement of aging poles. Power may be restored earlier than scheduled.', '', 'Active', '2026-04-27 21:48:30'),
(2, 'Articles', 'NOCECO Energizes 50 New Households in Sitio Mag-amay', 'In line with our mandate of total rural electrification, NOCECO has successfully energized 50 households in Sitio Mag-amay, Binalbagan. This milestone is part of the Sitio Electrification Program (SEP) in partnership with the National Electrification Administration (NEA). We remain committed to bringing light and progress to the farthest communities in Southern Negros.', '', 'Active', '2026-04-27 21:48:50'),
(3, 'Rates', 'MAY 2026 BILLING RATES UPDATE', 'For the billing month of May 2026, the effective residential rate is Php 12.45/kWh. This reflects a slight decrease of Php 0.05 from last month due to lower generation charges in the wholesale electricity spot market (WESM). Please log in to your Consumer Portal to view your updated statements.', '', 'Active', '2026-04-27 21:49:08'),
(4, 'Pictures', '24/7 Line Maintenance at Himamaylan Substation', 'Image Upload', '', 'Active', '2026-04-27 21:49:50'),
(5, 'Pictures', 'TRY', 'Image Upload', 'uploads/1777298964_negocc-noneco-power-lines-210624.jpg', 'Active', '2026-04-27 22:09:24');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `billing_invoices`
--
ALTER TABLE `billing_invoices`
  ADD PRIMARY KEY (`invoice_no`),
  ADD KEY `account_no` (`account_no`),
  ADD KEY `meter_reader_id` (`meter_reader_id`);

--
-- Indexes for table `billing_rates_catalog`
--
ALTER TABLE `billing_rates_catalog`
  ADD PRIMARY KEY (`rate_id`);

--
-- Indexes for table `clients`
--
ALTER TABLE `clients`
  ADD PRIMARY KEY (`client_id`),
  ADD UNIQUE KEY `account_no` (`account_no`),
  ADD UNIQUE KEY `id_number` (`id_number`);

--
-- Indexes for table `invoice_line_items`
--
ALTER TABLE `invoice_line_items`
  ADD PRIMARY KEY (`line_item_id`),
  ADD KEY `invoice_no` (`invoice_no`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`payment_id`),
  ADD KEY `invoice_no` (`invoice_no`);

--
-- Indexes for table `sms_logs`
--
ALTER TABLE `sms_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `account_no` (`account_no`);

--
-- Indexes for table `system_staff`
--
ALTER TABLE `system_staff`
  ADD PRIMARY KEY (`staff_id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `website_content`
--
ALTER TABLE `website_content`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `billing_rates_catalog`
--
ALTER TABLE `billing_rates_catalog`
  MODIFY `rate_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=60;

--
-- AUTO_INCREMENT for table `clients`
--
ALTER TABLE `clients`
  MODIFY `client_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `invoice_line_items`
--
ALTER TABLE `invoice_line_items`
  MODIFY `line_item_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=254;

--
-- AUTO_INCREMENT for table `sms_logs`
--
ALTER TABLE `sms_logs`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `system_staff`
--
ALTER TABLE `system_staff`
  MODIFY `staff_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `website_content`
--
ALTER TABLE `website_content`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `billing_invoices`
--
ALTER TABLE `billing_invoices`
  ADD CONSTRAINT `billing_invoices_ibfk_1` FOREIGN KEY (`account_no`) REFERENCES `clients` (`account_no`),
  ADD CONSTRAINT `billing_invoices_ibfk_2` FOREIGN KEY (`meter_reader_id`) REFERENCES `system_staff` (`staff_id`) ON DELETE SET NULL;

--
-- Constraints for table `invoice_line_items`
--
ALTER TABLE `invoice_line_items`
  ADD CONSTRAINT `invoice_line_items_ibfk_1` FOREIGN KEY (`invoice_no`) REFERENCES `billing_invoices` (`invoice_no`) ON DELETE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`invoice_no`) REFERENCES `billing_invoices` (`invoice_no`);

--
-- Constraints for table `sms_logs`
--
ALTER TABLE `sms_logs`
  ADD CONSTRAINT `sms_logs_ibfk_1` FOREIGN KEY (`account_no`) REFERENCES `clients` (`account_no`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
