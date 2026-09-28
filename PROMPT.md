金额算到分，中间不许出现浮点。fixeddec 是 PHP 8 的定点十进制库
（php-cli，无 composer，无第三方依赖），构建不需要额外步骤，
自检走 `scripts/check.sh`（`check/` 是固定验收程序，别改），既有用例走 `php tests/run.php`。

`src/FixedDec.php` 现在只有签名，方法体全抛错；`src/DecException.php` 已经给全。

任务：按 README「对外契约」的 10 条实现，让 14 个既有用例转绿、固定件全过。

验收：
- php -l src/FixedDec.php 退出码 0；
- php tests/run.php 14/14 全绿；
- php check/check.php 退出码 0，10 个场景全过（parse 3 + arith 3 + cmp 2 + format 1 + overflow 1）。

约束：
1. 不改 `check/`、不改 `src/DecException.php`；可以新增类文件。
2. 对外方法名与签名已定死，不要改；`tests/run.php` 里的用例一条都不许删或改。
3. 不许用 composer，不许引入任何第三方库；不许用 mbstring 扩展。
4. 全程不许出现浮点运算。
