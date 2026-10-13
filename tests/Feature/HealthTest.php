<?php

use Illuminate\Support\Facades\DB;

// Shard names come from config so the suite runs on any topology (3 shards locally, 2 in CI).
function allShards(): array
{
    return [...config('database.shards.regular'), ...config('database.shards.dedicated')];
}

it('reports every dependency as ok', function () {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJson([
            'app' => 'ok',
            'redis' => 'ok',
            'global' => 'ok',
            'shards' => array_fill_keys(allShards(), 'ok'),
        ]);
});

it('returns 503 and marks the shard down when a shard is unreachable', function () {
    [$healthy, $broken] = config('database.shards.regular');

    config(["database.connections.{$broken}.host" => '127.0.0.1', "database.connections.{$broken}.port" => '1']);
    DB::purge($broken);

    $this->getJson('/api/v1/health')
        ->assertServiceUnavailable()
        ->assertJsonPath("shards.{$broken}", 'down')
        ->assertJsonPath("shards.{$healthy}", 'ok');
});

it('does not require authentication', function () {
    $this->getJson('/api/v1/health')->assertOk();
});
