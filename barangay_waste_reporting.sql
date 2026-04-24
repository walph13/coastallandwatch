-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Apr 06, 2026 at 05:17 PM
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
-- Database: `barangay_waste_reporting`
--

-- --------------------------------------------------------

--
-- Table structure for table `barangay_information`
--

CREATE TABLE `barangay_information` (
  `id` int(11) NOT NULL,
  `barangay_name` varchar(100) NOT NULL,
  `municipality` varchar(100) NOT NULL,
  `province` varchar(100) NOT NULL,
  `captain_name` varchar(150) NOT NULL,
  `contact_email` varchar(150) NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `logo_path` varchar(255) DEFAULT NULL,
  `contact_number` varchar(50) DEFAULT NULL,
  `full_address` varchar(255) DEFAULT NULL,
  `zip_code` varchar(20) DEFAULT NULL,
  `region` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `barangay_information`
--

INSERT INTO `barangay_information` (`id`, `barangay_name`, `municipality`, `province`, `captain_name`, `contact_email`, `updated_at`, `logo_path`, `contact_number`, `full_address`, `zip_code`, `region`) VALUES
(1, 'abc', 'Estancia', 'Iloilo', 'Captain Juan Dela Cruz', 'tanza@gmail.com', '2026-03-19 20:39:51', '1774278948_Screenshot (260).png', '09309593609', 'Barangay Tanza ....', '5017', 'Region VI');

-- --------------------------------------------------------

--
-- Table structure for table `basura_alerts`
--

CREATE TABLE `basura_alerts` (
  `alert_id` int(11) NOT NULL,
  `purok_area` varchar(100) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `message` text NOT NULL DEFAULT 'The garbage truck is near your area!',
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `basura_alerts`
--

INSERT INTO `basura_alerts` (`alert_id`, `purok_area`, `admin_id`, `message`, `sent_at`) VALUES
(1, 'Purok Uno', 2, 'The garbage truck is near your area! Please bring out your trash.', '2026-03-17 10:51:30'),
(2, 'All Areas', 2, 'The garbage truck is near your area! Please bring out your trash.', '2026-03-17 11:42:58'),
(3, 'All Areas', 2, 'yohoo', '2026-03-23 10:06:51'),
(4, 'Purok Tres', 2, 'The garbage truck is near your area! Please bring out your trash.', '2026-03-25 13:11:04');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(150) NOT NULL,
  `address_purok_sitio` varchar(255) NOT NULL,
  `date_of_birth` date DEFAULT NULL,
  `phone_number` varchar(100) NOT NULL,
  `id_photo_path` varchar(255) NOT NULL,
  `role` enum('Admin','Resident','','') NOT NULL,
  `account_status` enum('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `full_name`, `username`, `password`, `email`, `address_purok_sitio`, `date_of_birth`, `phone_number`, `id_photo_path`, `role`, `account_status`) VALUES
(2, 'Barangay Secretary', 'admin_tanza', '$2y$10$IdUqTj032tWPZ1f83wCoJ.dGMmEFEsTsMPLECgD4kPHRqscoLoUJO', '', '', NULL, '', '', 'Admin', ''),
(14, 'testuser1', 'testuser1', '$2y$10$XCzexxDSF4OaDV9pW9JX/.Y36lBemMPtsLeKPEbMH.ndqfaPpu6f6', 'yellyhazee69@outlook.com', 'testuser1', '1111-11-11', '09309593609', '1774266217_123.jpg', 'Resident', 'Approved'),
(15, 'testuser2', 'testuser2', '$2y$10$X9UpTglyTntZ6ntuP2cK3eEeTdjxK1ZSgrORdzVLr9c7S.5GQgY5e', 'yellyhazee69@outlook.com', 'testuser2', '1111-02-22', '09309593609', '1774274118_Screenshot (260).png', 'Resident', 'Pending');

-- --------------------------------------------------------

--
-- Table structure for table `waste_reports`
--

CREATE TABLE `waste_reports` (
  `report_id` int(11) NOT NULL,
  `resident_id` int(11) NOT NULL,
  `latitude` decimal(10,8) NOT NULL,
  `longitude` decimal(11,8) NOT NULL,
  `description` text NOT NULL,
  `location_description` text NOT NULL,
  `before_photo_path` varchar(255) NOT NULL,
  `after_photo_path` varchar(255) DEFAULT NULL,
  `status` enum('Pending','Ongoing','Cleaned','Confirmed','DEFAULT ''Pending''') NOT NULL DEFAULT 'Pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `waste_reports`
--

INSERT INTO `waste_reports` (`report_id`, `resident_id`, `latitude`, `longitude`, `description`, `location_description`, `before_photo_path`, `after_photo_path`, `status`, `created_at`) VALUES
(13, 14, 11.43496542, 123.13808084, 'wwww', '', '1774268460_541161518_1292926421666878_340286137083821170_n.jpg', '1774268491_after_123.jpg', 'Cleaned', '2026-03-23 12:21:00'),
(14, 14, 11.42655621, 123.13626766, '222', '', '1774274154_Screenshot (260).png', NULL, 'Pending', '2026-03-23 13:55:54');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `barangay_information`
--
ALTER TABLE `barangay_information`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `basura_alerts`
--
ALTER TABLE `basura_alerts`
  ADD PRIMARY KEY (`alert_id`),
  ADD KEY `admin_id` (`admin_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `waste_reports`
--
ALTER TABLE `waste_reports`
  ADD PRIMARY KEY (`report_id`),
  ADD KEY `resident_id` (`resident_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `barangay_information`
--
ALTER TABLE `barangay_information`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `basura_alerts`
--
ALTER TABLE `basura_alerts`
  MODIFY `alert_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `waste_reports`
--
ALTER TABLE `waste_reports`
  MODIFY `report_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `basura_alerts`
--
ALTER TABLE `basura_alerts`
  ADD CONSTRAINT `basura_alerts_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `waste_reports`
--
ALTER TABLE `waste_reports`
  ADD CONSTRAINT `waste_reports_ibfk_1` FOREIGN KEY (`resident_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
