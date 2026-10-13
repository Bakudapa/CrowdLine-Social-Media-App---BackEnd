<?php

namespace App\Sharding;

use InvalidArgumentException;

/**
 * Decides which shard a NEW user is placed on (FR-07, D-002).
 *
 * This runs once, at registration. The result is stored in shard_map, and
 * every later lookup reads shard_map instead of recomputing (FR-06). That is
 * why changing the shard list later only affects users created afterwards.
 *
 * Pure function: no database, no config, no framework. The caller passes the
 * list of REGULAR shards (never dedicated ones).
 */
class ShardPlacement
{
    /**
     * @param  int  $userId  The new user's id (from the global users table).
     * @param  list<string>  $shards  Regular shard names in a fixed order, e.g. ['shard_0', 'shard_1', 'shard_2'].
     * @return string One of the names in $shards.
     */
    public function shardFor(int $userId, array $shards): string
    {
        // TODO(human): reject invalid input with guard clauses before any calculation (step #4).
        // Context: FR-07. Today an empty list crashes with "Modulo by zero", which does not tell
        //   the caller what they did wrong.
        // Input/Output: empty $shards or $userId < 1 -> throw \InvalidArgumentException with a clear message.
        // Consider:
        //   - Guards go at the top; the code below may then assume the input is valid.
        //   - The message should say what is wrong and what was received (e.g. the user id).
        //   - Do not change the existing three lines; steps #1-#3 must stay green.
        // Tests: tests/Unit/Sharding/ShardPlacementTest.php (step 4)
        $shardCount = count($shards);
        if ($userId <= 0) {
            throw new InvalidArgumentException("User ID cannot be negative or zero {$userId}");
        }
        if ($shardCount <= 0) {
            throw new InvalidArgumentException("Shards cannot be negative or zero, got {$shardCount}.");
        }

        $position = $userId % $shardCount;

        return $shards[$position];
    }
}
