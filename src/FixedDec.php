<?php

declare(strict_types=1);

/**
 * 定点十进制数：值 = 微单位 / 10^scale，全程以 PHP int 承载微单位。
 *
 * 对外契约见 README「对外契约」的 10 条。实现中不出现任何浮点运算：
 * 解析按字符处理，四则与比较走纯整数，溢出一律在运算之前判出。
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

    /** 10^0 .. 10^18；非零值的 scale 不可能超过 18（否则微单位必超 int）。 */
    private const POW10 = [
        1,
        10,
        100,
        1000,
        10000,
        100000,
        1000000,
        10000000,
        100000000,
        1000000000,
        10000000000,
        100000000000,
        1000000000000,
        10000000000000,
        100000000000000,
        1000000000000000,
        10000000000000000,
        100000000000000000,
        1000000000000000000,
    ];

    private function __construct(
        private int $unscaledValue,
        private int $decimalScale,
    ) {
    }

    /**
     * 解析十进制字符串。数值 = 微单位 / 10^$scale。
     */
    public static function of(string $value, int $scale): self
    {
        if ($scale < 0) {
            throw new DecException('scale 不能为负数');
        }

        // 可选符号；整数部分与小数部分至少一侧有数字；小数点至多一个。
        if (preg_match('/^([+-]?)(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)$/', $value, $matches) !== 1) {
            throw new DecException('非法的十进制字符串: ' . $value);
        }

        $negative = $matches[1] === '-';
        $body = $matches[1] === '' ? $value : substr($value, 1);
        $dot = strpos($body, '.');
        if ($dot === false) {
            $wholePart = $body;
            $fractionPart = '';
        } else {
            $wholePart = substr($body, 0, $dot);
            $fractionPart = substr($body, $dot + 1);
        }

        // 零（含负零、.0、-0.000…）一律归一为无符号 0，与 scale 无关。
        if (ltrim($wholePart . $fractionPart, '0') === '') {
            return new self(0, $scale);
        }

        $whole = ltrim($wholePart, '0');
        $fraction = $fractionPart;

        // 超出 scale 的小数位按 HALF_UP 处理：只需看被丢弃的首位数字。
        $roundUp = false;
        if (strlen($fraction) > $scale) {
            $roundUp = $fraction[$scale] >= '5';
            $fraction = substr($fraction, 0, $scale);
        }

        // 先按位数挡住必溢出的情形，避免构造超长字符串。
        $maxDigits = strlen((string) PHP_INT_MAX);
        if (strlen($whole) + $scale > $maxDigits) {
            throw new DecException('数值超出 PHP int 范围');
        }

        $digits = $whole . $fraction . str_repeat('0', $scale - strlen($fraction));
        if ($roundUp) {
            $digits = self::incrementDecimal($digits);
        }

        self::assertWithinIntRange($digits);

        $magnitude = (int) $digits;

        return new self($negative ? -$magnitude : $magnitude, $scale);
    }

    /** 小数位数。 */
    public function scale(): int
    {
        return $this->decimalScale;
    }

    /** 微单位（整数表示，值 = 微单位 / 10^scale）。 */
    public function unscaled(): int
    {
        return $this->unscaledValue;
    }

    /** 加法；两侧 scale 必须一致。 */
    public function add(self $other): self
    {
        $this->requireSameScale($other);

        $result = self::checkedAdd($this->unscaledValue, $other->unscaledValue);

        return new self($result, $this->decimalScale);
    }

    /** 减法；两侧 scale 必须一致。 */
    public function sub(self $other): self
    {
        $this->requireSameScale($other);

        $result = self::checkedAdd($this->unscaledValue, -$other->unscaledValue);

        return new self($result, $this->decimalScale);
    }

    /** 乘法；两侧 scale 必须一致。 */
    public function mul(self $other): self
    {
        $this->requireSameScale($other);

        $a = $this->unscaledValue;
        $b = $other->unscaledValue;

        // 零乘任何数都是 0，且不应误报中间量溢出。
        if ($a === 0 || $b === 0) {
            return new self(0, $this->decimalScale);
        }

        $magnitudeA = self::magnitude($a);
        $magnitudeB = self::magnitude($b);

        // 契约 10：a × b 的溢出必须在真正相乘之前判出。
        if ($magnitudeA > intdiv(PHP_INT_MAX, $magnitudeB)) {
            throw new DecException('乘法中间量超出 PHP int 范围');
        }

        $product = $a * $b;
        $rounded = self::halfUpDivide($product, self::POW10[$this->decimalScale]);

        return new self($rounded, $this->decimalScale);
    }

    /** 除法；两侧 scale 必须一致，除不尽按 HALF_UP 舍入到当前 scale。 */
    public function div(self $other): self
    {
        $this->requireSameScale($other);

        $a = $this->unscaledValue;
        $b = $other->unscaledValue;

        if ($b === 0) {
            throw new DecException('除数不能为零');
        }

        if ($a === 0) {
            return new self(0, $this->decimalScale);
        }

        $power = self::POW10[$this->decimalScale];

        // 契约 10：被除数 × 10^scale 的溢出同样在相乘之前判出。
        if (self::magnitude($a) > intdiv(PHP_INT_MAX, $power)) {
            throw new DecException('除法中间量超出 PHP int 范围');
        }

        $numerator = $a * $power;

        // 统一成「正除数 + 带符号被除数」，符号随商走。
        if ($b < 0) {
            $numerator = -$numerator;
            $b = -$b;
        }

        $rounded = self::halfUpDivide($numerator, $b);

        return new self($rounded, $this->decimalScale);
    }

    /** 比较：小于返回负、相等返回 0、大于返回正；两侧 scale 必须一致。 */
    public function compareTo(self $other): int
    {
        $this->requireSameScale($other);

        return $this->unscaledValue <=> $other->unscaledValue;
    }

    /** 相等：scale 与微单位都相同才算相等。 */
    public function equals(self $other): bool
    {
        return $this->decimalScale === $other->decimalScale
            && $this->unscaledValue === $other->unscaledValue;
    }

    /** 哈希：相等的值必须给同一个结果。 */
    public function hashCode(): int
    {
        return $this->unscaledValue ^ (($this->decimalScale & 63) * 2654435761);
    }

    /** 按 scale 补零输出；零不带符号。 */
    public function toString(): string
    {
        $unscaled = $this->unscaledValue;
        $scale = $this->decimalScale;

        if ($unscaled === 0) {
            return $scale === 0 ? '0' : '0.' . str_repeat('0', $scale);
        }

        $negative = $unscaled < 0;
        $digits = (string) ($negative ? -$unscaled : $unscaled);

        if ($scale === 0) {
            return ($negative ? '-' : '') . $digits;
        }

        if (strlen($digits) <= $scale) {
            $digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);
        }

        $integerPart = substr($digits, 0, strlen($digits) - $scale);
        $fractionPart = substr($digits, -$scale);

        return ($negative ? '-' : '') . $integerPart . '.' . $fractionPart;
    }

    /** 按 $mode（TRUNC / FLOOR / CEIL / HALF_UP）取整为整数。 */
    public function toInt(int $mode): int
    {
        if (
            $mode !== self::TRUNC
            && $mode !== self::FLOOR
            && $mode !== self::CEIL
            && $mode !== self::HALF_UP
        ) {
            throw new DecException('未知的取整模式: ' . $mode);
        }

        $unscaled = $this->unscaledValue;

        if ($unscaled === 0) {
            return 0;
        }

        $negative = $unscaled < 0;
        $magnitude = $negative ? -$unscaled : $unscaled;
        $power = self::POW10[$this->decimalScale];

        $quotient = intdiv($magnitude, $power);
        $remainder = $magnitude % $power;

        switch ($mode) {
            case self::TRUNC:
                break;
            case self::FLOOR:
                if ($negative && $remainder !== 0) {
                    $quotient++;
                }
                break;
            case self::CEIL:
                if (!$negative && $remainder !== 0) {
                    $quotient++;
                }
                break;
            case self::HALF_UP:
                // 用 r >= d-r 代替 2r >= d，避免比较本身溢出。
                if ($remainder !== 0 && $remainder >= $power - $remainder) {
                    $quotient++;
                }
                break;
        }

        return $negative ? -$quotient : $quotient;
    }

    private function requireSameScale(self $other): void
    {
        if ($this->decimalScale !== $other->decimalScale) {
            throw new DecException(
                'scale 不一致: ' . $this->decimalScale . ' 与 ' . $other->decimalScale
            );
        }
    }

    /** 两个非负/同号整数相加，越界在相加之前判出。 */
    private static function checkedAdd(int $a, int $b): int
    {
        if ($a > 0 && $b > 0 && $a > PHP_INT_MAX - $b) {
            throw new DecException('加法结果超出 PHP int 范围');
        }

        if ($a < 0 && $b < 0) {
            $magnitudeA = -$a;
            $magnitudeB = -$b;
            if ($magnitudeA > PHP_INT_MAX - $magnitudeB) {
                throw new DecException('加法结果超出 PHP int 范围');
            }
        }

        return $a + $b;
    }

    /**
     * 有符号整数除以正除数，HALF_UP（小数部分恰为 .5 时远离零）。
     * 全程整数：余数比较用 r >= d-r，不做 2*r 的乘法。
     */
    private static function halfUpDivide(int $numerator, int $positiveDivisor): int
    {
        $negative = $numerator < 0;
        $magnitude = $negative ? -$numerator : $numerator;

        $quotient = intdiv($magnitude, $positiveDivisor);
        $remainder = $magnitude % $positiveDivisor;

        if ($remainder !== 0 && $remainder >= $positiveDivisor - $remainder) {
            $quotient++;
        }

        return $negative ? -$quotient : $quotient;
    }

    private static function magnitude(int $value): int
    {
        return $value < 0 ? -$value : $value;
    }

    /** 十进制数字符串 +1（最长多一位）。 */
    private static function incrementDecimal(string $digits): string
    {
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            if ($digits[$i] !== '9') {
                $digits[$i] = chr(ord($digits[$i]) + 1);

                return $digits;
            }
            $digits[$i] = '0';
        }

        return '1' . $digits;
    }

    /** 无符号十进制数字符串不得超过 PHP_INT_MAX。 */
    private static function assertWithinIntRange(string $digits): void
    {
        $max = (string) PHP_INT_MAX;

        if (strlen($digits) > strlen($max)
            || (strlen($digits) === strlen($max) && strcmp($digits, $max) > 0)) {
            throw new DecException('数值超出 PHP int 范围');
        }
    }
}
