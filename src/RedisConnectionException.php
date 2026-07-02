<?php

declare(strict_types=1);

namespace PhpPico\Caching;

/**
 * Thrown when the Redis transport fails: the socket could not be dialed, or the
 * connection dropped while writing a command or reading its reply.
 *
 * Distinct from CacheException so callers (and the driver's retry loop) can tell
 * a transport failure apart from a protocol-level error such as a Redis "-ERR"
 * reply, which is not safe to blindly retry.
 */
final class RedisConnectionException extends CacheException {}
