<?php

namespace App\Services;

use App\DTOs\GraphEdgeDraft;
use App\DTOs\GraphNodeDraft;
use App\DTOs\IntentDeclaration;
use App\DTOs\WritePayload;
use App\Enums\NodeType;
use App\Enums\Origin;
use App\Enums\WriteIntent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\StructuredAgentResponse;

class ExtractionService
{
    /**
     * Extract structured graph data from raw user input and related graph context.
     *
     * @param  array<int, mixed>  $relatedNodes
     * @param  array{name: string, graph_id: string|null}  $speakerContext
     */
    public function extract(string $input, array $relatedNodes, bool $listenMode = true, array $speakerContext = []): WritePayload
    {
        $retrievedContext = empty($relatedNodes)
            ? 'No existing nodes retrieved for this input.'
            : implode("\n", array_map(
                fn ($node) => "- id: {$node->id} | label: {$node->label} | type: {$node->type->value}",
                $relatedNodes,
            ));

        $agent = new ExtractionAgent($retrievedContext, $listenMode, $speakerContext);

        /** @var StructuredAgentResponse $response */
        $response = $agent->prompt($input);

        return $this->hydrate($response->toArray());
    }

    /**
     * Hydrate the raw AI JSON response into a typed WritePayload DTO.
     *
     * @param  array<string, mixed>  $data
     */
    private function hydrate(array $data): WritePayload
    {
        return new WritePayload(
            nodes: array_map($this->hydrateNode(...), $data['nodes'] ?? []),
            edges: array_map($this->hydrateEdge(...), $data['edges'] ?? []),
            intents: array_map($this->hydrateIntent(...), $data['intents'] ?? []),
            reply: $data['reply'] ?? '',
            mood: $data['mood'] ?? null,
            open_questions: $data['open_questions'] ?? null,
        );
    }

    /**
     * Hydrate a single node draft from raw array data.
     *
     * @param  array<string, mixed>  $data
     */
    private function hydrateNode(array $data): GraphNodeDraft
    {
        return new GraphNodeDraft(
            id: $data['id'],
            label: $data['label'],
            type: NodeType::from($data['type']),
            origin: Origin::from($data['origin']),
            confidence: (float) $data['confidence'],
            decay_rate: isset($data['decay_rate']) ? (float) $data['decay_rate'] : 0.02,
            anchored: (bool) ($data['anchored'] ?? false),
            properties: $data['properties'] ?? [],
        );
    }

    /**
     * Hydrate a single edge draft from raw array data.
     *
     * @param  array<string, mixed>  $data
     */
    private function hydrateEdge(array $data): GraphEdgeDraft
    {
        return new GraphEdgeDraft(
            source_id: $data['source_id'],
            target_id: $data['target_id'],
            type: $data['type'],
            origin: Origin::from($data['origin']),
            strength: (float) $data['strength'],
            reason: $data['reason'] ?? null,
        );
    }

    /**
     * Hydrate a single intent declaration from raw array data.
     *
     * @param  array<string, mixed>  $data
     */
    private function hydrateIntent(array $data): IntentDeclaration
    {
        return new IntentDeclaration(
            node_id: $data['node_id'],
            intent: WriteIntent::from($data['intent']),
            replaces_id: $data['replaces_id'] ?? null,
            reason: $data['reason'] ?? null,
        );
    }
}

/**
 * Anonymous structured agent that extracts graph data from user input.
 *
 * Kept package-private (file-scoped) to the ExtractionService — callers
 * should go through ExtractionService::extract(), not this class directly.
 */
#[Provider('lmstudio')]
#[Model('openai/gpt-oss-20b')]
class ExtractionAgent implements Agent, Conversational, HasStructuredOutput
{
    use Promptable;

    /**
     * @param  array{name: string, graph_id: string|null}  $speakerContext
     */
    public function __construct(
        private readonly string $retrievedNodesJson,
        private readonly bool $listenMode = true,
        private readonly array $speakerContext = [],
    ) {}

    public function instructions(): string
    {
        $listenNote = $this->listenMode
            ? 'This is listen mode. Leave the reply field as an empty string.'
            : 'You may include a brief conversational reply in the reply field.';

        $speakerBlock = '';
        if (! empty($this->speakerContext['name'])) {
            $name = $this->speakerContext['name'];
            $graphId = $this->speakerContext['graph_id'] ?? null;
            $idLine = $graphId
                ? "Graph node id: {$graphId} — use this exact id whenever referencing the speaker as a node."
                : 'No graph node found yet — create one with a stable snake_case id derived from their name.';

            $speakerBlock = <<<SPEAKER

THE SPEAKER:
Name: {$name}
{$idLine}
When the user says "I", "me", "my", "I am", "I believe", "I prefer", "I like", "I dislike" — they are referring to this person.
Always attribute edges that originate from the speaker (ORIGINATED, PREFERS, HAS_AVERSION_TO, etc.) to their node.
Set origin to "user" for everything the speaker directly states.

SPEAKER;
        }

        return <<<SYSTEM
You are a personal intelligence extraction engine embedded in a knowledge graph system.
{$speakerBlock}

Your job is to read what the user said and extract structured graph data from it.

You have been given existing graph context (nodes already in the graph that may relate
to this input). Use this context to:
- Avoid creating duplicate nodes
- Detect when beliefs or ideas have evolved or changed
- Detect contradictions with existing nodes
- Reinforce existing nodes when the user returns to a topic

RULES:
1. Extract 2–5 nodes per turn. Do not over-extract. Quality over quantity.
2. ALWAYS check the EXISTING GRAPH CONTEXT first. If an entity in the input is clearly the same person, place, idea, or concept as an existing node — even under a different name or phrasing (e.g. "Dustin" and "Dustin Woodard") — you MUST use that node's exact existing `id`. Never generate a new id for an entity that already exists in the context.
3. Node types must come from: Person, Idea, Project, Belief, Question, Preference, Dislike, Event, Place, Resource
4. Prefer these relationship types: ORIGINATED, SUGGESTED, REJECTED, EVOLVED_INTO, CONTRADICTED_BY, REINFORCES, RELATES_TO, BLOCKS, ENABLES, HAS_QUESTION, PREFERS, HAS_AVERSION_TO, WORKS_WITH, BUILT_ON, MENTIONS. If none fit, invent a new type in SCREAMING_SNAKE_CASE (e.g. FUNDED_BY, INSPIRED_BY).
5. Declare your WriteIntent for each node: CREATE, REINFORCE, UPDATE, EVOLVE, CONTRADICT, or RESOLVE
6. Origin must be accurate: 'user' if they said it, 'inferred' if you derived it.
7. {$listenNote}
8. If you detect a contradiction, set intent to CONTRADICT and explain in the reason field.
9. Confidence: 0.3 for passing mention, 0.8+ for strong clear statement.
10. Properties are freeform but keep them brief — 1 to 3 key facts only.

OUTPUT FORMAT:
You MUST respond with a single JSON object matching this exact shape. No other text.
{
  "nodes": [
    {"id": "snake_case_id", "label": "Human readable label", "type": "Idea", "origin": "user", "confidence": 0.8, "decay_rate": 0.02, "anchored": false, "properties": {}}
  ],
  "edges": [
    {"source_id": "node_a", "target_id": "node_b", "type": "RELATES_TO", "origin": "inferred", "strength": 0.7, "reason": "optional explanation"}
  ],
  "intents": [
    {"node_id": "snake_case_id", "intent": "CREATE", "replaces_id": null, "reason": null}
  ],
  "reply": "",
  "mood": null,
  "open_questions": null
}

EXISTING GRAPH CONTEXT (reuse these exact ids if the entity matches):
{$this->retrievedNodesJson}
SYSTEM;
    }

    /** @return array<int, never> */
    public function messages(): iterable
    {
        return [];
    }

    /** @return array<int, never> */
    public function tools(): iterable
    {
        return [];
    }

    /**
     * Define the JSON schema for structured output.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        $nodeTypeValues = array_column(NodeType::cases(), 'value');
        $writeIntentValues = array_column(WriteIntent::cases(), 'value');
        $originValues = array_column(Origin::cases(), 'value');

        $nodeSchema = $schema->object([
            'id' => $schema->string()->required(),
            'label' => $schema->string()->required(),
            'type' => $schema->string()->enum($nodeTypeValues)->required(),
            'origin' => $schema->string()->enum($originValues)->required(),
            'confidence' => $schema->number()->required(),
            'decay_rate' => $schema->number()->nullable(),
            'anchored' => $schema->boolean()->nullable(),
            'properties' => $schema->object()->nullable(),
        ]);

        $edgeSchema = $schema->object([
            'source_id' => $schema->string()->required(),
            'target_id' => $schema->string()->required(),
            'type' => $schema->string()->required(),
            'origin' => $schema->string()->enum($originValues)->required(),
            'strength' => $schema->number()->required(),
            'reason' => $schema->string()->nullable(),
        ]);

        $intentSchema = $schema->object([
            'node_id' => $schema->string()->required(),
            'intent' => $schema->string()->enum($writeIntentValues)->required(),
            'replaces_id' => $schema->string()->nullable(),
            'reason' => $schema->string()->nullable(),
        ]);

        return [
            'nodes' => $schema->array()->items($nodeSchema)->required(),
            'edges' => $schema->array()->items($edgeSchema)->required(),
            'intents' => $schema->array()->items($intentSchema)->required(),
            'reply' => $schema->string()->required(),
            'mood' => $schema->string()->nullable(),
            'open_questions' => $schema->array()->items($schema->string())->nullable(),
        ];
    }
}
