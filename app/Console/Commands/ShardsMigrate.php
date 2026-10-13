<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Runs the shard schema (database/migrations/shard) against every shard.
 *
 * Global tables live in database/migrations and use the plain `migrate`
 * command. Each shard keeps its own `migrations` table, so shards can be
 * migrated, reset, or added independently (FR-05).
 */
#[Signature('shards:migrate
    {--shard=* : Only migrate these shards (default: all regular + dedicated)}
    {--fresh : Drop all tables on each shard before migrating}')]
#[Description('Run shard migrations on every shard connection')]
class ShardsMigrate extends Command
{
    public const MIGRATION_PATH = 'database/migrations/shard';

    public function handle(): int
    {
        $all = [...config('database.shards.regular'), ...config('database.shards.dedicated')];
        $targets = $this->option('shard') ?: $all;

        if ($unknown = array_diff($targets, $all)) {
            $this->error('Unknown shard(s): '.implode(', ', $unknown));

            return self::FAILURE;
        }

        foreach ($targets as $shard) {
            $this->components->info("Migrating {$shard}");

            $exitCode = $this->call($this->option('fresh') ? 'migrate:fresh' : 'migrate', [
                '--database' => $shard,
                '--path' => self::MIGRATION_PATH,
                '--force' => true,
            ]);

            if ($exitCode !== self::SUCCESS) {
                $this->error("Migration failed on {$shard}; remaining shards were not migrated.");

                return $exitCode;
            }
        }

        return self::SUCCESS;
    }
}
