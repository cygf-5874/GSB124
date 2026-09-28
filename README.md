# fixeddec —— PHP 8 的定点十进制库

金额算到分，中间不许出现浮点。把一个十进制数表示成「整数微单位」：`值 = 微单位 / 10^scale`，
加、减、乘、除、比较全部走整数运算。

- 语言/框架：PHP 8，**仅标准库**（php-cli，无 composer，无第三方依赖，不用 mbstring）。
- 自检入口：`bash scripts/check.sh`（内部就是 `php check/check.php`）。
  `check/` 是**固定验收程序**，不要改。
- 两个类在 `src/` 下：`FixedDec`（定点数）、`DecException`（非法入参 / 运算非法）。

## 快速开始

```bash
php -l src/FixedDec.php          # 语法检查
php tests/run.php                # 既有用例，14 个
bash scripts/check.sh            # 跑全部 10 个场景，全过才 exit 0
bash scripts/check.sh -list      # 列出全部场景
bash scripts/check.sh --only arith
```

## 公开 API

```php
FixedDec::of(string $value, int $scale): FixedDec

$d->scale(): int
$d->unscaled(): int
$d->add(FixedDec $other): FixedDec
$d->sub(FixedDec $other): FixedDec
$d->mul(FixedDec $other): FixedDec
$d->div(FixedDec $other): FixedDec
$d->compareTo(FixedDec $other): int
$d->equals(FixedDec $other): bool
$d->hashCode(): int
$d->toString(): string
$d->toInt(int $mode): int          // $mode ∈ {TRUNC, FLOOR, CEIL, HALF_UP}
```

公开方法名与签名已定死，不得更改；可以新增类文件。

## 对外契约（10 条）

1. `FixedDec::of(string $value, int $scale)`：把十进制字符串解析成**整数微单位**表示
   （`$scale` 位小数 → 值 × 10^scale 存为整数）。`unscaled()` 返回该整数、`scale()` 返回 `$scale`。
   **实现全程不得使用浮点**（`float` / `double` / `floatval` / `(float)` / `round` / `floor` / `ceil` 等一律禁止）。
2. 解析接受：可选前导 `+` / `-`；整数部分可省略（`.5`）；小数部分可省略（`5.`）；
   两侧都为空的串、多个小数点、指数形式（`1e3`）、含空白 / 其它字符、`$scale < 0`
   一律抛 `DecException`。`$value` 与 `$scale` 非法时不得泄漏原生 `TypeError` / `Warning`。
3. **舍入统一是 HALF_UP（.5 远离零）、只出现在三处**：`of` 解析时超出 `$scale` 的位数、
   `mul` 与 `div` 把结果落到当前 scale 时的余数。`12.345` 在 `scale=2` 下是 `12.35`；
   `-12.345` 是 `-12.35`；`2.4` 在 `scale=0` 下是 `2`。除这三处外不得再引入任何舍入。
4. `add` / `sub` / `mul` / `div`：不产生新的 scale —— **两侧 scale 必须一致**，否则抛 `DecException`；
   运算结果是与两侧同一 scale 的 `FixedDec`。设 `P = 10^scale`，微单位口径为：
   `add`：`a + b`；`sub`：`a - b`；`mul`：`round(a × b / P)`；`div`：`round(a × P / b)`。
   其中 `round` 就是第 3 条的 HALF_UP。`mul` 的中间量 `a × b` 与 `div` 的中间量 `a × P`
   都要按第 10 条先判溢出。
5. `div`：除数为零抛 `DecException`；除不尽时按 **HALF_UP** 舍入到当前 scale（不抛错）。
   例：`scale=2` 下 `2.00 / 3.00 = 0.67`，`1.00 / 3.00 = 0.33`；`scale=0` 下 `5 / 2 = 3`。
6. `compareTo`：返回负 / 零 / 正，**纯整数比较**（不得先转 `float`）；两侧 scale 必须一致，否则抛 `DecException`。
7. `equals` / `hashCode`：**scale 与微单位都相同**才算相等（`5.00` 与 `5.0` 不相等，
   `-5.00` 与 `5.00` 不相等；零与负零都归零，二者相等）；相等的值必须给同一个 `hashCode`。
8. `toString()`：按 scale 补零输出（`scale=2` 的 `5` → `"5.00"`；`scale=1` 的 `123.4` → `"123.4"`）；
   负值保留符号（`-0.05`）；**值为零则不得输出符号**（`-0.00` 非法，必须是 `"0.00"`）。
9. `toInt(int $mode)`：把值取整为 PHP `int`。`$mode` 取 `FixedDec::TRUNC`（向零）、
   `FixedDec::FLOOR`（向 −∞）、`FixedDec::CEIL`（向 +∞）、`FixedDec::HALF_UP`（.5 远离零）；
   其它取值抛 `DecException`。
10. **溢出**：解析或运算结果的微单位超出 PHP `int` 范围时抛 `DecException`，
    且判断必须在乘法 / 加法**之前**做（不得先算出 float 再补救）。
    结果与 PHP 数组键顺序无关；同一输入多次调用结果完全相同。

## 补充说明（只是把判据说死）

- 舍入一律「远离零」：`-0.005` 在 `scale=2` 下是 `-0.01`，`-0.004` 是 `0.00`。
- 除法的中间量（被除数 × 10^scale）若溢出，同样按契约 10 抛 `DecException`。
- 非法入参一律以 `DecException` 结束，不许把原生 `TypeError` / `Warning` / `DivisionByZeroError` 泄漏出来。

## 目录

```
README.md          本文件
PROMPT.md          出题用的 User Prompt
autoload.php       极简 spl_autoload_register（__DIR__ 相对定位 src/）
src/FixedDec.php   定点数        ← 现在只有签名，方法体全抛错
src/DecException.php 统一异常
tests/run.php      既有用例，14 个，起点全红
check/check.php    固定验收程序（别改）
scripts/check.sh   固定验收的 shell 入口
```
