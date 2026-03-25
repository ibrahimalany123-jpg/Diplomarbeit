<?php
declare(strict_types=1);

$host = '127.0.0.1';
$db   = 'rfid_gate';
$user = 'daniel';              // <-- dein MariaDB user
$pass = 'Nichtanfassen1'; // <-- dein MariaDB pass

$dsn = "mysql:host=$host;dbname=$db;charset=utf8mb4";

try {
  // Verbindungsaufbau
  $pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
  ]);
  // Verbindungstest
  $pdo->query('SELECT 1');

} catch (PDOException $e) {
  error_log('DB CONNECTION FAILED: ' . $e->getMessage());
  http_response_code(500);
  exit('Datenbankverbindung fehlgeschlagen: ' . $e->getMessage());
}

// Rest des Codes
?>