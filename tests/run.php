<?php

declare(strict_types=1);

/**
 * fixeddec 既有用例：14 个。**别改这个文件。**
 *
 * 用法： php tests/run.php     全绿 → 退出码 0，否则 1
 *
 * 只走 README 里公开的对外方法，不碰任何内部结构。
 */

require __DIR__ . '/../autoload.php';

$GLOBALS['t_total'] = 0;
$GLOBALS['t_pass'] = 0;

function tcase(string $name, callable $fn): void
{
    $GLOBALS['t_total']++;

    try {
        $fn();
    } catch (\Throwable $exc) {
        printf("FAIL %s —— %s: %s\n", $name, get_class($exc), $exc->getMessage());

        return;
    }

    $GLOBALS['t_pass']++;
}

function same(mixed $want, mixed $got, string $what): void
{
    if ($want !== $got) {
        throw new \RuntimeException(
            $what . ' 期望=' . var_export($want, true) . ' 实际=' . var_export($got, true)
        );
    }
}

function throws_dec(string $what, callable $fn): void
{
    try {
        $fn();
    } catch (DecException $exc) {
        return;
    } catch (\Throwable $exc) {
        throw new \RuntimeException(
            $what . ' 应抛 DecException，实际 ' . get_class($exc) . ': ' . $exc->getMessage()
        );
    }

    throw new \RuntimeException($what . ' 应抛 DecException，实际正常返回');
}

tcase('超位按 HALF_UP 进位', static function (): void {
    same('12.35', FixedDec::of('12.345', 2)->toString(), '12.345 保留 2 位');
});

tcase('超位不足半则截断', static function (): void {
    same('12.34', FixedDec::of('12.344', 2)->toString(), '12.344 保留 2 位');
});

tcase('省略整数部分', static function (): void {
    same('0.50', FixedDec::of('.5', 2)->toString(), '.5 保留 2 位');
});

tcase('省略小数部分', static function (): void {
    same('5.00', FixedDec::of('5.', 2)->toString(), '5. 保留 2 位');
});

tcase('零不带符号', static function (): void {
    same('0.00', FixedDec::of('-0.004', 2)->toString(), '-0.004 保留 2 位');
});

tcase('多个小数点非法', static function (): void {
    throws_dec('of("1.2.3", 2)', static fn () => FixedDec::of('1.2.3', 2));
});

tcase('加法', static function (): void {
    same('4.0', FixedDec::of('1.5', 1)->add(FixedDec::of('2.5', 1))->toString(), '1.5 + 2.5');
});

tcase('减法', static function (): void {
    same('9.95', FixedDec::of('10.00', 2)->sub(FixedDec::of('0.05', 2))->toString(), '10.00 - 0.05');
});

tcase('乘法', static function (): void {
    same('3.00', FixedDec::of('1.50', 2)->mul(FixedDec::of('2.00', 2))->toString(), '1.50 * 2.00');
});

tcase('除法除不尽按 HALF_UP', static function (): void {
    same('0.67', FixedDec::of('2.00', 2)->div(FixedDec::of('3.00', 2))->toString(), '2.00 / 3.00');
});

tcase('除零报错', static function (): void {
    throws_dec('div(0.00)', static fn () => FixedDec::of('1.00', 2)->div(FixedDec::of('0.00', 2)));
});

tcase('scale 不一致报错', static function (): void {
    throws_dec('add(scale 不同)', static fn () => FixedDec::of('1.0', 1)->add(FixedDec::of('1.00', 2)));
});

tcase('equals 区分 scale', static function (): void {
    same(false, FixedDec::of('5.00', 2)->equals(FixedDec::of('5.0', 1)), '5.00 与 5.0 不相等');
});

tcase('toInt 按 TRUNC 截断', static function (): void {
    same(-7, FixedDec::of('-7.5', 1)->toInt(FixedDec::TRUNC), '-7.5 向零截断');
});

printf("通过 %d/%d\n", $GLOBALS['t_pass'], $GLOBALS['t_total']);

exit($GLOBALS['t_pass'] === $GLOBALS['t_total'] ? 0 : 1);
