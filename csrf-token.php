<?php
// /csrf-token.php
header('Content-Type: application/json; charset=UTF-8');
session_start();
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
echo json_encode(['csrf' => $_SESSION['csrf']]);
