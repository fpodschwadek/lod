<?php

declare(strict_types=1);

namespace Tests\Support;

use ErrorException;

/**
 * Turns PHP warnings and notices raised by the code under test into exceptions.
 *
 * PHPUnit only reports them, but the development context of the application turns E_WARNING into
 * an exception (SYS.exceptionalErrors), so a warning there aborts the request. Tests that pin
 * down "no warning" behaviour wrap the call in withPhpErrorsAsExceptions().
 */
trait FailOnPhpErrorsTrait
{
    /**
     * @template T
     * @param callable(): T $callable
     * @return T
     */
    protected function withPhpErrorsAsExceptions(callable $callable): mixed
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            return $callable();
        } finally {
            restore_error_handler();
        }
    }
}
