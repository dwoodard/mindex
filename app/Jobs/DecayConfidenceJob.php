<?php

namespace App\Jobs;

use App\Services\Contracts\GraphServiceInterface;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Tries(3)]
#[Backoff(60, 300)]
class DecayConfidenceJob implements ShouldQueue
{
    use Queueable;

    public function handle(GraphServiceInterface $graph): void
    {
        $rate = (float) config('mindex.decay.rate', 0.02);
        $days = (int) config('mindex.decay.not_reinforced_days', 7);
        $since = Carbon::now()->subDays($days);

        $updated = $graph->decayConfidence($rate, $since);

        Log::info('Confidence decay completed', [
            'nodes_updated' => $updated,
            'rate' => $rate,
            'not_reinforced_since' => $since->toDateString(),
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('DecayConfidenceJob failed', ['error' => $e->getMessage()]);
    }
}
