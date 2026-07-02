<?php

declare(strict_types=1);

namespace PhpPico\Caching\Exceptions;

use Exception;
use Psr\SimpleCache\CacheException as CacheExceptionInterface;

class CacheException extends Exception implements CacheExceptionInterface {}
