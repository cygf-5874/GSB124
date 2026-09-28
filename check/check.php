<?php

declare(strict_types=1);

/**
 * 固定验收入口：fixeddec 的「对外契约」。**别改这个文件。**
 *
 * 用法：
 *   php check/check.php                       跑全部场景，全过才退 0
 *   php check/check.php -list                 列出全部场景
 *   php check/check.php --only parse          只跑一组
 *   php check/check.php --only parse,arith    只跑指定的几组
 *
 * 五组共 10 个场景：
 *   parse    3 —— 省略整数/小数部分、各进制非法形式报错（含负 scale）、超位 HALF_UP 舍入
 *   arith    3 —— 四则精确（2^53 以上不失真，乘法保留 scale）、scale 不一致报错、除不尽按 HALF_UP
 *   cmp      2 —— 精确比较不经 float、equals 与 hashCode 区分 scale
 *   format   1 —— 按 scale 补零、零不带符号
 *   overflow 1 —— 解析/加法/减法/乘法溢出都在运算前判出并抛 DecException
 *
 * 只依赖 PHP 8 标准库。判据完全确定：不读时间、不用随机源、不依赖数组 hash 顺序。
 * 失败不早退；每个场景用 try/catch(\Throwable) 兜住后继续。
 */

require __DIR__ . '/../autoload.php';

// ---------------------------------------------------------------------------
// 断言失败
// ---------------------------------------------------------------------------

final class CheckFailure extends \Exception
{
    public string $expected;

    public string $actual;

    public function __construct(string $message, string $expected = '-', string $actual = '-')
    {
        parent::__construct($message);
        $this->expected = $expected;
        $this->actual = $actual;
    }
}

// ---------------------------------------------------------------------------
// 工具
// ---------------------------------------------------------------------------

function tk(string $name, string $why, callable $fn): array
{
    return ['name' => $name, 'why' => $why, 'fn' => $fn];
}

function show(mixed $v): string
{
    if (is_bool($v)) {
        return $v ? 'true' : 'false';
    }
    if (is_string($v)) {
        return "'" . $v . "'";
    }
    if ($v === null) {
        return 'null';
    }

    return (string) $v;
}

function expect_same(mixed $want, mixed $got, string $what): void
{
    if ($want !== $got) {
        throw new CheckFailure($what, show($want), show($got));
    }
}

/** 断言 $fn() 抛 DecException；抛别的（含不抛）都算失败。 */
function expect_dec(callable $fn, string $what): void
{
    try {
        $fn();
    } catch (DecException $exc) {
        return;
    } catch (\Throwable $exc) {
        throw new CheckFailure(
            $what . ' 应抛 DecException',
            'DecException',
            get_class($exc) . ': ' . $exc->getMessage()
        );
    }

    throw new CheckFailure($what . ' 应抛 DecException', 'DecException', '没有抛异常');
}

// ---------------------------------------------------------------------------
// [parse] 3
// ---------------------------------------------------------------------------

$parse = [
    tk('omit', '整数部分或小数部分可以省略；前导零不影响取值', function (): void {
        expect_same('0.50', FixedDec::of('.5', 2)->toString(), '.5 保留 2 位小数');
        expect_same('5.00', FixedDec::of('5.', 2)->toString(), '5. 保留 2 位小数');
        expect_same('1.5', FixedDec::of('+1.5', 1)->toString(), '前导 + 应被接受');
        expect_same('-1.5', FixedDec::of('-1.5', 1)->toString(), '前导 - 应被接受');
        expect_same('7.50', FixedDec::of('007.50', 2)->toString(), '前导零应被忽略');

        expect_same(50, FixedDec::of('.5', 2)->unscaled(), '.5 在 scale=2 下的微单位');
        expect_same(500, FixedDec::of('5.', 2)->unscaled(), '5. 在 scale=2 下的微单位');
        expect_same(2, FixedDec::of('5.', 2)->scale(), 'scale 应原样保留');
    }),

    tk('illegal', '空串 / 只有符号 / 多个小数点 / 指数形式 / 带空白 / 负 scale 一律 DecException', function (): void {
        foreach (['', '+', '-', '.', '+.', '-.', '1.2.3', '1e3', '0x10', 'abc', ' 5', '5 ', '', '1,5', '--1'] as $bad) {
            expect_dec(static fn () => FixedDec::of($bad, 2), 'of(' . var_export($bad, true) . ', 2)');
        }

        expect_dec(static fn () => FixedDec::of('1', -1), 'of("1", -1)');
    }),

    tk('round', '超出 scale 的位数按 HALF_UP（远离零）舍入', function (): void {
        expect_same('12.35', FixedDec::of('12.345', 2)->toString(), '12.345 -> 12.35');
        expect_same('12.34', FixedDec::of('12.344', 2)->toString(), '12.344 -> 12.34');
        expect_same('-12.35', FixedDec::of('-12.345', 2)->toString(), '-12.345 -> -12.35（远离零）');
        expect_same('0.01', FixedDec::of('0.005', 2)->toString(), '0.005 -> 0.01');
        expect_same('-0.01', FixedDec::of('-0.005', 2)->toString(), '-0.005 -> -0.01（远离零）');
        expect_same('3', FixedDec::of('2.5', 0)->toString(), '2.5 -> 3');
        expect_same('-3', FixedDec::of('-2.5', 0)->toString(), '-2.5 -> -3（远离零）');
        expect_same('2', FixedDec::of('2.4', 0)->toString(), '2.4 -> 2');
        expect_same('-2', FixedDec::of('-2.4', 0)->toString(), '-2.4 -> -2');
    }),
];

// ---------------------------------------------------------------------------
// [arith] 3
// ---------------------------------------------------------------------------

$arith = [
    tk('exact', '四则运算精确到微单位；2^53 以上不得失真', function (): void {
        $big = FixedDec::of('9007199254740993.00', 2);
        expect_same(900719925474099300, $big->unscaled(), '2^53+1 的微单位必须精确');

        expect_same('9007199254740994.00', $big->add(FixedDec::of('1.00', 2))->toString(), '加法按整数做');
        expect_same('9007199254740992.00', $big->sub(FixedDec::of('1.00', 2))->toString(), '减法按整数做');
        expect_same('90071992547409.93', $big->mul(FixedDec::of('0.01', 2))->toString(), '乘法后仍落在同一 scale');
        expect_same('3.00', FixedDec::of('1.50', 2)->mul(FixedDec::of('2.00', 2))->toString(), '1.50 × 2.00');
        expect_same('0.01', FixedDec::of('0.06', 2)->mul(FixedDec::of('0.09', 2))->toString(), '乘法余数按 HALF_UP');
        expect_same('0.00', FixedDec::of('0.03', 2)->mul(FixedDec::of('0.05', 2))->toString(), '乘法余数不足半则舍去');

        expect_same('0.125', FixedDec::of('1.000', 3)->div(FixedDec::of('8.000', 3))->toString(), '1/8 除得尽');
        expect_same('2.50', FixedDec::of('10.00', 2)->div(FixedDec::of('4.00', 2))->toString(), '10/4 除得尽');
        expect_same(999999999999999999, FixedDec::of('9999999999999999.99', 2)->unscaled(), '大值解析精确');
    }),

    tk('scale-mismatch', '参与运算的两侧 scale 必须一致，否则 DecException', function (): void {
        $one = FixedDec::of('1.0', 1);
        $two = FixedDec::of('1.00', 2);

        expect_dec(static fn () => FixedDec::of('1.0', 1)->add(FixedDec::of('1.00', 2)), 'add 跨 scale');
        expect_dec(static fn () => FixedDec::of('1.0', 1)->sub(FixedDec::of('1.00', 2)), 'sub 跨 scale');
        expect_dec(static fn () => FixedDec::of('1.0', 1)->mul(FixedDec::of('1.00', 2)), 'mul 跨 scale');
        expect_dec(static fn () => FixedDec::of('1.0', 1)->div(FixedDec::of('1.00', 2)), 'div 跨 scale');
        expect_dec(static fn () => FixedDec::of('1.0', 1)->compareTo(FixedDec::of('1.00', 2)), 'compareTo 跨 scale');

        expect_same(true, $one->scale() === 1, '左侧 scale 不变');
        expect_same(true, $two->scale() === 2, '右侧 scale 不变');
    }),

    tk('div-round', '除不尽时按 HALF_UP 舍入到当前 scale；除零抛 DecException', function (): void {
        expect_same('0.33', FixedDec::of('1.00', 2)->div(FixedDec::of('3.00', 2))->toString(), '1/3 保留 2 位');
        expect_same('0.67', FixedDec::of('2.00', 2)->div(FixedDec::of('3.00', 2))->toString(), '2/3 保留 2 位（进位）');
        expect_same('-0.67', FixedDec::of('-2.00', 2)->div(FixedDec::of('3.00', 2))->toString(), '-2/3：符号跟着商走');
        expect_same('-0.67', FixedDec::of('2.00', 2)->div(FixedDec::of('-3.00', 2))->toString(), '2/-3：符号跟着商走');
        expect_same('0.67', FixedDec::of('-2.00', 2)->div(FixedDec::of('-3.00', 2))->toString(), '-2/-3 为正');
        expect_same('3.50', FixedDec::of('7.00', 2)->div(FixedDec::of('2.00', 2))->toString(), '7/2 除得尽');
        expect_same('0.00', FixedDec::of('0.00', 2)->div(FixedDec::of('5.00', 2))->toString(), '零除以非零为零');
        expect_same('1.005', FixedDec::of('1.005', 3)->div(FixedDec::of('1.000', 3))->toString(), '原地不动');

        expect_same('3', FixedDec::of('5', 0)->div(FixedDec::of('2', 0))->toString(), 'scale=0：5/2 -> 3');
        expect_same('-3', FixedDec::of('-5', 0)->div(FixedDec::of('2', 0))->toString(), 'scale=0：-5/2 -> -3');
        expect_same('2', FixedDec::of('3', 0)->div(FixedDec::of('2', 0))->toString(), 'scale=0：3/2 -> 2');
        expect_same('0', FixedDec::of('1', 0)->div(FixedDec::of('3', 0))->toString(), 'scale=0：1/3 -> 0');
        expect_same('1', FixedDec::of('2', 0)->div(FixedDec::of('3', 0))->toString(), 'scale=0：2/3 -> 1');

        expect_dec(static fn () => FixedDec::of('1.00', 2)->div(FixedDec::of('0.00', 2)), 'div(0.00)');
        expect_dec(static fn () => FixedDec::of('1', 0)->div(FixedDec::of('-0', 0)), 'div(-0)');
    }),
];

// ---------------------------------------------------------------------------
// [cmp] 2
// ---------------------------------------------------------------------------

$cmp = [
    tk('exact', '比较是纯整数比较，2^53 以上的相邻值必须分得开', function (): void {
        $hi = FixedDec::of('9007199254740993.00', 2);
        $lo = FixedDec::of('9007199254740992.00', 2);

        expect_same(1, $hi->compareTo($lo), '2^53+1 > 2^53');
        expect_same(-1, $lo->compareTo($hi), '2^53 < 2^53+1');
        expect_same(0, $hi->compareTo($hi), '自己与自己相等');
        expect_same(0, $lo->compareTo($lo), '自己与自己相等');
        expect_same(-1, FixedDec::of('-1.00', 2)->compareTo(FixedDec::of('1.00', 2)), '-1 < 1');
        expect_same(0, FixedDec::of('0.00', 2)->compareTo(FixedDec::of('-0.00', 2)), '零与负零相等');
        expect_same(
            1,
            FixedDec::of('92233720368547758.07', 2)->compareTo(FixedDec::of('92233720368547758.06', 2)),
            '接近 int 上限的两个相邻值也必须分得开'
        );
    }),

    tk('equals', 'equals 要求 scale 与微单位都相同；相等则 hashCode 必相同', function (): void {
        $a = FixedDec::of('5.00', 2);
        $b = FixedDec::of('5.00', 2);

        expect_same(true, $a->equals($b), '同 scale 同微单位应相等');
        expect_same($b->hashCode(), $a->hashCode(), '相等的值 hashCode 必须相同');
        expect_same(true, $a->hashCode() === $b->hashCode(), 'hashCode 逐次调用要稳定');

        expect_same(false, $a->equals(FixedDec::of('5.0', 1)), '仅 scale 不同就不相等');
        expect_same(false, $a->equals(FixedDec::of('-5.00', 2)), '符号不同就不相等');
        expect_same(true, FixedDec::of('0.00', 2)->equals(FixedDec::of('-0.00', 2)), '零与负零相等');
        expect_same(
            true,
            FixedDec::of('0.00', 2)->hashCode() === FixedDec::of('-0.00', 2)->hashCode(),
            '零与负零的 hashCode 必须相同'
        );
    }),
];

// ---------------------------------------------------------------------------
// [format] 1
// ---------------------------------------------------------------------------

$format = [
    tk('zeros', '按 scale 补零；零一律不带符号', function (): void {
        expect_same('5.00', FixedDec::of('5', 2)->toString(), 'scale=2 的整数应补到两位');
        expect_same('123.4', FixedDec::of('123.4', 1)->toString(), 'scale=1 补一位');
        expect_same('-0.05', FixedDec::of('-0.05', 2)->toString(), '负值保留符号');
        expect_same('-0.050', FixedDec::of('-0.050', 3)->toString(), '负值补零到 scale');
        expect_same('0.00', FixedDec::of('0.00', 2)->toString(), '零输出 0.00');
        expect_same('0.00', FixedDec::of('-0.004', 2)->toString(), '舍成零后不得输出 -0.00');
        expect_same('0.000', FixedDec::of('-0', 3)->toString(), '负零不得带符号');
        expect_same('0', FixedDec::of('0', 0)->toString(), 'scale=0 的零就是 0');
        expect_same('7', FixedDec::of('7.4', 0)->toString(), 'scale=0 输出整数');
    }),
];

// ---------------------------------------------------------------------------
// [overflow] 1
// ---------------------------------------------------------------------------

$overflow = [
    tk('mul', '溢出在运算之前判出并抛 DecException', function (): void {
        expect_dec(static fn () => FixedDec::of('9223372036854775808', 0), '解析超出 int 上限');
        expect_same(
            '9223372036854775807',
            FixedDec::of('9223372036854775807', 0)->toString(),
            '恰好等于 int 上限应能解析'
        );

        expect_dec(static fn () => FixedDec::of('9223372036854775807', 0)->add(FixedDec::of('1', 0)), '加法溢出');
        expect_dec(static fn () => FixedDec::of('9223372036854775807', 0)->sub(FixedDec::of('-1', 0)), '减法溢出');
        expect_dec(static fn () => FixedDec::of('9223372036854775807', 0)->mul(FixedDec::of('2', 0)), '乘法溢出');
        expect_dec(
            static fn () => FixedDec::of('4000000000.00', 2)->mul(FixedDec::of('4000000000.00', 2)),
            '两个大数相乘溢出'
        );
        expect_dec(
            static fn () => FixedDec::of('92233720368547758.07', 2)->add(FixedDec::of('0.01', 2)),
            '微单位加法越过 int 上限'
        );

        expect_same(
            '0.00',
            FixedDec::of('0.00', 2)->mul(FixedDec::of('92233720368547758.07', 2))->toString(),
            '零乘大数仍为零，不该误报溢出'
        );
    }),
];

$GROUPS = [
    'parse' => $parse,
    'arith' => $arith,
    'cmp' => $cmp,
    'format' => $format,
    'overflow' => $overflow,
];

// ---------------------------------------------------------------------------
// runner
// ---------------------------------------------------------------------------

$flat = [];
foreach ($GROUPS as $group => $scenarios) {
    foreach ($scenarios as $scenario) {
        $flat[] = [$group, $scenario['name'], $scenario['why'], $scenario['fn']];
    }
}

$argv = $argv ?? [];
$doList = false;
$only = null;

for ($i = 1; $i < count($argv); $i++) {
    $arg = $argv[$i];

    if ($arg === '-list' || $arg === '--list') {
        $doList = true;
    } elseif ($arg === '--only' || $arg === '--group') {
        $i++;
        if ($i >= count($argv)) {
            fwrite(STDERR, "--only 需要一个组名（parse/arith/cmp/format/overflow）\n");
            exit(2);
        }
        $only = [];
        foreach (explode(',', $argv[$i]) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $only[$part] = true;
            }
        }
    } elseif ($arg === '-h' || $arg === '--help') {
        echo "用法: php check/check.php [-list] [--only <组名>]\n";
        exit(0);
    } else {
        fwrite(STDERR, "未知参数: {$arg}\n");
        exit(2);
    }
}

if ($doList) {
    foreach ($flat as [$group, $name, $why, $_fn]) {
        printf("[%-8s] %-14s %s\n", $group, $name, $why);
    }
    exit(0);
}

$selected = [];
foreach ($flat as $entry) {
    if ($only === null || isset($only[$entry[0]])) {
        $selected[] = $entry;
    }
}

if ($selected === []) {
    fwrite(STDERR, "没有匹配的场景\n");
    exit(2);
}

$passed = 0;
$failed = 0;

foreach ($selected as [$group, $name, $_why, $fn]) {
    try {
        $fn();
    } catch (CheckFailure $exc) {
        $failed++;
        printf("FAIL %s/%s  期望=%s 实际=%s（%s）\n", $group, $name, $exc->expected, $exc->actual, $exc->getMessage());
        continue;
    } catch (\Throwable $exc) {
        $failed++;
        printf("FAIL %s/%s  期望=正常返回 实际=%s: %s\n", $group, $name, get_class($exc), $exc->getMessage());
        continue;
    }

    $passed++;
    printf("PASS %s/%s\n", $group, $name);
}

$total = count($selected);
printf("结果：通过 %d/%d\n", $passed, $total);
exit($failed === 0 ? 0 : 1);
