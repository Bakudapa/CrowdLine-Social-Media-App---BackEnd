<?php

use App\Sharding\ShardPlacement;

// Step 1: basic mapping. The remainder of userId / N picks a position in the list.
it('places a user on the shard at position userId mod N', function (int $userId, string $expected) {
    $shards = ['shard_0', 'shard_1', 'shard_2'];

    expect((new ShardPlacement)->shardFor($userId, $shards))->toBe($expected);
})->with([
    'id 1 -> remainder 1' => [1, 'shard_1'],
    'id 2 -> remainder 2' => [2, 'shard_2'],
    'id 3 -> remainder 0' => [3, 'shard_0'],
    'id 7 -> remainder 1' => [7, 'shard_1'],
    'id 8 -> remainder 2' => [8, 'shard_2'],
    'large id 1000002 -> remainder 0' => [1_000_002, 'shard_0'],
]);

it('follows the order of the given list, not the shard names', function () {
    $reversed = ['shard_2', 'shard_1', 'shard_0'];
    $placement = new ShardPlacement;

    // Remainder 0 -> first position, remainder 2 -> last position, whatever their names are.
    expect($placement->shardFor(3, $reversed))->toBe('shard_2')
        ->and($placement->shardFor(8, $reversed))->toBe('shard_0');
});

// Step 2: even distribution. Sequential ids must spread equally, otherwise one shard
// starts "hot" before any celebrity even exists.
it('spreads sequential ids evenly across shards', function (int $shardCount) {
    $shards = array_map(fn (int $i) => "shard_{$i}", range(0, $shardCount - 1));
    $placement = new ShardPlacement;

    $perShard = array_count_values(array_map(
        fn (int $id) => $placement->shardFor($id, $shards),
        range(1, 300 * $shardCount),
    ));

    expect($perShard)->toHaveCount($shardCount)
        ->and(array_unique(array_values($perShard)))->toBe([300]);
})->with([2, 3, 4]);

// Step 3: deterministic. The same input always gives the same shard, and a different
// shard list only changes the answer for the call itself (it never moves stored users).
it('returns the same shard for the same input every time', function () {
    $shards = ['shard_0', 'shard_1', 'shard_2'];
    $placement = new ShardPlacement;

    $results = array_map(fn () => $placement->shardFor(42, $shards), range(1, 100));

    expect(array_unique($results))->toBe(['shard_0']);
});

it('uses the list it is given, so a new shard count only affects new placements', function () {
    $placement = new ShardPlacement;

    // User 10 registered while there were 3 shards ...
    $atRegistration = $placement->shardFor(10, ['shard_0', 'shard_1', 'shard_2']);
    // ... later a 4th shard is added: the formula alone now gives a different answer.
    $withFourShards = $placement->shardFor(10, ['shard_0', 'shard_1', 'shard_2', 'shard_3']);

    expect($atRegistration)->toBe('shard_1')
        ->and($withFourShards)->toBe('shard_2');
    // This difference is exactly why lookups must read shard_map (FR-06), never recompute.
});

// Step 4: invalid input is rejected up front with a clear exception, instead of failing
// halfway (e.g. "Modulo by zero") or silently returning a shard for a nonsense id.
it('rejects an empty shard list', function () {
    (new ShardPlacement)->shardFor(5, []);
})->throws(InvalidArgumentException::class);

it('rejects user ids below 1', function (int $userId) {
    (new ShardPlacement)->shardFor($userId, ['shard_0', 'shard_1', 'shard_2']);
})->throws(InvalidArgumentException::class)->with([
    'zero' => [0],
    'negative' => [-1],
    'large negative' => [-300],
]);

it('accepts the smallest valid input: id 1 and a single shard', function () {
    expect((new ShardPlacement)->shardFor(1, ['shard_only']))->toBe('shard_only');
});
