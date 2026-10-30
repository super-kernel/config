<?php

declare(strict_types=1);

namespace SuperKernel\Config\Loading;

use SuperKernel\Config\Exception\ConfigExceptionInterface;
use Throwable;

/**
 * Finds the nearest Config exception in a throwable's chain of previous exceptions.
 *
 * @internal
 */
final class ConfigExceptionCause
{
    public static function find(Throwable $throwable): ?ConfigExceptionInterface
    {
        $cause = $throwable->getPrevious();

        while (null !== $cause) {
            if ($cause instanceof ConfigExceptionInterface) {
                return $cause;
            }

            $cause = $cause->getPrevious();
        }

        return null;
    }
}
