<?php

declare(strict_types=1);

namespace PhpPico\Caching\Exceptions;

use Exception;
use Psr\Cache\InvalidArgumentException as Psr6InvalidArgumentException;
use Psr\SimpleCache\InvalidArgumentException as Psr16InvalidArgumentException;

final class InvalidArgumentException extends Exception implements
    Psr16InvalidArgumentException,
    Psr6InvalidArgumentException {}
