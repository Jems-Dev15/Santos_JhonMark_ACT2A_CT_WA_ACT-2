<?php
require_once __DIR__ . '/config.php';

try {
    $pdo = getPDO();
} catch (PDOException $e) {
    error_log($e->getMessage());
    die('Database connection failed. Check that MySQL is running and the sample database exists.');
}
