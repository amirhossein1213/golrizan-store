<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $config = require BASE_PATH . '/config/database.php';
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $config['host'], $config['port'] ?? '3306', $config['name'], $config['charset'] ?? 'utf8mb4'
    );
    try {
        $pdo = new PDO($dsn, $config['user'], $config['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_TIMEOUT => 10,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ]);
        try { $pdo->exec("SET time_zone = '+03:30'"); } catch (Throwable) { /* هاست ممکن است اجازه تغییر منطقه زمانی ندهد. */ }
        return $pdo;
    } catch (PDOException $e) {
        error_log('[Mousavi DB] ' . $e->getMessage());
        http_response_code(500);
        exit('اتصال به پایگاه داده برقرار نشد. تنظیمات config/database.php را بررسی کنید.');
    }
}
