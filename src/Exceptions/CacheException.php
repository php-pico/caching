<?php

declare(strict_types=1);

namespace PhpPico\Caching\Exceptions;

use Exception;
use Psr\Cache\CacheException as Psr6CacheException;
use Psr\SimpleCache\CacheException as Psr16CacheException;

class CacheException extends Exception implements Psr16CacheException, Psr6CacheException {}
