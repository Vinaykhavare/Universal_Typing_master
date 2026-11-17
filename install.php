<?php
declare(strict_types=1);
// Universal Typing Master - one-time installer. Run once, then DELETE this file.
mysqli_report(MYSQLI_REPORT_OFF);
$dbName = 'student';
$adminUser = 'admin';
$adminPass = 'Admin@123'; // admin.php will force you to change it after first login

$db = new mysqli('localhost', 'root', '');
if ($db->connect_errno) exit('MySQL connection failed. Start MySQL in XAMPP first.');
$db->set_charset('utf8mb4');

$sql = [
"CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
"USE `$dbName`",
"CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(100) NOT NULL,
  username VARCHAR(60) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  selected_speed INT NOT NULL DEFAULT 30,
  selected_lang VARCHAR(20) NOT NULL DEFAULT 'English',
  email VARCHAR(190) NULL UNIQUE,
  contact_number VARCHAR(20) NULL UNIQUE,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB",
"CREATE TABLE IF NOT EXISTS test_results (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  exam_mode VARCHAR(30) NOT NULL,
  exercise_name VARCHAR(150) NOT NULL,
  language VARCHAR(20) NOT NULL,
  marks DECIMAL(5,2) NOT NULL DEFAULT 0,
  correct_words INT NOT NULL DEFAULT 0,
  wrong_words INT NOT NULL DEFAULT 0,
  untype_words INT NOT NULL DEFAULT 0,
  cpm INT NOT NULL DEFAULT 0,
  gross_wpm DECIMAL(8,2) NOT NULL DEFAULT 0,
  net_wpm DECIMAL(8,2) NOT NULL DEFAULT 0,
  accuracy DECIMAL(5,2) NOT NULL DEFAULT 0,
  time_taken INT NOT NULL DEFAULT 0,
  result_status VARCHAR(10) NOT NULL DEFAULT 'Fail',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB",
"CREATE TABLE IF NOT EXISTS admins (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(60) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  must_change_password TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB",
"CREATE TABLE IF NOT EXISTS passages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(150) NOT NULL,
  language VARCHAR(20) NOT NULL,
  difficulty VARCHAR(10) NOT NULL DEFAULT 'Medium',
  content MEDIUMTEXT NULL,
  content_html MEDIUMTEXT NULL,
  pdf_path VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB",
"CREATE TABLE IF NOT EXISTS business_letters (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(150) NOT NULL,
  language VARCHAR(20) NOT NULL,
  content MEDIUMTEXT NULL,
  content_html MEDIUMTEXT NULL,
  pdf_path VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB",
"CREATE TABLE IF NOT EXISTS objective_questions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  language VARCHAR(20) NOT NULL,
  question TEXT NOT NULL,
  option_a VARCHAR(255) NOT NULL,
  option_b VARCHAR(255) NOT NULL,
  option_c VARCHAR(255) NOT NULL,
  option_d VARCHAR(255) NOT NULL,
  correct_option CHAR(1) NOT NULL,
  explanation TEXT NULL,
  pdf_path VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB",
"CREATE TABLE IF NOT EXISTS login_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  login_identifier VARCHAR(190) NOT NULL,
  ip_address VARCHAR(45) NULL,
  logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB",
"CREATE TABLE IF NOT EXISTS settings (
  setting_key VARCHAR(60) NOT NULL PRIMARY KEY,
  setting_value VARCHAR(255) NOT NULL
) ENGINE=InnoDB",
"CREATE TABLE IF NOT EXISTS study_materials (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(150) NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB",
];

foreach ($sql as $q) {
    if (!$db->query($q)) exit('Error: ' . htmlspecialchars($db->error) . '<br><pre>' . htmlspecialchars($q) . '</pre>');
}

$hash = password_hash($adminPass, PASSWORD_DEFAULT);
$s = $db->prepare('INSERT IGNORE INTO admins (username, password_hash, must_change_password) VALUES (?, ?, 1)');
$s->bind_param('ss', $adminUser, $hash);
$s->execute();

foreach (['uploads/passages', 'uploads/letters', 'uploads/objective'] as $dir) {
    if (!is_dir(__DIR__ . '/' . $dir)) mkdir(__DIR__ . '/' . $dir, 0775, true);
}

echo "<h2>Installation complete ✅</h2>
<p>Database: <b>$dbName</b></p>
<p>Admin login: <b>$adminUser</b> / <b>$adminPass</b> (change it after first login)</p>
<p><a href='index.php'>Student Portal</a> | <a href='admin.php'>Admin Panel</a></p>
<p style='color:#b91c1c'><b>Now delete install.php from your server.</b></p>";
