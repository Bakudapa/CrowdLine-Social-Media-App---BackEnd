<?php

use Illuminate\Support\Str;

/*
| Sharding topology (D-001). Shard names double as connection names and,
| by default, as Docker service hostnames. Per-shard overrides:
| DB_<NAME>_HOST / DB_<NAME>_PORT (e.g. DB_SHARD_0_HOST=10.0.0.5).
*/
$shardNames = static fn (string $key, string $default): array => array_values(array_filter(
    array_map('trim', explode(',', (string) env($key, $default)))
));

$pgsql = static fn (string $host, string $port): array => [
    'driver' => 'pgsql',
    'host' => $host,
    'port' => $port,
    'database' => env('DB_DATABASE', 'crowdline'),
    'username' => env('DB_USERNAME', 'crowdline'),
    'password' => env('DB_PASSWORD', ''),
    'charset' => 'utf8',
    'prefix' => '',
    'prefix_indexes' => true,
    'search_path' => 'public',
    'sslmode' => env('DB_SSLMODE', 'prefer'),
    // pdo_pgsql maps ATTR_TIMEOUT to libpq connect_timeout: a dead shard fails fast instead of hanging.
    'options' => [PDO::ATTR_TIMEOUT => (int) env('DB_CONNECT_TIMEOUT', 2)],
];

$shardConnections = [];
foreach ([...$shardNames('SHARDS_REGULAR', 'shard_0,shard_1,shard_2'), ...$shardNames('SHARDS_DEDICATED', 'shard_ded_0')] as $name) {
    $prefix = 'DB_'.strtoupper($name);
    $shardConnections[$name] = $pgsql(env("{$prefix}_HOST", $name), env("{$prefix}_PORT", '5432'));
}

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | "global" holds non-sharded data: users, personal_access_tokens, shard_map.
    | Sharded data is never queried through the default connection; it goes
    | through the shard router (FR-06).
    |
    */

    'default' => env('DB_CONNECTION', 'global'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    */

    'connections' => [
        'global' => $pgsql(env('DB_HOST', 'pg_global'), env('DB_PORT', '5432')),
        ...$shardConnections,
    ],

    /*
    |--------------------------------------------------------------------------
    | Shard Lists
    |--------------------------------------------------------------------------
    |
    | Read via config('database.shards.regular') and config('database.shards.dedicated').
    |
    */

    'shards' => [
        'regular' => $shardNames('SHARDS_REGULAR', 'shard_0,shard_1,shard_2'),
        'dedicated' => $shardNames('SHARDS_DEDICATED', 'shard_ded_0'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

    ],

];
