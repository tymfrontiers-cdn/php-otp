# MySQL

### Table: otp_email
```
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;

--
-- Database: `{MYSQL_LOG_DB}`
-- Replace to match
--

-- --------------------------------------------------------

--
-- Table structure for table `otp_email`
--

CREATE TABLE `otp_email` (
  `id` int(10) UNSIGNED NOT NULL,
  `ref` char(32) NOT NULL,
  `user` char(72) NOT NULL,
  `code` char(32) NOT NULL,
  `qid` varchar(256) NOT NULL,
  `subject` char(72) NOT NULL,
  `message` text NOT NULL,
  `message_text` varchar(512) NOT NULL,
  `sender` char(128) NOT NULL,
  `receiver` char(128) NOT NULL,
  `expiry` datetime DEFAULT NULL,
  `_created` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `otp_email`
--
ALTER TABLE `otp_email`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ref` (`ref`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `otp_email`
--
ALTER TABLE `otp_email`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;
COMMIT;
```
