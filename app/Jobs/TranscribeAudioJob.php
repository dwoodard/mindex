<?php

namespace App\Jobs;

use App\Events\PipelineStatusEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

#[Tries(2)]
#[Backoff([30, 120])]
class TranscribeAudioJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $audioPath,
        public readonly int $userId,
        public readonly string $sessionId,
        public readonly bool $listenMode = true,
    ) {}

    public function handle(): void
    {
        broadcast(new PipelineStatusEvent($this->sessionId, 'transcribing'));

        $filePath = Storage::disk('local')->path($this->audioPath);

        $response = Http::withToken(config('services.openai.key'))
            ->timeout(120)
            ->connectTimeout(10)
            ->attach('file', file_get_contents($filePath), basename($filePath))
            ->post('https://api.openai.com/v1/audio/transcriptions', [
                'model' => 'whisper-1',
                'response_format' => 'text',
            ])
            ->throw();

        $transcript = $response->body();

        Storage::disk('local')->delete($this->audioPath);

        ProcessCaptureJob::dispatch(
            input: $transcript,
            userId: $this->userId,
            sessionId: $this->sessionId,
            listenMode: $this->listenMode,
        );
    }

    public function failed(Throwable $e): void
    {
        broadcast(new PipelineStatusEvent($this->sessionId, 'failed'));

        if (Storage::disk('local')->exists($this->audioPath)) {
            Storage::disk('local')->delete($this->audioPath);
        }
    }
}
