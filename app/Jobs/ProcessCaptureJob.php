<?php

namespace App\Jobs;

use App\DTOs\GraphNodeDraft;
use App\DTOs\IntentDeclaration;
use App\DTOs\ValidatedPayload;
use App\DTOs\WritePayload;
use App\Enums\WriteIntent;
use App\Events\ConflictDetectedEvent;
use App\Events\GraphUpdatedEvent;
use App\Events\PipelineStatusEvent;
use App\Events\ReplyEvent;
use App\Services\Contracts\GraphServiceInterface;
use App\Services\ExtractionService;
use App\Services\IntentValidatorService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

#[Tries(3)]
#[Backoff(30, 60, 120)]
class ProcessCaptureJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $input,
        public readonly int $userId,
        public readonly string $sessionId,
        public readonly bool $listenMode = true,
    ) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            new WithoutOverlapping($this->sessionId),
        ];
    }

    /**
     * Process the capture through the full extraction → validation → write pipeline.
     */
    public function handle(
        GraphServiceInterface $graph,
        ExtractionService $extractor,
        IntentValidatorService $validator,
    ): string {
        broadcast(new PipelineStatusEvent($this->sessionId, 'queued'));

        $normalised = mb_convert_encoding($this->input, 'UTF-8', 'auto');

        // --- Extract ---
        broadcast(new PipelineStatusEvent($this->sessionId, 'extracting'));

        $relatedNodes = $graph->search($normalised, limit: 5);

        $writePayload = $extractor->extract($normalised, $relatedNodes, $this->listenMode);

        // --- Validate ---
        broadcast(new PipelineStatusEvent($this->sessionId, 'validating'));

        $validatedPayload = $validator->validate($writePayload);

        // --- Write ---
        broadcast(new PipelineStatusEvent($this->sessionId, 'writing'));

        $nodesWritten = $this->writeNodes($graph, $validatedPayload);
        $edgesWritten = $this->writeEdges($graph, $validatedPayload);

        $this->reinforceNodes($graph, $validatedPayload->payload);

        // --- Done ---
        broadcast(new PipelineStatusEvent($this->sessionId, 'done'));

        broadcast(new GraphUpdatedEvent(
            userId: $this->userId,
            sessionId: $this->sessionId,
            nodesAdded: $nodesWritten,
            edgesAdded: $edgesWritten,
        ));

        $this->broadcastConflicts($graph, $validatedPayload);

        if (! $this->listenMode && $validatedPayload->payload->reply !== '') {
            broadcast(new ReplyEvent($this->sessionId, $validatedPayload->payload->reply));
        }

        return $validatedPayload->payload->reply;
    }

    /**
     * Broadcast a failed status when the job exhausts all retries.
     */
    public function failed(Throwable $e): void
    {
        broadcast(new PipelineStatusEvent($this->sessionId, 'failed'));
    }

    /**
     * Persist each validated node to the graph and return the count written.
     */
    private function writeNodes(GraphServiceInterface $graph, ValidatedPayload $validatedPayload): int
    {
        $count = 0;

        foreach ($validatedPayload->payload->nodes as $draft) {
            $graph->mergeNode($draft);
            $count++;
        }

        return $count;
    }

    /**
     * Persist each validated edge to the graph and return the count written.
     */
    private function writeEdges(GraphServiceInterface $graph, ValidatedPayload $validatedPayload): int
    {
        $count = 0;

        foreach ($validatedPayload->payload->edges as $draft) {
            $graph->mergeEdge($draft, $this->sessionId);
            $count++;
        }

        return $count;
    }

    /**
     * Call incrementMentionCount for every node declared with a REINFORCE intent.
     */
    private function reinforceNodes(GraphServiceInterface $graph, WritePayload $payload): void
    {
        $reinforcedIds = array_map(
            fn (IntentDeclaration $declaration) => $declaration->node_id,
            array_filter(
                $payload->intents,
                fn (IntentDeclaration $declaration) => $declaration->intent === WriteIntent::Reinforce,
            ),
        );

        foreach ($reinforcedIds as $id) {
            $graph->incrementMentionCount($id);
        }
    }

    /**
     * Broadcast a ConflictDetectedEvent for each flagged contradiction.
     *
     * Skips silently if the existing node cannot be found by id (e.g. race condition).
     */
    private function broadcastConflicts(GraphServiceInterface $graph, ValidatedPayload $validatedPayload): void
    {
        if (empty($validatedPayload->flaggedContradictions)) {
            return;
        }

        $draftsByNodeId = [];

        foreach ($validatedPayload->payload->nodes as $draft) {
            $draftsByNodeId[$draft->id] = $draft;
        }

        foreach ($validatedPayload->flaggedContradictions as $contradiction) {
            $existingNode = $graph->findById($contradiction->node_id);

            if ($existingNode === null) {
                continue;
            }

            $newNodeDraft = $draftsByNodeId[$contradiction->node_id] ?? new GraphNodeDraft(
                id: $contradiction->node_id,
                label: $existingNode->label,
                type: $existingNode->type,
                origin: $existingNode->origin,
                confidence: $existingNode->confidence,
            );

            broadcast(new ConflictDetectedEvent(
                userId: $this->userId,
                existingNode: $existingNode,
                newNode: $newNodeDraft,
            ));
        }
    }
}
