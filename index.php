<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!empty($_SESSION['user_id'])) {
    $role = strtolower($_SESSION['role'] ?? '');
    if (in_array($role, ['superadmin', 'admin'])) {
        header('Location: admin/dashboard.php');
        exit();
    }
    header('Location: client/dashboard.php');
    exit();
}

header('Location: login');
exit();