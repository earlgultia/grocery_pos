<?php
// includes/auth.php
require_once 'db_connect.php';
require_once 'functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Check if user is store user
if ($_SESSION['user_role'] !== 'store_user') {
    header('Location: admin_dashboard.php');
    exit();
}

// Get user info
$user_id = $_SESSION['user_id'];
$stmt = getDB()->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Get store info
$stmt = getDB()->prepare("SELECT * FROM stores WHERE id = ?");
$stmt->execute([$user['store_id']]);
$store = $stmt->fetch();
?>