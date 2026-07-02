<?php

declare(strict_types=1);

namespace PhpPico\Caching;

use Exception;
use Psr\SimpleCache\CacheException as CacheExceptionInterface;

class CacheException extends Exception implements CacheExceptionInterface {}
