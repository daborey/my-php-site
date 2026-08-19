<?php
// ============================================
// FILE: index.php
// PROJECT: daboreystep2
// ============================================

require_once __DIR__ . '/config.php';

if (isset($_SESSION['user_id'])) {
    header("Location: " . BASE_URL . "/dashboard.php");
    exit;
} else {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}