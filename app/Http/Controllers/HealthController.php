<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * FR-21: reports app, Redis, global DB, and per-shard status.
 *
 * Returns 503 when any dependency is down so load balancers and the
 * benchmark runner can refuse to start against a broken stack.
 */
class HealthController
{
    public function __invoke(): JsonResponse
    {
        $shards = [];
        foreach ([...config('database.shards.regular'), ...config('database.shards.dedicated')] as $shard) {
            $shards[$shard] = $this->check(fn () => DB::connection($shard)->select('select 1'), $shard);
        }

        $status = [
            'app' => 'ok',
            'redis' => $this->check(fn () => Redis::connection()->ping(), 'redis'),
            'global' => $this->check(fn () => DB::connection('global')->select('select 1'), 'global'),
            'shards' => $shards,
        ];

        $healthy = $status['redis'] === 'ok'
            && $status['global'] === 'ok'
            && ! in_array('down', $shards, true);

        return response()->json($status, $healthy ? 200 : 503);
    }

    private function check(callable $probe, string $name): string
    {
        try {
            $probe();

            return 'ok';
        } catch (Throwable $e) {
            // Details go to the log only; the public response never leaks hosts or credentials.
            Log::warning('Health check failed', ['dependency' => $name, 'error' => $e->getMessage()]);

            return 'down';
        }
    }
}
