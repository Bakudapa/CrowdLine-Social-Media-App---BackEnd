<?php

use Illuminate\Support\Facades\DB;

it('registers a database connection for every configured shard', function () {
    $shards = allShards();

    expect($shards)->not->toBeEmpty();

    foreach ($shards as $shard) {
        expect(config("database.connections.{$shard}.driver"))->toBe('pgsql');
    }
});

it('can reach every shard', function () {
    foreach (allShards() as $shard) {
        expect(DB::connection($shard)->selectOne('select 1 as ok')->ok)->toBe(1);
    }
});

it('rejects unknown shard names', function () {
    $this->artisan('shards:migrate', ['--shard' => ['does_not_exist']])
        ->expectsOutputToContain('Unknown shard(s): does_not_exist')
        ->assertFailed();
});
