<?php

declare(strict_types=1);

/**
 * 定点十进制数：值 = 微单位 / 10^scale，全程以 PHP int 承载微单位。
 *
 * 对外契约见 README「对外契约」的 10 条。当前只有签名，方法体全抛错。
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

    /**
     * 解析十进制字符串。数值 = 微单位 / 10^$scale。
     */
    public static function of(string $value, int $scale): self
    {
        throw new \LogicException('not implemented');
    }

    /** 小数位数。 */
    public function scale(): int
    {
        throw new \LogicException('not implemented');
    }

    /** 微单位（整数表示，值 = 微单位 / 10^scale）。 */
    public function unscaled(): int
    {
        throw new \LogicException('not implemented');
    }

    /** 加法；两侧 scale 必须一致。 */
    public function add(self $other): self
    {
        throw new \LogicException('not implemented');
    }

    /** 减法；两侧 scale 必须一致。 */
    public function sub(self $other): self
    {
        throw new \LogicException('not implemented');
    }

    /** 乘法；两侧 scale 必须一致。 */
    public function mul(self $other): self
    {
        throw new \LogicException('not implemented');
    }

    /** 除法；两侧 scale 必须一致，除不尽按 HALF_UP 舍入到当前 scale。 */
    public function div(self $other): self
    {
        throw new \LogicException('not implemented');
    }

    /** 比较：小于返回负、相等返回 0、大于返回正；两侧 scale 必须一致。 */
    public function compareTo(self $other): int
    {
        throw new \LogicException('not implemented');
    }

    /** 相等：scale 与微单位都相同才算相等。 */
    public function equals(self $other): bool
    {
        throw new \LogicException('not implemented');
    }

    /** 哈希：相等的值必须给同一个结果。 */
    public function hashCode(): int
    {
        throw new \LogicException('not implemented');
    }

    /** 按 scale 补零输出；零不带符号。 */
    public function toString(): string
    {
        throw new \LogicException('not implemented');
    }

    /** 按 $mode（TRUNC / FLOOR / CEIL / HALF_UP）取整为整数。 */
    public function toInt(int $mode): int
    {
        throw new \LogicException('not implemented');
    }
}
