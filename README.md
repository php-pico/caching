# php-pico/caching

A dependency-light PSR-16 (`psr/simple-cache`) and PSR-6 (`psr/cache`) cache for
PHP 8.5+.

Two facades — the PSR-16 `Cache` and the PSR-6 `CachePool` — sit over the same
swappable **drivers**. The facades own key validation, value (de)serialization
and TTL handling; each driver only stores and retrieves the resulting string
payloads. This keeps drivers thin, interchangeable, and independently testable,
and lets both standards share one backend.

## Installation

```bash
composer require php-pico/caching
```

## PSR-16 (simple cache)

Construct a `Cache` with the driver of your choice and use it through the
standard PSR-16 API:

```php
use PhpPico\Caching\Cache;
use PhpPico\Caching\Driver\Testing\StaticDriver;

$cache = new Cache(new StaticDriver());

$cache->set('user.42', ['name' => 'Ada'], ttl: 3600);
$cache->get('user.42');                 // ['name' => 'Ada']
$cache->has('user.42');                 // true
$cache->delete('user.42');

$cache->setMultiple(['a' => 1, 'b' => 2]);
$cache->getMultiple(['a', 'b', 'c'], default: null);
$cache->deleteMultiple(['a', 'b']);
$cache->clear();
```

`get()`/`set()`/`delete()`/`clear()`/`has()` and the `*Multiple` variants all
behave per the PSR-16 spec. Keys are validated (max 64 chars, `[A-Za-z0-9_.]`);
an illegal key throws a PSR-16 `InvalidArgumentException`.

## PSR-6 (cache item pool)

Construct a `CachePool` with the same drivers and use the PSR-6 item API. Items
are obtained from the pool via `getItem()`/`getItems()` — never instantiated
directly.

```php
use PhpPico\Caching\CachePool;
use PhpPico\Caching\Driver\Filesystem\FilesystemDriver;

$pool = new CachePool(new FilesystemDriver('/var/cache/app'));

$item = $pool->getItem('user.42');
if (!$item->isHit()) {
    $item->set(['name' => 'Ada'])->expiresAfter(3600);
    $pool->save($item);
}
$item->get();                           // ['name' => 'Ada']

$pool->hasItem('user.42');              // true
$pool->deleteItem('user.42');
$pool->clear();
```

Expiration is set on the item with `expiresAfter(int|\DateInterval|null)` or
`expiresAt(?\DateTimeInterface)`. `getItem()` always returns an item (never null)
— a miss is an item whose `isHit()` is `false` and whose `get()` is `null`.

**Deferred writes.** Queue items and flush them together with `commit()`. A
deferred item is already visible to `getItem()`/`hasItem()` before the commit:

```php
$pool->saveDeferred($pool->getItem('a')->set(1));
$pool->saveDeferred($pool->getItem('b')->set(2));

$pool->hasItem('a');                    // true (visible before commit)
$pool->commit();                        // persist both
```

Illegal keys throw a `Psr\Cache\InvalidArgumentException`. Because the exception
implements both PSR interfaces, the PSR-16 and PSR-6 facades can share one driver
and interoperate — a value written through one reads back through the other.

## Drivers

All drivers implement `PhpPico\Caching\Driver\Driver` and are interchangeable
between the PSR-16 `Cache` and the PSR-6 `CachePool`.

| Driver | Backing store | Persistence | Notes |
|--------|---------------|-------------|-------|
| `StaticDriver` | in-memory array | current request only | great for tests; nothing survives the request |
| `NoopDriver` | none | never | discards writes, always misses — disables caching transparently |
| `FilesystemDriver` | JSON files on disk | durable | TTL enforced on read; guards against directory traversal |
| `RedisDriver` | Redis / Valkey over a socket | durable | hand-rolled RESP2, TTL via `EXAT`, optional reconnect |

```php
use PhpPico\Caching\Cache;
use PhpPico\Caching\Driver\Testing\NoopDriver;
use PhpPico\Caching\Driver\Testing\StaticDriver;
use PhpPico\Caching\Driver\Filesystem\FilesystemDriver;

new Cache(new StaticDriver());
new Cache(new NoopDriver());
new Cache(new FilesystemDriver('/var/cache/app'));
```

### RedisDriver

`RedisDriver` speaks the RESP2 protocol directly over a TCP stream — it needs no
`ext-redis` or other extension. Because it only depends on RESP2, it works with
**Redis, Valkey, and any other RESP2-compatible server** (KeyDB, DragonflyDB,
etc.). It is validated against Redis 8.x/7.x/6.x and Valkey 9.x/8.x/7.x.

A `RedisDriver` takes a `RedisConnection`, built one of two ways.

**From connection details** — dials Redis lazily on first use:

```php
use PhpPico\Caching\Cache;
use PhpPico\Caching\Driver\Redis\RedisConnection;
use PhpPico\Caching\Driver\Redis\RedisDriver;

$connection = RedisConnection::build(
    host: '127.0.0.1',
    port: 6379,
    timeoutSeconds: 3,
    database: 0,
    reconnectTries: 1,
);

$cache = new Cache(new RedisDriver($connection));
```

**From an existing stream** — bring your own socket (e.g. a pre-opened,
pooled, or already-authenticated connection):

```php
$stream = stream_socket_client('tcp://127.0.0.1:6379');

$connection = RedisConnection::fromStream($stream, database: 0);
$cache = new Cache(new RedisDriver($connection));
```

#### Selecting a database

`build()` selects its `database` automatically on first use (a fresh connection
starts on db 0, so a `SELECT` is only sent when `database > 0`). A connection
built from a supplied stream is not auto-selected — call `select()` yourself:

```php
$connection->select(1);
$connection->currentDatabase();   // 1
```

#### Reconnection

On a dropped connection (server restart, failover, idle kill), `RedisDriver`
transparently reconnects and replays the failed command, up to `reconnectTries`
times with a short backoff between attempts. This matters for long-lived
runtimes (FrankenPHP, ReactPHP, Swoole, queue workers) where a single connection
is reused across many requests; classic per-request PHP-FPM rarely needs it.

Only commands that are safe to run twice are replayed — every command this
driver issues is idempotent (`GET`/`SET`/`DEL`/`MSET`/`MSETEX`/`EXISTS`/
`FLUSHDB`, with writes using an absolute `EXAT` so a delayed replay does not
drift the TTL). A protocol error (a Redis `-ERR` reply) is never retried. A
connection built from a supplied stream cannot re-dial, so it surfaces the
failure instead of reconnecting.

Set `reconnectTries: 0` to disable reconnection. A transport failure that cannot
be recovered throws `PhpPico\Caching\Driver\Redis\Exceptions\RedisConnectionException`
(a subclass of the PSR-16 `CacheException`).

## Custom drivers

Implement `PhpPico\Caching\Driver\Driver`. Drivers operate on already-serialized
`string` payloads and an absolute `?int $expiresAt` timestamp — the facades
handle key validation and (de)serialization. The `DriverTrait` provides naive
multi-key operations built on the single-key methods, so a driver only has to
implement `get`/`set`/`delete`/`clear`/`has`. A new driver works with both the
PSR-16 and PSR-6 facades automatically.

## Development

```bash
composer test     # PHPUnit
composer mago      # static analysis (mago)
composer format    # code style (mago)
composer qa        # format + analyze + test
```

## License

MIT.
