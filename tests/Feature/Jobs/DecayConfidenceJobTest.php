<?php

use App\Jobs\DecayConfidenceJob;
use App\Services\Contracts\GraphServiceInterface;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;

it('calls decayConfidence with config-driven rate and since date', function (): void {
    config([
        'mindex.decay.rate' => 0.05,
        'mindex.decay.not_reinforced_days' => 7,
    ]);

    Carbon::setTestNow('2026-01-10 02:00:00');

    $graph = Mockery::mock(GraphServiceInterface::class);
    $graph->shouldReceive('decayConfidence')
        ->once()
        ->withArgs(function (float $rate, Carbon $since): bool {
            return $rate === 0.05
                && $since->toDateString() === '2026-01-03';
        })
        ->andReturn(42);

    (new DecayConfidenceJob)->handle($graph);

    Carbon::setTestNow();
});

it('logs the number of nodes updated', function (): void {
    config([
        'mindex.decay.rate' => 0.02,
        'mindex.decay.not_reinforced_days' => 7,
    ]);

    Log::spy();

    $graph = Mockery::mock(GraphServiceInterface::class);
    $graph->shouldReceive('decayConfidence')->andReturn(15);

    (new DecayConfidenceJob)->handle($graph);

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(fn (string $msg, array $ctx) => $msg === 'Confidence decay completed' && $ctx['nodes_updated'] === 15);
});

it('logs on failure', function (): void {
    Log::spy();

    $e = new RuntimeException('Neo4j connection refused');

    (new DecayConfidenceJob)->failed($e);

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn (string $msg, array $ctx) => $msg === 'DecayConfidenceJob failed' && $ctx['error'] === 'Neo4j connection refused');
});

it('is scheduled daily at 2am', function (): void {
    $events = collect(app(Schedule::class)->events());

    $job = $events->first(
        fn ($e) => str_contains($e->description ?? $e->command ?? '', 'DecayConfidenceJob'),
    );

    expect($job)->not->toBeNull()
        ->and($job->expression)->toBe('0 2 * * *');
});
