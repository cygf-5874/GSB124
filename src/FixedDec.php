<?php

declare(strict_types=1);

/**
 * 定点十进制数：值 = 微单位 / 10^scale，全程以 PHP int 承载微单位。
 *
 * 对外契约见 README「对外契约」的 10 条。
 *
 * 全程只用 PHP 整数（int）运算：解析靠字符处理与逐位累加，
 * 乘 / 除 / 加 / 减均先判溢出再做整数运算，不出现任何 float。
 */
final class FixedDec
{
    /** toInt() 的取整模式：向零截断。 */
    public const TRUNC = 0;

    /** toInt() 的取整模式：向下取整（-∞）。 */
    public const FLOOR = 1;

    /** toInt() 的取整模式：向上取整（+∞）。 */
    public const CEIL = 2;

    /** toInt() 的取整模式：四舍五入（.5 远离零）。 */
    public const HALF_UP = 3;

    /** 10^scale 的整数缓存，下标即 scale（0..18，10^18 仍在 PHP int 内）。 */
    private static array $powers = [];

    private int $scale;

    private int $unscaled;

    private function __construct(int $scale, int $unscaled)
    {
        $this->scale = $scale;
        $this->unscaled = $unscaled;
    }

    /**
     * 解析十进制字符串。数值 = 微单位 / 10^$scale。
     */
    public static function of(string $value, int $scale): self
    {
        if ($scale < 0) {
            throw new DecException('scale 不能为负');
        }

        // 10^scale 必须落在 int 内（mul/div 的中间量要用）。64 位下上限是 18。
        if ($scale > 18) {
            throw new DecException('scale 超出整数可表示的范围');
        }

        if (preg_match('/^([+-]?)([0-9]*)(?:\.([0-9]*))?$/', $value, $m) !== 1) {
            throw new DecException('非法十进制字符串: ' . $value);
        }

        $sign = $m[1];
        $intPart = $m[2];
        $fracPart = array_key_exists(3, $m) ? $m[3] : '';

        // 两侧都为空（含 ""、"."、"+."、"-."、"+"、"-"）一律非法。
        if ($intPart === '' && $fracPart === '') {
            throw new DecException('非法十进制字符串: ' . $value);
        }

        $digits = $intPart . $fracPart;
        $decimalPos = strlen($intPart); // 小数点在 digits 中的位置

        // 需要保留到 scale 位小数：保留位数 = 小数点位置 + scale。
        $keep = $decimalPos + $scale;
        $total = strlen($digits);

        $keptDigits = $digits;
        $droppedDigits = '';
        if ($total > $keep) {
            $keptDigits = substr($digits, 0, $keep);
            $droppedDigits = substr($digits, $keep);
        } elseif ($keep > $total) {
            $keptDigits = str_pad($digits, $keep, '0', STR_PAD_RIGHT);
        }

        // 逐位构造整数，每一步在「乘 10 加位」之前先判溢出。
        $unscaled = 0;
        $atMin = false; // 负数且绝对值恰好是 2^63（即 PHP_INT_MIN）
        $keptLen = strlen($keptDigits);
        for ($i = 0; $i < $keptLen; $i++) {
            $digit = ord($keptDigits[$i]) - 48;
            if ($unscaled > intdiv(PHP_INT_MAX, 10)) {
                throw new DecException('解析结果超出整数范围');
            }
            $unscaled *= 10;
            if ($unscaled > PHP_INT_MAX - $digit) {
                // 唯一可放行的情形：负值，绝对值恰好 = 2^63（PHP_INT_MIN），
                // 且这是最后一位；此时不能做加法（MAX+1 会转成 float）。
                if (
                    $sign === '-'
                    && $i === $keptLen - 1
                    && $unscaled === PHP_INT_MAX - 7
                    && $digit === 8
                ) {
                    $unscaled = PHP_INT_MIN;
                    $atMin = true;
                    break;
                }
                throw new DecException('解析结果超出整数范围');
            }
            $unscaled += $digit;
        }

        // HALF_UP（远离零）：被舍部分首位 >= 5 则绝对值加一。
        if ($droppedDigits !== '' && $droppedDigits[0] >= '5') {
            if ($atMin || $unscaled > PHP_INT_MAX - 1) {
                throw new DecException('解析结果超出整数范围');
            }
            $unscaled++;
        }

        if ($sign === '-' && !$atMin) {
            $unscaled = -$unscaled;
        }

        return new self($scale, $unscaled);
    }

    /** 小数位数。 */
    public function scale(): int
    {
        return $this->scale;
    }

    /** 微单位（整数表示，值 = 微单位 / 10^scale）。 */
    public function unscaled(): int
    {
        return $this->unscaled;
    }

    /** 加法；两侧 scale 必须一致。 */
    public function add(self $other): self
    {
        $this->assertSameScale($other);

        $a = $this->unscaled;
        $b = $other->unscaled;
        if (!self::addNoOverflow($a, $b)) {
            throw new DecException('加法结果超出整数范围');
        }

        return new self($this->scale, $a + $b);
    }

    /** 减法；两侧 scale 必须一致。 */
    public function sub(self $other): self
    {
        $this->assertSameScale($other);

        $a = $this->unscaled;
        $b = $other->unscaled;
        if (!self::subNoOverflow($a, $b)) {
            throw new DecException('减法结果超出整数范围');
        }

        return new self($this->scale, $a - $b);
    }

    /** 乘法；两侧 scale 必须一致。 */
    public function mul(self $other): self
    {
        $this->assertSameScale($other);

        $a = $this->unscaled;
        $b = $other->unscaled;
        if (!self::mulNoOverflow($a, $b)) {
            throw new DecException('乘法中间量超出整数范围');
        }

        $product = $a * $b;
        $p = self::pow10($this->scale);

        return new self($this->scale, self::roundDivHalfUp($product, $p));
    }

    /** 除法；两侧 scale 必须一致，除不尽按 HALF_UP 舍入到当前 scale。 */
    public function div(self $other): self
    {
        $this->assertSameScale($other);

        $a = $this->unscaled;
        $b = $other->unscaled;
        if ($b === 0) {
            throw new DecException('除数不能为零');
        }

        $p = self::pow10($this->scale);

        // 中间量 a × P 在乘之前先判溢出（契约 10）。
        if (!self::mulNoOverflow($a, $p)) {
            throw new DecException('除法中间量超出整数范围');
        }

        $dividend = $a * $p;

        return new self($this->scale, self::roundDivHalfUp($dividend, $b));
    }

    /** 比较：小于返回负、相等返回 0、大于返回正；两侧 scale 必须一致。 */
    public function compareTo(self $other): int
    {
        $this->assertSameScale($other);

        return $this->unscaled <=> $other->unscaled;
    }

    /** 相等：scale 与微单位都相同才算相等。 */
    public function equals(self $other): bool
    {
        return $this->scale === $other->scale && $this->unscaled === $other->unscaled;
    }

    /** 哈希：相等的值必须给同一个结果。 */
    public function hashCode(): int
    {
        // 只用循环移位与异或：位运算始终停留在 64 位整数语义内，
        // 不存在加减乘法溢出转 float 的可能；且是 (unscaled, scale) 的纯函数。
        $h = $this->unscaled;
        $h = ($h << 13) | ($h >> 51);
        $h ^= $this->scale;
        $h = ($h << 7) | ($h >> 57);

        return $h ^ ($h >> 29);
    }

    /** 按 scale 补零输出；零不带符号。 */
    public function toString(): string
    {
        $v = $this->unscaled;

        if ($v === 0) {
            return $this->scale === 0 ? '0' : '0.' . str_repeat('0', $this->scale);
        }

        $negative = $v < 0;
        // 直接用字符串去掉负号：对 PHP_INT_MIN 取反会溢出成 float。
        $digits = $negative ? substr((string) $v, 1) : (string) $v;

        if ($this->scale === 0) {
            $body = $digits;
        } elseif (strlen($digits) <= $this->scale) {
            $body = '0.' . str_pad($digits, $this->scale, '0', STR_PAD_LEFT);
        } else {
            $body = substr($digits, 0, -$this->scale) . '.' . substr($digits, -$this->scale);
        }

        return $negative ? '-' . $body : $body;
    }

    /** 按 $mode（TRUNC / FLOOR / CEIL / HALF_UP）取整为整数。 */
    public function toInt(int $mode): int
    {
        $p = self::pow10($this->scale);
        $a = $this->unscaled;

        switch ($mode) {
            case self::TRUNC:
                // 向零：intdiv 即截断方向。
                return intdiv($a, $p);

            case self::FLOOR:
                // 向 -∞。
                $q = intdiv($a, $p);
                return ($a < 0 && $a % $p !== 0) ? $q - 1 : $q;

            case self::CEIL:
                // 向 +∞。
                $q = intdiv($a, $p);
                return ($a > 0 && $a % $p !== 0) ? $q + 1 : $q;

            case self::HALF_UP:
                return self::roundDivHalfUp($a, $p);

            default:
                throw new DecException('未知的取整模式: ' . $mode);
        }
    }

    private function assertSameScale(self $other): void
    {
        if ($this->scale !== $other->scale) {
            throw new DecException(
                'scale 不一致: ' . $this->scale . ' 与 ' . $other->scale
            );
        }
    }

    /** 返回 10^$scale（整数）；结果超出 int 时抛 DecException。 */
    private static function pow10(int $scale): int
    {
        if ($scale < 0 || $scale > 18) {
            throw new DecException('scale 超出整数可表示的范围');
        }

        if (self::$powers === []) {
            $v = 1;
            for ($i = 0; $i <= 18; $i++) {
                self::$powers[$i] = $v;
                if ($i < 18) {
                    $v *= 10;
                }
            }
        }

        return self::$powers[$scale];
    }

    /**
     * 判断 $a + $b 是否在 PHP int 范围内（判断先于加法）。
     */
    private static function addNoOverflow(int $a, int $b): bool
    {
        if ($b >= 0) {
            return $a <= PHP_INT_MAX - $b;
        }

        return $a >= PHP_INT_MIN - $b;
    }

    /**
     * 判断 $a - $b 是否在 PHP int 范围内（判断先于减法）。
     */
    private static function subNoOverflow(int $a, int $b): bool
    {
        if ($b >= 0) {
            return $a >= PHP_INT_MIN + $b;
        }

        return $a <= PHP_INT_MAX + $b;
    }

    /**
     * 判断 $a × $b 是否在 PHP int 范围内（判断先于乘法）。
     */
    private static function mulNoOverflow(int $a, int $b): bool
    {
        if ($a === 0 || $b === 0) {
            return true;
        }

        if ($a === PHP_INT_MIN) {
            return $b === 1;
        }

        if ($b === PHP_INT_MIN) {
            return $a === 1;
        }

        if ($a === 1 || $b === 1 || $a === -1 || $b === -1) {
            return true;
        }

        $x = $a < 0 ? -$a : $a;
        $y = $b < 0 ? -$b : $b;

        // x,y >= 2：$x * $y <= PHP_INT_MAX 的无比较乘法判断。
        return $x <= intdiv(PHP_INT_MAX - 1, $y) + 1;
    }

    /**
     * 整数除法 $a / $b，按 HALF_UP（.5 远离零）取整。
     *
     * $b 必须非零。调用方需保证中间量不溢出；本方法在取整结果
     * 本身超出 int 时（唯一情形：PHP_INT_MIN / -1）抛 DecException。
     */
    private static function roundDivHalfUp(int $a, int $b): int
    {
        if ($b === 0) {
            throw new DecException('除数不能为零');
        }

        // 唯一会让商超出 int 的组合。
        if ($a === PHP_INT_MIN && $b === -1) {
            throw new DecException('运算结果超出整数范围');
        }

        $q = intdiv($a, $b);
        // 用 r = a - q*b 求余，避免对 % 的任何依赖；q*b 在此恒不溢出。
        $r = $a - $q * $b;

        if ($r === 0) {
            return $q;
        }

        // 余数与被除数同号（截断除法）；用 2*|r| 与 |b| 比较，全程整数。
        $ar = $r < 0 ? -$r : $r;
        $ab = $b < 0 ? -$b : $b;

        // ar = PHP_INT_MIN 时 |b| <= PHP_INT_MAX 而 2*ar 必更大；
        // 且该情形下商不会落在 HALF_UP 的半区，故无需进位。
        if ($ar !== PHP_INT_MIN && $ar * 2 >= $ab) {
            $q += (($r > 0) === ($b > 0)) ? 1 : -1;
        }

        return $q;
    }
}
