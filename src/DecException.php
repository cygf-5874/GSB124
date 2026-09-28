<?php

declare(strict_types=1);

/**
 * fixeddec 的统一异常类型（契约 2、4、5、10）。
 *
 * 解析非法、scale 不一致、除零、溢出一律以 DecException 结束，
 * 不得让原生 TypeError / Warning / DivisionByZeroError 泄漏出去。
 * 这是一个纯异常类型，没有需要实现的方法体。
 */
class DecException extends RuntimeException
{
}
