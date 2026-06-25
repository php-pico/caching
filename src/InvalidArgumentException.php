<?php

declare(strict_types=1);

namespace PhpPico\Caching;

use Exception;
use Psr\SimpleCache\InvalidArgumentException as InvalidArgumentExceptionInterface;

final class InvalidArgumentException extends Exception implements InvalidArgumentExceptionInterface {}
