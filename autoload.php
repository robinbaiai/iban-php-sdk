<?php
declare(strict_types=1);

// 不使用 Composer 时引用此文件；只加载 SDK 自己的命名空间。
spl_autoload_register(static function (string $class): void {
    $prefix = 'YabandPay\\Iban\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) { return; }
    $relative = substr($class, strlen($prefix));
    if (!preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $relative)) { return; }
    $file = __DIR__ . '/src/' . $relative . '.php';
    if (is_file($file)) { require $file; }
});
