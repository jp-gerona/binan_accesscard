-- MariaDB dump 10.19  Distrib 10.4.28-MariaDB, for osx10.10 (x86_64)
--
-- Host: 127.0.0.1    Database: accesscard
-- ------------------------------------------------------
-- Server version	10.4.28-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Current Database: `accesscard`
--

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `accesscard` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci */;

USE `accesscard`;

--
-- Table structure for table `audit_trails`
--

DROP TABLE IF EXISTS `audit_trails`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_trails` (
  `auditID` int(11) NOT NULL AUTO_INCREMENT,
  `user_action` text DEFAULT NULL,
  `description` text DEFAULT NULL,
  `full_description` text DEFAULT NULL,
  `ip_address` varchar(50) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `dt_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `userID` int(11) DEFAULT NULL,
  `memberID` int(11) DEFAULT NULL,
  PRIMARY KEY (`auditID`),
  KEY `fk_audit_user` (`userID`),
  KEY `fk_audit_member` (`memberID`),
  KEY `idx_audit_created` (`dt_created`),
  CONSTRAINT `fk_audit_member` FOREIGN KEY (`memberID`) REFERENCES `member` (`memberID`),
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`userID`) REFERENCES `users` (`userID`)
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `barangay`
--

DROP TABLE IF EXISTS `barangay`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `barangay` (
  `barangayID` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `dt_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `dt_deleted` datetime DEFAULT NULL,
  PRIMARY KEY (`barangayID`),
  UNIQUE KEY `uq_barangay_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=903 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `batch_barangay`
--

DROP TABLE IF EXISTS `batch_barangay`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `batch_barangay` (
  `batch_id` int(11) NOT NULL,
  `barangayID` int(11) NOT NULL,
  PRIMARY KEY (`batch_id`,`barangayID`),
  KEY `fk_bb_barangay` (`barangayID`),
  CONSTRAINT `fk_bb_barangay` FOREIGN KEY (`barangayID`) REFERENCES `barangay` (`barangayID`),
  CONSTRAINT `fk_bb_batch` FOREIGN KEY (`batch_id`) REFERENCES `distribution_batch` (`batch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `batch_eligibility`
--

DROP TABLE IF EXISTS `batch_eligibility`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `batch_eligibility` (
  `batch_id` int(11) NOT NULL,
  `headID` int(11) NOT NULL,
  PRIMARY KEY (`batch_id`,`headID`),
  KEY `idx_be_head` (`headID`),
  CONSTRAINT `fk_be_batch` FOREIGN KEY (`batch_id`) REFERENCES `distribution_batch` (`batch_id`),
  CONSTRAINT `fk_be_head` FOREIGN KEY (`headID`) REFERENCES `member` (`memberID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `batch_sector`
--

DROP TABLE IF EXISTS `batch_sector`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `batch_sector` (
  `batch_id` int(11) NOT NULL,
  `sectorID` int(11) NOT NULL,
  PRIMARY KEY (`batch_id`,`sectorID`),
  KEY `fk_bs_sector` (`sectorID`),
  CONSTRAINT `fk_bs_batch` FOREIGN KEY (`batch_id`) REFERENCES `distribution_batch` (`batch_id`),
  CONSTRAINT `fk_bs_sector` FOREIGN KEY (`sectorID`) REFERENCES `sector` (`sectorID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `category`
--

DROP TABLE IF EXISTS `category`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `category` (
  `categoryID` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(30) NOT NULL,
  `name` varchar(150) NOT NULL,
  `dt_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `dt_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `dt_deleted` datetime DEFAULT NULL,
  PRIMARY KEY (`categoryID`),
  UNIQUE KEY `uq_category_code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `distribution_batch`
--

DROP TABLE IF EXISTS `distribution_batch`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `distribution_batch` (
  `batch_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `venue` varchar(150) NOT NULL DEFAULT '',
  `subsidy_type_id` int(11) NOT NULL,
  `scheduled_start` date NOT NULL DEFAULT '1970-01-01',
  `scheduled_end` date NOT NULL DEFAULT '1970-01-01',
  `daily_start_time` time NOT NULL DEFAULT '08:00:00',
  `daily_end_time` time NOT NULL DEFAULT '17:00:00',
  `color` varchar(16) NOT NULL DEFAULT 'green',
  `started_at` timestamp NULL DEFAULT NULL,
  `closed_at` timestamp NULL DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `eligible_count` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`batch_id`),
  KEY `idx_db_subsidy` (`subsidy_type_id`),
  KEY `idx_db_sched` (`scheduled_start`,`scheduled_end`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `job_queue`
--

DROP TABLE IF EXISTS `job_queue`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `job_queue` (
  `jobID` int(11) NOT NULL AUTO_INCREMENT,
  `type` varchar(64) NOT NULL,
  `payload` longtext DEFAULT NULL,
  `status` enum('pending','processing','done','partial','failed') NOT NULL DEFAULT 'pending',
  `progress_total` int(11) NOT NULL DEFAULT 0,
  `progress_done` int(11) NOT NULL DEFAULT 0,
  `checkpoint` int(11) NOT NULL DEFAULT 0,
  `result_json` longtext DEFAULT NULL,
  `message` varchar(500) DEFAULT NULL,
  `userID` int(11) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `attempts` int(11) NOT NULL DEFAULT 0,
  `max_attempts` int(11) NOT NULL DEFAULT 1,
  `available_at` datetime DEFAULT NULL,
  `locked_at` datetime DEFAULT NULL,
  `locked_by` varchar(64) DEFAULT NULL,
  `dt_created` datetime NOT NULL,
  `dt_started` datetime DEFAULT NULL,
  `dt_finished` datetime DEFAULT NULL,
  PRIMARY KEY (`jobID`),
  KEY `idx_claim` (`status`,`available_at`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `member`
--

DROP TABLE IF EXISTS `member`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `member` (
  `memberID` int(11) NOT NULL AUTO_INCREMENT,
  `lastname` varchar(100) NOT NULL,
  `firstname` varchar(100) NOT NULL,
  `middlename` varchar(50) NOT NULL,
  `suffix` enum('JR','SR','I','II','III','IV','V') DEFAULT NULL,
  `birthday` date DEFAULT NULL,
  `civilstatus` varchar(100) DEFAULT NULL,
  `sex` enum('MALE','FEMALE') DEFAULT NULL,
  `education` text DEFAULT NULL,
  `job` text DEFAULT NULL,
  `salary` decimal(12,2) DEFAULT NULL,
  `contactnumber` varchar(20) DEFAULT NULL,
  `relationship` text DEFAULT NULL,
  `address` text DEFAULT NULL,
  `religion` varchar(100) DEFAULT NULL,
  `dt_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `dt_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `dt_deleted` timestamp NULL DEFAULT NULL,
  `headID` int(11) NOT NULL,
  `barangayID` int(11) DEFAULT NULL,
  PRIMARY KEY (`memberID`),
  KEY `fk_head` (`headID`),
  KEY `idx_member_deleted_name` (`dt_deleted`,`lastname`,`firstname`),
  KEY `idx_member_created` (`dt_created`),
  KEY `idx_member_brgy` (`barangayID`),
  CONSTRAINT `fk_head` FOREIGN KEY (`headID`) REFERENCES `member` (`memberID`),
  CONSTRAINT `fk_member_barangay` FOREIGN KEY (`barangayID`) REFERENCES `barangay` (`barangayID`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `member_sectors`
--

DROP TABLE IF EXISTS `member_sectors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `member_sectors` (
  `memberID` int(11) NOT NULL,
  `sectorID` int(11) NOT NULL,
  `dt_created` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`memberID`,`sectorID`),
  KEY `idx_ms_sector` (`sectorID`),
  CONSTRAINT `fk_ms_member` FOREIGN KEY (`memberID`) REFERENCES `member` (`memberID`),
  CONSTRAINT `fk_ms_sector` FOREIGN KEY (`sectorID`) REFERENCES `sector` (`sectorID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `member_services`
--

DROP TABLE IF EXISTS `member_services`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `member_services` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `dt_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `dt_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `serviceID` int(11) NOT NULL,
  `memberID` int(11) NOT NULL,
  PRIMARY KEY (`ID`),
  KEY `fk_service` (`serviceID`),
  KEY `fk_member` (`memberID`),
  CONSTRAINT `fk_member` FOREIGN KEY (`memberID`) REFERENCES `member` (`memberID`),
  CONSTRAINT `fk_service` FOREIGN KEY (`serviceID`) REFERENCES `services` (`serviceID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `qr_control`
--

DROP TABLE IF EXISTS `qr_control`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `qr_control` (
  `control_no` int(11) NOT NULL,
  `headID` int(11) NOT NULL,
  `dt_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `card_generated_at` timestamp NULL DEFAULT NULL,
  `card_generated_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`control_no`),
  KEY `idx_qr_head` (`headID`),
  KEY `idx_qr_generated` (`card_generated_at`),
  KEY `fk_qr_generated_by` (`card_generated_by`),
  CONSTRAINT `fk_qr_generated_by` FOREIGN KEY (`card_generated_by`) REFERENCES `users` (`userID`),
  CONSTRAINT `fk_qr_head` FOREIGN KEY (`headID`) REFERENCES `member` (`memberID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sector`
--

DROP TABLE IF EXISTS `sector`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sector` (
  `sectorID` int(11) NOT NULL AUTO_INCREMENT,
  `shortcode` varchar(30) NOT NULL,
  `name` text NOT NULL,
  `description` text NOT NULL,
  `dt_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `dt_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `dt_deleted` datetime DEFAULT NULL,
  PRIMARY KEY (`sectorID`),
  UNIQUE KEY `uq_sector_shortcode` (`shortcode`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `services`
--

DROP TABLE IF EXISTS `services`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `services` (
  `serviceID` int(11) NOT NULL AUTO_INCREMENT,
  `shortcode` varchar(30) DEFAULT NULL,
  `categoryID` int(11) DEFAULT NULL,
  `sectorID` int(11) DEFAULT NULL,
  `name` text DEFAULT NULL,
  `description` text DEFAULT NULL,
  `dt_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `dt_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `dt_deleted` datetime DEFAULT NULL,
  PRIMARY KEY (`serviceID`),
  UNIQUE KEY `uq_services_shortcode` (`shortcode`),
  KEY `idx_services_category` (`categoryID`),
  KEY `idx_services_sector` (`sectorID`),
  CONSTRAINT `fk_services_category` FOREIGN KEY (`categoryID`) REFERENCES `category` (`categoryID`),
  CONSTRAINT `fk_services_sector` FOREIGN KEY (`sectorID`) REFERENCES `sector` (`sectorID`),
  CONSTRAINT `chk_services_group` CHECK (`categoryID` is null <> (`sectorID` is null))
) ENGINE=InnoDB AUTO_INCREMENT=47 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `subsidy`
--

DROP TABLE IF EXISTS `subsidy`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `subsidy` (
  `subsidy_type_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `dt_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `dt_deleted` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`subsidy_type_id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `subsidy_distribution`
--

DROP TABLE IF EXISTS `subsidy_distribution`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `subsidy_distribution` (
  `distribution_id` int(11) NOT NULL AUTO_INCREMENT,
  `control_no` int(11) NOT NULL,
  `memberID` int(11) NOT NULL,
  `subsidy_type_id` int(11) NOT NULL,
  `claim_date` date NOT NULL,
  `userID` int(11) DEFAULT NULL,
  `batch_id` int(11) DEFAULT NULL,
  `dt_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `dt_voided` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`distribution_id`),
  KEY `idx_ad_control` (`control_no`),
  KEY `idx_ad_type` (`subsidy_type_id`),
  KEY `idx_ad_batch` (`batch_id`),
  KEY `idx_sd_batch_voided` (`batch_id`,`dt_voided`),
  KEY `fk_sd_member` (`memberID`),
  KEY `fk_sd_user` (`userID`),
  CONSTRAINT `fk_sd_batch` FOREIGN KEY (`batch_id`) REFERENCES `distribution_batch` (`batch_id`),
  CONSTRAINT `fk_sd_control` FOREIGN KEY (`control_no`) REFERENCES `qr_control` (`control_no`),
  CONSTRAINT `fk_sd_member` FOREIGN KEY (`memberID`) REFERENCES `member` (`memberID`),
  CONSTRAINT `fk_sd_type` FOREIGN KEY (`subsidy_type_id`) REFERENCES `subsidy` (`subsidy_type_id`),
  CONSTRAINT `fk_sd_user` FOREIGN KEY (`userID`) REFERENCES `users` (`userID`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `userID` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(255) NOT NULL,
  `full_description` varchar(255) DEFAULT NULL,
  `password` text NOT NULL,
  `account_level` enum('viewer','scanner','administrator','developer','encoder') NOT NULL DEFAULT 'encoder',
  `isactive` enum('Enable','Disabled') DEFAULT NULL,
  `dt_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `dt_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`userID`)
) ENGINE=InnoDB AUTO_INCREMENT=48 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
--
-- Reference data (lookup tables + the dev login accounts). No family,
-- member, QR, or distribution rows: those come from the Excel import or the
-- data entry form.
--

INSERT INTO `barangay` VALUES (1,'BIÑAN','2026-08-04 03:58:41',NULL),(2,'BUNGAHAN','2026-08-04 03:58:41',NULL),(3,'CANLALAY','2026-08-04 03:58:41',NULL),(4,'CASILE','2026-08-04 03:58:41',NULL),(5,'DE LA PAZ','2026-08-04 03:58:41',NULL),(6,'GANADO','2026-08-04 03:58:41',NULL),(7,'LANGKIWA','2026-08-04 03:58:41',NULL),(8,'LOMA','2026-08-04 03:58:41',NULL),(9,'MALABAN','2026-08-04 03:58:41',NULL),(10,'MALAMIG','2026-08-04 03:58:41',NULL),(11,'MAMPLASAN','2026-08-04 03:58:41',NULL),(12,'PLATERO','2026-08-04 03:58:41',NULL),(13,'POBLACION','2026-08-04 03:58:41',NULL),(14,'SAN ANTONIO','2026-08-04 03:58:41',NULL),(15,'SAN FRANCISCO','2026-08-04 03:58:41',NULL),(16,'SAN JOSE','2026-08-04 03:58:41',NULL),(17,'SAN VICENTE','2026-08-04 03:58:41',NULL),(18,'SANTO DOMINGO','2026-08-04 03:58:41',NULL),(19,'SANTO NIÑO','2026-08-04 03:58:41',NULL),(20,'SANTO TOMAS','2026-08-04 03:58:41',NULL),(21,'SORO-SORO','2026-08-04 03:58:41',NULL),(22,'TIMBAO','2026-08-04 03:58:41',NULL),(23,'TUBIGAN','2026-08-04 03:58:41',NULL),(24,'ZAPOTE','2026-08-04 03:58:41',NULL);
INSERT INTO `category` VALUES (5,'FA','FINANCIAL ASSISTANCE PROGRAMS','2026-07-01 01:13:55','2026-08-12 19:17:36',NULL),(6,'SWPS','SOCIAL WELFARE PROGRAMS AND SERVICES','2026-07-01 01:13:55','2026-08-12 19:17:36',NULL),(7,'EDA','EMERGENCY / DISASTER ASSISTANCE PROGRAMS','2026-07-01 01:13:55','2026-08-12 19:17:36',NULL);
INSERT INTO `sector` VALUES (1,'SC','SENIOR CITIZEN','Individuals aged 60 years old and above.','2026-07-01 01:13:55','2026-08-12 19:17:36',NULL),(2,'PWD','PERSON WITH DISABILITY','Persons with a long-term physical, mental, intellectual, or sensory disability.','2026-07-01 01:13:55','2026-08-12 19:17:36',NULL),(3,'SP','SOLO PARENT','Individuals raising one or more children on their own.','2026-07-01 01:13:55','2026-08-12 19:17:36',NULL),(4,'B','BATA (CHILDREN)','Minors below 18 years of age.','2026-07-01 01:13:55','2026-08-12 19:17:36',NULL),(5,'LGBT','LGBTQIA+','Members of the LGBTQIA+ community.','2026-07-01 01:13:55','2026-07-01 01:13:55',NULL),(6,'OFW','OVERSEAS FILIPINO WORKER','Filipinos working or residing abroad.','2026-07-01 01:13:55','2026-08-12 19:17:36',NULL),(7,'IP','INDIGENOUS PEOPLE','Members of indigenous cultural communities.','2026-07-01 01:13:55','2026-08-12 19:17:36',NULL),(8,'IDP','INTERNALLY DISPLACED PERSON','Persons forced to flee their homes but remaining within the country.','2026-07-01 01:13:55','2026-08-12 19:17:36',NULL),(9,'PDL','PERSONS DEPRIVED OF LIBERTY','Persons detained or under lawful custody.','2026-07-01 01:13:55','2026-08-12 19:17:36',NULL),(10,'OTHER','OTHER SECTORS','Sectors not covered by the listed categories.','2026-07-01 01:13:55','2026-08-12 19:17:36',NULL),(11,'IW','Informal Worker','Informal workers: seasonal, contractual, or self-employed persons without formal employment registration','2026-08-31 18:27:35','2026-08-31 18:27:35',NULL);
INSERT INTO `services` VALUES (1,'EDA1',7,NULL,'CASH ASSISTANCE','Financial aid for families affected by an emergency or disaster.','2026-06-29 07:22:54','2026-08-12 19:21:03',NULL),(2,'EDA2',7,NULL,'CASH FOR WORK','Temporary paid work for disaster-affected residents in community activities.','2026-06-29 07:22:54','2026-08-12 19:21:03',NULL),(3,'EDA3',7,NULL,'EMERGENCY SHELTER (LOCAL)','City-funded temporary shelter for displaced families.','2026-06-29 07:22:54','2026-08-12 19:21:03',NULL),(4,'EDA4',7,NULL,'EMERGENCY SHELTER (NATIONAL / NHA)','National Housing Authority shelter assistance for displaced families.','2026-06-29 07:22:54','2026-08-12 19:21:03',NULL),(5,'EDA5',7,NULL,'EMERGENCY SHELTER (PROVINCE)','Province-funded temporary shelter for displaced families.','2026-06-29 07:22:54','2026-08-12 19:21:03',NULL),(6,'EDA6',7,NULL,'FOOD FOR WORK','Food packs given in exchange for community work during emergencies.','2026-06-29 07:22:54','2026-08-12 19:21:03',NULL),(7,'EDA7',7,NULL,'NON-FOOD ASSISTANCE','Relief goods other than food, such as hygiene kits and blankets.','2026-06-29 07:22:54','2026-08-12 19:21:03',NULL),(8,'EDA8',7,NULL,'RELIEF FOOD PACK','Ready-to-distribute food packs for disaster-affected families.','2026-06-29 07:22:54','2026-08-12 19:21:03',NULL),(9,'EDA9',7,NULL,'TEMPORARY SHELTER','Short-term housing for families displaced by a disaster.','2026-06-29 07:22:54','2026-08-12 19:21:03',NULL),(10,'FA1',5,NULL,'BALIK PROBINSYA','Transportation and support to help residents return to their home province.','2026-06-29 07:22:54','2026-08-12 19:21:03',NULL),(11,'FA2',5,NULL,'BURIAL ASSISTANCE','Financial aid for funeral and burial expenses.','2026-06-29 07:22:54','2026-08-12 19:21:03',NULL),(12,'FA3',5,NULL,'DENTAL ASSISTANCE','Support for dental treatment and services.','2026-06-29 07:22:54','2026-08-12 19:21:03',NULL),(13,'FA4',5,NULL,'EYEGLASSES ASSISTANCE','Provision of prescription eyeglasses.','2026-06-29 07:22:54','2026-08-12 19:21:03',NULL),(14,'FA5',5,NULL,'LINGAP SA MAHIRAP','Emergency financial aid for indigent residents.','2026-06-29 07:22:54','2026-08-12 19:21:03',NULL),(15,'FA6',5,NULL,'MEDICAL ASSISTANCE','Financial aid for hospital bills, medicines, and treatment.','2026-06-29 07:22:54','2026-08-12 19:21:03',NULL),(16,'SWPS1',6,NULL,'BALAY SILANGAN','Community-based reformation program for recovering drug users.','2026-06-29 07:22:54','2026-08-12 19:21:03',NULL),(17,'SWPS2',6,NULL,'BUSINESS SKILLS MANAGEMENT TRAINING','Livelihood and entrepreneurship skills training.','2026-06-29 07:22:54','2026-08-12 19:21:03',NULL),(18,'SWPS3',6,NULL,'COUNSELING / DIALOGUE','Guidance and counseling sessions for individuals or families.','2026-06-29 07:22:54','2026-08-12 19:21:03',NULL),(19,'SWPS4',6,NULL,'FAMILY DEVELOPMENT SESSION','Regular sessions to strengthen family well-being.','2026-06-29 07:22:54','2026-08-12 19:21:03',NULL),(20,'SWPS5',6,NULL,'GENDER SENSITIVITY TRAINING','Training to promote gender awareness and equality.','2026-06-29 07:22:54','2026-08-12 19:21:03',NULL),(21,'SWPS6',6,NULL,'LEGAL ASSISTANCE / FREE NOTARY','Free legal advice and notarial services.','2026-06-29 07:22:54','2026-08-12 19:21:03',NULL),(22,'SWPS7',6,NULL,'LICENSED FOSTER PARENT','Support and accreditation for licensed foster parents.','2026-06-29 07:22:54','2026-08-12 19:21:03',NULL),(23,'SWPS8',6,NULL,'PAMASKONG HANDOG','Christmas gift-giving program for beneficiaries.','2026-06-29 07:22:54','2026-08-12 19:21:03',NULL),(24,'SWPS9',6,NULL,'PARENT EFFECTIVENESS SERVICE','Parenting skills and effectiveness training.','2026-06-29 07:22:54','2026-08-12 19:21:03',NULL),(25,'SWPS10',6,NULL,'PMOC (PRE-MARRIAGE ORIENTATION / COUNSELING)','Orientation and counseling for couples before marriage.','2026-06-29 07:22:54','2026-08-12 19:21:03',NULL),(26,'SWPS11',6,NULL,'REFERRAL','Referral to other agencies or services as needed.','2026-06-29 07:22:54','2026-08-12 19:21:03',NULL),(27,'4PS',6,NULL,'4PS (PANTAWID PAMILYANG PILIPINO PROGRAMS)','Conditional cash transfer for qualified poor families.','2026-06-29 07:22:54','2026-08-12 19:21:03',NULL),(28,'SC1',NULL,1,'REGISTERED OSCA BIÑAN','Senior citizens registered with the Office for Senior Citizens Affairs of Biñan.','2026-07-01 01:13:55','2026-08-12 19:21:03',NULL),(29,'SC2',NULL,1,'LOCAL PENSIONER','Senior citizens receiving a city-funded pension.','2026-07-01 01:13:55','2026-08-12 19:21:03',NULL),(30,'SC3',NULL,1,'NATIONAL PENSIONER','Senior citizens receiving a national government pension.','2026-07-01 01:13:55','2026-08-12 19:21:03',NULL),(31,'SC4',NULL,1,'CENTENARIAN LOCAL AWARDEE','Local cash award for residents reaching 100 years old.','2026-07-01 01:13:55','2026-08-12 19:21:03',NULL),(32,'SC5',NULL,1,'CENTENARIAN NATIONAL AWARDEE','National cash award for citizens reaching 100 years old.','2026-07-01 01:13:55','2026-08-12 19:21:03',NULL),(33,'SC6',NULL,1,'CENTENARIAN PROVINCE AWARDEE','Provincial cash award for residents reaching 100 years old.','2026-07-01 01:13:55','2026-08-12 19:21:03',NULL),(34,'SC7',NULL,1,'EYEGLASSES ASSISTANCE','Provision of prescription eyeglasses for senior citizens.','2026-07-01 01:13:55','2026-08-12 19:21:03',NULL),(35,'SC8',NULL,1,'ONE TIME CASH INCENTIVE (85YRS OLD)','One-time cash gift for senior citizens turning 85 years old.','2026-07-01 01:13:55','2026-08-12 19:21:03',NULL),(36,'SC9',NULL,1,'WHEELCHAIR / CRUTCHES','Provision of wheelchairs or crutches for senior citizens.','2026-07-01 01:13:55','2026-08-12 19:21:03',NULL),(37,'PWD1',NULL,2,'REGISTERED PWD IN BIÑAN','Persons with disability registered in Biñan.','2026-07-01 01:13:55','2026-08-12 19:21:03',NULL),(38,'PWD2',NULL,2,'BIÑAN CITY DEVELOPMENT CENTER','Services through the Biñan City Development Center for PWDs.','2026-07-01 01:13:55','2026-08-12 19:21:03',NULL),(39,'PWD3',NULL,2,'BIRTHDAY CASH GIFT','Birthday cash gift for registered PWDs.','2026-07-01 01:13:55','2026-08-12 19:21:03',NULL),(40,'PWD4',NULL,2,'PROJECT ARUGA','Care and support program for persons with disability.','2026-07-01 01:13:55','2026-08-12 19:21:03',NULL),(41,'PWD5',NULL,2,'SUBSIDY FOR UNEMPLOYABLE PWD','Monthly subsidy for persons with disability unable to work.','2026-07-01 01:13:55','2026-08-12 19:21:03',NULL),(42,'SP1',NULL,3,'REGISTERED SOLO PARENT IN BIÑAN','Solo parents registered in Biñan.','2026-07-01 01:13:55','2026-08-12 19:21:03',NULL),(43,'SP2',NULL,3,'MONTHLY SUBSIDY FOR SOLO PARENT','Monthly financial subsidy for qualified solo parents.','2026-07-01 01:13:55','2026-08-12 19:21:03',NULL),(44,'B1',NULL,4,'BAHAY PAG-ASA','Residential care facility for children in conflict with the law.','2026-07-01 01:13:55','2026-08-12 19:21:03',NULL),(45,'B2',NULL,4,'ECCD','Early Childhood Care and Development program for young children.','2026-07-01 01:13:55','2026-08-12 19:21:03',NULL),(46,'B3',NULL,4,'SUPPLEMENTARY FEEDING PROGRAM','Supplementary meals for undernourished children.','2026-07-01 01:13:55','2026-08-12 19:21:03',NULL);
INSERT INTO `subsidy` VALUES (1,'FINANCIAL','2026-07-29 03:22:29',NULL),(2,'RICE','2026-07-29 03:22:29',NULL),(3,'GROCERY','2026-07-29 03:22:29',NULL);
INSERT INTO `users` VALUES (1,'developer',NULL,'$argon2id$v=19$m=65536,t=4,p=1$SDJHU0p3NHF0L2hRQkhiZA$WQqhzKPvG3nBUNLwH4naRBjjwBoUunH8soeNEgeyvzk','developer','Enable','2026-07-12 22:05:26','2026-07-12 22:05:26'),(2,'Administrator1',NULL,'$argon2id$v=19$m=65536,t=4,p=1$QS4zaTQ5bWFNVC9GaG8zbA$LnM1Ll2YUyUk6tZeNjin0EEiEuZNU9dP4WK+cXyRUmw','administrator','Enable','2026-07-20 00:00:00','2026-07-20 00:00:00'),(3,'Administrator2',NULL,'$argon2id$v=19$m=65536,t=4,p=1$bFNKQ1h1V1JHQUl4czFpSg$xKfnElVSeJ/HV5bFQIE1wSQwqIfU2/81ofZdRgN4nlU','administrator','Enable','2026-07-20 00:00:00','2026-07-20 00:00:00'),(4,'Administrator3',NULL,'$argon2id$v=19$m=65536,t=4,p=1$OEJFTC9QOVduYzdEZ3V2WQ$s5Hqrnkqz+c2/kWo77JaQdkUEo9dpsXGw6vyUyiRkqU','administrator','Enable','2026-07-20 00:00:00','2026-07-20 00:00:00'),(5,'Administrator4',NULL,'$argon2id$v=19$m=65536,t=4,p=1$aUxIM2ExLjBYeDBSSEovNQ$DLEa3hnlKAI9Dw/V8gUw+YeV4Ih+q7v92NEt2gr5KQM','administrator','Enable','2026-07-20 00:00:00','2026-07-20 00:00:00'),(6,'Administrator5',NULL,'$argon2id$v=19$m=65536,t=4,p=1$MFNrVVgubFgxbnR0aUwwLw$QiiRhkG+GvNdtRn12BudXnQFWWgKsovkw40IJhzV6xA','administrator','Enable','2026-07-20 00:00:00','2026-07-20 00:00:00'),(7,'Scanner1',NULL,'$argon2id$v=19$m=65536,t=4,p=1$TVlWZTBxNWdncEdLMVRoTg$iiju6JG9/opGYjHKHpnEW10czPH7/9FdF241s2IUeIk','scanner','Enable','2026-07-20 00:00:00','2026-07-20 00:00:00'),(8,'Scanner2',NULL,'$argon2id$v=19$m=65536,t=4,p=1$UGQ4U2drU1pjbjJPbGNyLw$IChkuTvgwyoLRe37CZpT9STnN9HksmA1k1skxbIFCA0','scanner','Enable','2026-07-20 00:00:00','2026-07-20 00:00:00'),(9,'Scanner3',NULL,'$argon2id$v=19$m=65536,t=4,p=1$cjRiV0pFdGwwMFBqUi9XYw$bOukipVUow2Jgh4QRjPPR7P/kZKjvifwozbaqor/ne4','scanner','Enable','2026-07-20 00:00:00','2026-07-20 00:00:00'),(10,'Scanner4',NULL,'$argon2id$v=19$m=65536,t=4,p=1$dVVocXM3elo5TnNUQXlDbw$9Yn9YntDSW6MbKjWab8n7N+oYy/HhzgAoZZaaSJIubk','scanner','Enable','2026-07-20 00:00:00','2026-07-20 00:00:00'),(11,'Scanner5',NULL,'$argon2id$v=19$m=65536,t=4,p=1$U0pac1RTL0ZkMzg4LlUyeg$hZLcNgYwMjOYJx/Rj/mmuW0+Y4ECAef8+PCopMCiQUY','scanner','Enable','2026-07-20 00:00:00','2026-07-20 00:00:00'),(12,'Scanner6',NULL,'$argon2id$v=19$m=65536,t=4,p=1$ZDNLUURHZlNjYkFpREFGcQ$JLajJ3zSOEHRJ0WNpFaOVEjAe/PhNtkNKTT2IZon1rw','scanner','Enable','2026-07-20 00:00:00','2026-07-20 00:00:00'),(13,'Scanner7',NULL,'$argon2id$v=19$m=65536,t=4,p=1$LnQ2OU5jOXVSbncwSEdxZw$mXpPnxFyqeq6IuOBMbmiEc4/EURzpkgO5eAW/1Pz3As','scanner','Enable','2026-07-20 00:00:00','2026-07-20 00:00:00'),(14,'Scanner8',NULL,'$argon2id$v=19$m=65536,t=4,p=1$NDhNb0pQLzZidkFSMnZjLw$U3eCOhgLn8GmQmOKzVKtYPRIZoyI/YPVUaoGYcY80yo','scanner','Enable','2026-07-20 00:00:00','2026-07-20 00:00:00'),(15,'Scanner9',NULL,'$argon2id$v=19$m=65536,t=4,p=1$MjZLYlRFVXVxeXdkTGVkdg$LwODBfqzPCGSEa+JtFogQqyQsBIK+cAq9Lio3Okv81A','scanner','Enable','2026-07-20 00:00:00','2026-07-20 00:00:00'),(16,'Scanner10',NULL,'$argon2id$v=19$m=65536,t=4,p=1$QnVjNXhhMUtIeE9OcFpXOA$a4RlD3uI/JOQv1k0vLgJBXd5rw2A4VDpK77/PjdCxiQ','scanner','Enable','2026-07-20 00:00:00','2026-07-20 00:00:00'),(17,'Scanner11',NULL,'$argon2id$v=19$m=65536,t=4,p=1$TEVrZUp5MndiVlRPL0ZqdA$DSbL8UhJ8miHL6uf5wOSmaOZonXBGGNwX7OJ8GZRn7c','scanner','Enable','2026-07-20 00:00:00','2026-07-20 00:00:00'),(18,'Scanner12',NULL,'$argon2id$v=19$m=65536,t=4,p=1$c0x3dE91SmtCMnBPcTNxMw$JnxbibKd/ndqK90rNyrGD5gHKZVX9/zODSNjxBbkmJA','scanner','Enable','2026-07-20 00:00:00','2026-07-20 00:00:00'),(19,'Scanner13',NULL,'$argon2id$v=19$m=65536,t=4,p=1$aElTMjVvZDM5SzFjTGE3SQ$iB95AVggJynM4sPyNQUKdU9zrIBaUE4NrN/Xebt+F3I','scanner','Enable','2026-07-20 00:00:00','2026-07-20 00:00:00'),(20,'Scanner14',NULL,'$argon2id$v=19$m=65536,t=4,p=1$ZGpxWnU4djc1ZWVqR0J4Lg$UlAtr11gOG9e7SnzjUn3mWKus3yT9K7S5DSsJnphdnY','scanner','Enable','2026-07-20 00:00:00','2026-07-20 00:00:00'),(21,'Scanner15',NULL,'$argon2id$v=19$m=65536,t=4,p=1$a2VBcldHTVVxWDcxblRtNg$F+h17y+tdd3lMYtC1/vRmQ9OfrDUrEnFmeOLdNtB7UU','scanner','Enable','2026-07-20 00:00:00','2026-07-20 00:00:00'),(22,'Scanner16',NULL,'$argon2id$v=19$m=65536,t=4,p=1$VHdyOGVCSFpMdUQzRTJsNA$8bz2Ddng/SNH4rfkwADDRx9U50+9dWyPsHYxa/feDZE','scanner','Enable','2026-07-20 00:00:00','2026-07-20 00:00:00'),(23,'Scanner17',NULL,'$argon2id$v=19$m=65536,t=4,p=1$eWRoTml4VERHdVpvd3JMSw$09UiSpO60brLAbpqWuUKhsuI1YcvyNXJymhPC6kLdwc','scanner','Enable','2026-07-20 00:00:00','2026-07-20 00:00:00'),(24,'Scanner18',NULL,'$argon2id$v=19$m=65536,t=4,p=1$S042YzQ0Rkp3L0M1NWZmRQ$ynei6uhXgaTBX6kPGtVgq8YAMFPl7G6oEy1tOU1mk9E','scanner','Enable','2026-07-20 00:00:00','2026-07-20 00:00:00'),(25,'Scanner19',NULL,'$argon2id$v=19$m=65536,t=4,p=1$Zkd5MWV6OUpPY1FvUVJvRQ$rhEO0zdTyrsAMEp5G4CU92TSeUPZU/IZ7UDzdCSuttg','scanner','Enable','2026-07-20 00:00:00','2026-07-20 00:00:00'),(26,'Scanner20',NULL,'$argon2id$v=19$m=65536,t=4,p=1$Vnp1R2hrenFtOWVrM2Y0WA$TSlKLu6Tuy6gfN5luJm6hbJ5PjWe5L/8oJKB0Hc82RM','scanner','Enable','2026-07-20 00:00:00','2026-07-20 00:00:00'),(27,'Encoder1',NULL,'$argon2id$v=19$m=65536,t=4,p=1$MmhhdzRuYTdpMUpyL0wwTQ$6R7fDDQS7MYZXGsVSatQQG4Ln3nmJxeuvztyASO+R8M','encoder','Enable','2026-07-20 00:00:00','2026-07-30 06:33:47'),(28,'Encoder2',NULL,'$argon2id$v=19$m=65536,t=4,p=1$eFZXWURJdTBKR01RR2twbg$TqYcUQqtD2EWnF8VdpqJj+jX6bLXf/Sr8QKpMoRC2C0','encoder','Enable','2026-07-20 00:00:00','2026-07-20 00:00:00'),(29,'Encoder3',NULL,'$argon2id$v=19$m=65536,t=4,p=1$N0k1VndEamc5emNOQlVqMg$9rh4yOLfXnU16y92UtqZl9RfD/k1D2loxj8gzKrrRGA','encoder','Enable','2026-07-20 00:00:00','2026-07-20 00:00:00'),(30,'Encoder4',NULL,'$argon2id$v=19$m=65536,t=4,p=1$WFRpcFk1bE1iTUFoVlN3NA$xcDJWSAyZ3US+EY75JSUgPvWZco1BCzQjKnKy9Ve9to','encoder','Enable','2026-07-20 00:00:00','2026-07-20 00:00:00'),(31,'Encoder5',NULL,'$argon2id$v=19$m=65536,t=4,p=1$MGJvTGIxLlJoR01zcjdDZg$zNemg3DGydMFatmOPG7uYzoM18GVuCxDUrNoisG74P4','encoder','Enable','2026-07-20 00:00:00','2026-07-20 00:00:00'),(32,'Encoder6',NULL,'$argon2id$v=19$m=65536,t=4,p=1$dTZGbTc4eWtIY1hxamR6Yg$JTPScDmxK64WeEwSGJfJmJZUnRwjXO67Ka2v1iusym0','encoder','Enable','2026-07-20 00:00:00','2026-07-20 00:00:00'),(33,'Encoder7',NULL,'$argon2id$v=19$m=65536,t=4,p=1$RHRwaHdKMWVISWF3U3lRNQ$+3w2mYGW5XreuFUNFmamyszVtISJYd4FCVDIgioCfjw','encoder','Enable','2026-07-20 00:00:00','2026-07-20 00:00:00'),(34,'Encoder8',NULL,'$argon2id$v=19$m=65536,t=4,p=1$d3pQdXVabjUvQlBpWHpZRg$1Ng/5Pq4Ww/+gfyiYImmS9JN7/Pm9zkw8Qrug34acpo','encoder','Enable','2026-07-20 00:00:00','2026-07-20 00:00:00'),(35,'Encoder9',NULL,'$argon2id$v=19$m=65536,t=4,p=1$MWgyVmY4ZnN5VWhzWjhTNA$eS/3iZ4E5bH6o1sc+mbX8AIpOHaJhG+FH1XQlLahcDg','encoder','Enable','2026-07-20 00:00:00','2026-07-20 00:00:00'),(36,'Encoder10',NULL,'$argon2id$v=19$m=65536,t=4,p=1$N09JZ0tVcm9Ra3VvZkNSbw$yCVStMtZuhvnWxu4XgutXUUBuMEk5qxqh/nyx2CCDDE','encoder','Enable','2026-07-20 00:00:00','2026-07-20 00:00:00'),(37,'Encoder11',NULL,'$argon2id$v=19$m=65536,t=4,p=1$dXlId0drUjNRLjFTczl0dg$Ii5eEzFDjRkNYQwYWpCeRInlvA9SlA0duiRuK7dKEN4','encoder','Enable','2026-07-20 00:00:00','2026-07-20 00:00:00'),(38,'Encoder12',NULL,'$argon2id$v=19$m=65536,t=4,p=1$Q1I3bFR0eC5veFBIZlFhdw$6PW6sWEY+dHPbVtEEn+8iAGpyTg34NWjuOtPVrSkYCU','encoder','Enable','2026-07-20 00:00:00','2026-07-20 00:00:00'),(39,'Encoder13',NULL,'$argon2id$v=19$m=65536,t=4,p=1$emk1SlVISXpDRnNWNjlhcA$ETwazbO0OcpFebkpaqtVfGnLx5oZg73Loih4R53Indw','encoder','Enable','2026-07-20 00:00:00','2026-07-20 00:00:00'),(40,'Encoder14',NULL,'$argon2id$v=19$m=65536,t=4,p=1$eXdrVHlMbFlhWkVuTVJHQQ$D7Ki2tNAW+U/oO3yzul/DNwxGzVxXtI1S9otfJkZFzc','encoder','Enable','2026-07-20 00:00:00','2026-07-20 00:00:00'),(41,'Encoder15',NULL,'$argon2id$v=19$m=65536,t=4,p=1$RlN0Q2FNb2hwcjk4STBGZQ$HZ3bQzMpJwF3aLD1Mu7vEQYRLclgrh5E4d+Fn4bWlqc','encoder','Enable','2026-07-20 00:00:00','2026-07-20 00:00:00'),(42,'Encoder16',NULL,'$argon2id$v=19$m=65536,t=4,p=1$NVczdktsbVBUVG01QklWQw$0Tm5vBJFHPoLrhnPwph91d00XPWT4sBGgLh7nAhGUeo','encoder','Enable','2026-07-20 00:00:00','2026-07-20 00:00:00'),(43,'Encoder17',NULL,'$argon2id$v=19$m=65536,t=4,p=1$bS9HdENKVUVrZzFzT2duVg$I40RoJVs9Yap8Mdz8+mD+3UTX72t5x/MszgWPMQZkHE','encoder','Enable','2026-07-20 00:00:00','2026-07-20 00:00:00'),(44,'Encoder18',NULL,'$argon2id$v=19$m=65536,t=4,p=1$RjN5eXZmRFlZd0VvYlFtbA$qPMznnOD+29HgOzXiId0Kt7NaXiPgwvmsGg6BHvsobQ','encoder','Enable','2026-07-20 00:00:00','2026-07-20 00:00:00'),(45,'Encoder19',NULL,'$argon2id$v=19$m=65536,t=4,p=1$Tkoxek51eS9OZndTTkxQTQ$gsY9DH2wk3c1DY1YfPXjBQO6AcSe6V/NunZ61NYMB20','encoder','Enable','2026-07-20 00:00:00','2026-07-20 00:00:00'),(46,'Encoder20',NULL,'$argon2id$v=19$m=65536,t=4,p=1$b2FhU0sxaGo5M0dsWER2Lw$XRmbQLt4cDf966G6k1qoRIe2q8LwLzBZOSFez8ZHDfM','encoder','Enable','2026-07-20 00:00:00','2026-07-20 00:00:00');

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed
