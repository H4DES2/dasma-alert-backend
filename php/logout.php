<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

/** @var \mysqli $conn */
$auth = new Auth($conn);
$auth->logout();

header("Location: login.php");
exit();