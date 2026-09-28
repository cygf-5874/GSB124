<?php

declare(strict_types=1);

/**
 * 极简自动加载：把 src/ 下的类按类名映射成同名的 .php 文件。
 *
 *   FixedDec     -> src/FixedDec.php
 *   DecException -> src/DecException.php
 *
 * 只认全局命名空间里的标识符类名；用 __DIR__ 定位，不写死绝对路径。
 * 零依赖，不引入 composer。
 */

spl_autoload_register(static function (string $class): void {
    if ($class === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $class) !== 1) {
        return;
    }

    $file = __DIR__ . '/src/' . $class . '.php';

    if (is_file($file)) {
        require $file;
    }
});
