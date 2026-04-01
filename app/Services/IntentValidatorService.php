<?php

namespace App\Services;

use App\DTOs\GraphEdgeDraft;
use App\DTOs\IntentDeclaration;
use App\DTOs\ValidatedPayload;
use App\DTOs\WritePayload;
use App\Enums\WriteIntent;
use App\Services\Contracts\GraphServiceInterface;
use Illuminate\Support\Facades\Log;

/**
 * Last line of defence before any write to the graph.
 *
 * Validates and overrides the AI's declared intents against actual graph state:
 *  - Create → Reinforce when the node already exists.
 *  - Evolve without replaces_id is rejected (node removed from payload).
 *  - Contradict is never auto-written; it is flagged for manual review.
 */
class IntentValidatorService
{
    public function __construct(
        private readonly GraphServiceInterface $graphService,
    ) {}

    public function validate(WritePayload $payload): ValidatedPayload
    {
        $payload = $this->deduplicateByLabel($payload);

        $warnings = [];
        $flaggedContradictions = [];

        $correctedIntents = [];
        $removedNodeIds = [];

        foreach ($payload->intents as $declaration) {
            $result = $this->validateDeclaration($declaration, $warnings);

            if ($result === null) {
                // Rejected — remove from payload entirely.
                $removedNodeIds[] = $declaration->node_id;

                continue;
            }

            if ($result->intent === WriteIntent::Contradict) {
                // Flag for review — do not include in the write payload.
                $flaggedContradictions[] = $result;
                $removedNodeIds[] = $declaration->node_id;

                continue;
            }

            $correctedIntents[] = $result;
        }

        $correctedPayload = new WritePayload(
            nodes: array_values(
                array_filter(
                    $payload->nodes,
                    fn ($node) => ! in_array($node->id, $removedNodeIds, strict: true),
                ),
            ),
            edges: $payload->edges,
            intents: $correctedIntents,
            reply: $payload->reply,
            mood: $payload->mood,
            open_questions: $payload->open_questions,
        );

        return new ValidatedPayload(
            payload: $correctedPayload,
            flaggedContradictions: $flaggedContradictions,
            warnings: $warnings,
        );
    }

    /**
     * Rule 0 — Label deduplication.
     *
     * Detects when the AI assigned a new id to an entity that already exists under
     * a different id but the same label (case-insensitive). Remaps the new id to the
     * existing id throughout nodes, edges, and intents so the downstream CREATE →
     * REINFORCE rule handles it cleanly without writing a duplicate node.
     */
    private function deduplicateByLabel(WritePayload $payload): WritePayload
    {
        // Build a map of newId → existingId for any label collision.
        $remapping = [];

        foreach ($payload->nodes as $node) {
            $existing = $this->graphService->findByLabel($node->label);

            if ($existing !== null && $existing->id !== $node->id) {
                $remapping[$node->id] = $existing->id;

                Log::warning(
                    sprintf(
                        'Dedup: node "%s" (label "%s") remapped to existing id "%s".',
                        $node->id,
                        $node->label,
                        $existing->id,
                    ),
                    ['new_id' => $node->id, 'existing_id' => $existing->id],
                );
            }
        }

        if (empty($remapping)) {
            return $payload;
        }

        $remap = fn (string $id): string => $remapping[$id] ?? $id;

        // Remove remapped nodes (the existing node stays; the duplicate is dropped).
        $nodes = array_values(
            array_filter($payload->nodes, fn ($n) => ! isset($remapping[$n->id])),
        );

        $edges = array_map(
            fn ($e) => new GraphEdgeDraft(
                source_id: $remap($e->source_id),
                target_id: $remap($e->target_id),
                type: $e->type,
                origin: $e->origin,
                strength: $e->strength,
                reason: $e->reason,
            ),
            $payload->edges,
        );

        $intents = array_map(
            fn ($i) => new IntentDeclaration(
                node_id: $remap($i->node_id),
                intent: $i->intent,
                replaces_id: $i->replaces_id !== null ? $remap($i->replaces_id) : null,
                reason: $i->reason,
            ),
            $payload->intents,
        );

        return new WritePayload(
            nodes: $nodes,
            edges: $edges,
            intents: $intents,
            reply: $payload->reply,
            mood: $payload->mood,
            open_questions: $payload->open_questions,
        );
    }

    /**
     * Validate a single IntentDeclaration against the live graph.
     *
     * Returns the (possibly overridden) declaration, or null if it should be
     * rejected and the corresponding node removed from the payload.
     *
     * @param  string[]  $warnings  Passed by reference — warnings are appended here.
     */
    private function validateDeclaration(IntentDeclaration $declaration, array &$warnings): ?IntentDeclaration
    {
        return match ($declaration->intent) {
            WriteIntent::Create => $this->validateCreate($declaration, $warnings),
            WriteIntent::Evolve => $this->validateEvolve($declaration, $warnings),
            WriteIntent::Contradict => $this->flagContradict($declaration, $warnings),
            default => $declaration,
        };
    }

    /**
     * Rule 1 — CREATE → REINFORCE override.
     *
     * If the AI declares Create but the node already exists in the graph,
     * override to Reinforce so we do not accidentally duplicate the node.
     */
    private function validateCreate(IntentDeclaration $declaration, array &$warnings): IntentDeclaration
    {
        if (! $this->graphService->nodeExists($declaration->node_id)) {
            return $declaration;
        }

        $message = sprintf(
            'Intent override: node "%s" declared as CREATE but already exists — overriding to REINFORCE.',
            $declaration->node_id,
        );

        $warnings[] = $message;
        Log::warning($message, ['node_id' => $declaration->node_id]);

        return new IntentDeclaration(
            node_id: $declaration->node_id,
            intent: WriteIntent::Reinforce,
            replaces_id: $declaration->replaces_id,
            reason: $declaration->reason,
        );
    }

    /**
     * Rule 2 — EVOLVE without replaces_id is invalid.
     *
     * Evolve semantically requires a predecessor node. Without replaces_id
     * we cannot record the lineage, so the declaration is rejected and the
     * node is removed from the payload to prevent a dangling write.
     */
    private function validateEvolve(IntentDeclaration $declaration, array &$warnings): ?IntentDeclaration
    {
        if ($declaration->replaces_id !== null) {
            return $declaration;
        }

        $message = sprintf(
            'Intent rejected: node "%s" declared as EVOLVE but replaces_id is null — removing from payload.',
            $declaration->node_id,
        );

        $warnings[] = $message;
        Log::warning($message, ['node_id' => $declaration->node_id]);

        return null;
    }

    /**
     * Rule 3 — CONTRADICT is never auto-written.
     *
     * The declaration is returned as-is so it can be placed in
     * $flaggedContradictions, but returning it here signals to the caller
     * that it should be excluded from the write payload.
     */
    private function flagContradict(IntentDeclaration $declaration, array &$warnings): IntentDeclaration
    {
        $message = sprintf(
            'Intent flagged: node "%s" declared as CONTRADICT — queued for manual review, not written.',
            $declaration->node_id,
        );

        $warnings[] = $message;
        Log::warning($message, [
            'node_id' => $declaration->node_id,
            'reason' => $declaration->reason,
        ]);

        return $declaration;
    }
}
