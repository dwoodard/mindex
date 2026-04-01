<?php

namespace App\Services;

use App\DTOs\GraphEdge;
use App\DTOs\GraphEdgeDraft;
use App\DTOs\GraphNode;
use App\DTOs\GraphNodeDraft;
use App\Enums\NodeType;
use App\Enums\Origin;
use App\Services\Contracts\GraphServiceInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

class GraphServiceStub implements GraphServiceInterface
{
    private const STORE_PATH = 'graph-stub.json';

    public function findById(string $id): ?GraphNode
    {
        $store = $this->readStore();

        if (! isset($store['nodes'][$id])) {
            return null;
        }

        return $this->hydrateNode($store['nodes'][$id]);
    }

    public function findByLabel(string $label): ?GraphNode
    {
        $store = $this->readStore();
        $normalised = mb_strtolower(trim($label));

        foreach ($store['nodes'] as $data) {
            if (mb_strtolower(trim($data['label'] ?? '')) === $normalised) {
                return $this->hydrateNode($data);
            }
        }

        return null;
    }

    /** @return GraphNode[] */
    public function findByIds(array $ids): array
    {
        $store = $this->readStore();

        return array_values(
            array_filter(
                array_map(
                    fn (string $id) => isset($store['nodes'][$id])
                        ? $this->hydrateNode($store['nodes'][$id])
                        : null,
                    $ids,
                ),
                fn ($node) => $node !== null,
            ),
        );
    }

    /** @return GraphNode[] */
    public function search(string $query, int $limit = 10): array
    {
        $store = $this->readStore();
        $lower = mb_strtolower($query);
        $results = [];

        foreach ($store['nodes'] as $raw) {
            if (count($results) >= $limit) {
                break;
            }

            $labelMatch = str_contains($lower, mb_strtolower($raw['label']));

            $propertiesMatch = false;
            foreach ($raw['properties'] ?? [] as $value) {
                if (str_contains(mb_strtolower((string) $value), $lower)) {
                    $propertiesMatch = true;
                    break;
                }
            }

            if ($labelMatch || $propertiesMatch) {
                $results[] = $this->hydrateNode($raw);
            }
        }

        return $results;
    }

    public function nodeExists(string $id): bool
    {
        return isset($this->readStore()['nodes'][$id]);
    }

    public function mergeNode(GraphNodeDraft $draft): GraphNode
    {
        $store = $this->readStore();
        $now = Carbon::now()->toIso8601String();

        $existing = $store['nodes'][$draft->id] ?? null;

        $raw = [
            'id' => $draft->id,
            'label' => $draft->label,
            'type' => $draft->type->value,
            'origin' => $draft->origin->value,
            'confidence' => $draft->confidence,
            'decay_rate' => $draft->decay_rate,
            'anchored' => $draft->anchored,
            'properties' => $draft->properties,
            'mention_count' => $existing['mention_count'] ?? 0,
            'created_at' => $existing['created_at'] ?? $now,
            'updated_at' => $now,
            'last_reinforced_at' => $existing['last_reinforced_at'] ?? null,
        ];

        $store['nodes'][$draft->id] = $raw;
        $this->writeStore($store);

        return $this->hydrateNode($raw);
    }

    public function mergeEdge(GraphEdgeDraft $draft, string $sessionId): GraphEdge
    {
        $store = $this->readStore();
        $now = Carbon::now()->toIso8601String();

        $relType = preg_replace('/[^A-Z_]/', '_', strtoupper(trim($draft->type)));
        $edgeId = "{$draft->source_id}__{$relType}__{$draft->target_id}";

        $existing = $store['edges'][$edgeId] ?? null;

        $raw = [
            'id' => $edgeId,
            'source_id' => $draft->source_id,
            'target_id' => $draft->target_id,
            'type' => $relType,
            'origin' => $draft->origin->value,
            'strength' => $draft->strength,
            'reason' => $draft->reason,
            'session_id' => $sessionId,
            'created_at' => $existing['created_at'] ?? $now,
            'valid_until' => $existing['valid_until'] ?? null,
        ];

        $store['edges'][$edgeId] = $raw;
        $this->writeStore($store);

        return $this->hydrateEdge($raw);
    }

    public function incrementMentionCount(string $id): void
    {
        $store = $this->readStore();

        if (! isset($store['nodes'][$id])) {
            return;
        }

        $store['nodes'][$id]['mention_count'] = ($store['nodes'][$id]['mention_count'] ?? 0) + 1;
        $store['nodes'][$id]['updated_at'] = Carbon::now()->toIso8601String();

        $this->writeStore($store);
    }

    public function setValidUntil(string $edgeId, Carbon $until): void
    {
        $store = $this->readStore();

        if (! isset($store['edges'][$edgeId])) {
            return;
        }

        $store['edges'][$edgeId]['valid_until'] = $until->toIso8601String();

        $this->writeStore($store);
    }

    /**
     * @return array{nodes: GraphNode[], edges: GraphEdge[]}
     */
    public function getRelated(string $id, int $depth = 1): array
    {
        $store = $this->readStore();

        $connectedEdges = array_filter(
            $store['edges'],
            fn (array $edge) => $edge['source_id'] === $id || $edge['target_id'] === $id,
        );

        $relatedNodeIds = [];
        foreach ($connectedEdges as $edge) {
            $relatedNodeIds[] = $edge['source_id'] === $id
                ? $edge['target_id']
                : $edge['source_id'];
        }

        $nodes = array_values(array_filter(
            array_map(
                fn (string $nodeId) => isset($store['nodes'][$nodeId])
                    ? $this->hydrateNode($store['nodes'][$nodeId])
                    : null,
                array_unique($relatedNodeIds),
            ),
            fn ($node) => $node !== null,
        ));

        $edges = array_values(array_map(
            fn (array $raw) => $this->hydrateEdge($raw),
            $connectedEdges,
        ));

        return compact('nodes', 'edges');
    }

    public function decayConfidence(float $rate, Carbon $notReinforcedSince): int
    {
        $store = $this->readStore();
        $updated = 0;

        foreach ($store['nodes'] as &$raw) {
            if ($raw['anchored']) {
                continue;
            }

            $lastReinforced = isset($raw['last_reinforced_at'])
                ? Carbon::parse($raw['last_reinforced_at'])
                : null;

            $shouldDecay = $lastReinforced === null || $lastReinforced->isBefore($notReinforcedSince);

            if ($shouldDecay) {
                $raw['confidence'] = max(0.05, $raw['confidence'] - $rate);
                $raw['faded'] = $raw['confidence'] < 0.2;
                $raw['updated_at'] = Carbon::now()->toIso8601String();
                $updated++;
            }
        }

        unset($raw);

        $this->writeStore($store);

        return $updated;
    }

    /** @return array{nodes: array<string, mixed>, edges: array<string, mixed>} */
    private function readStore(): array
    {
        if (! Storage::exists(self::STORE_PATH)) {
            return ['nodes' => [], 'edges' => []];
        }

        $decoded = json_decode(Storage::get(self::STORE_PATH), associative: true);

        return [
            'nodes' => $decoded['nodes'] ?? [],
            'edges' => $decoded['edges'] ?? [],
        ];
    }

    /** @param array{nodes: array<string, mixed>, edges: array<string, mixed>} $store */
    private function writeStore(array $store): void
    {
        Storage::put(self::STORE_PATH, json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $raw */
    private function hydrateNode(array $raw): GraphNode
    {
        return new GraphNode(
            id: $raw['id'],
            label: $raw['label'],
            type: NodeType::from($raw['type']),
            origin: Origin::from($raw['origin']),
            confidence: (float) $raw['confidence'],
            created_at: Carbon::parse($raw['created_at']),
            updated_at: Carbon::parse($raw['updated_at']),
            mention_count: (int) ($raw['mention_count'] ?? 0),
            properties: $raw['properties'] ?? [],
            decay_rate: (float) ($raw['decay_rate'] ?? 0.02),
            anchored: (bool) ($raw['anchored'] ?? false),
            last_reinforced_at: isset($raw['last_reinforced_at'])
                ? Carbon::parse($raw['last_reinforced_at'])
                : null,
            faded: (bool) ($raw['faded'] ?? false),
        );
    }

    /** @param array<string, mixed> $raw */
    private function hydrateEdge(array $raw): GraphEdge
    {
        return new GraphEdge(
            id: $raw['id'],
            source_id: $raw['source_id'],
            target_id: $raw['target_id'],
            type: $raw['type'],
            origin: Origin::from($raw['origin']),
            strength: (float) $raw['strength'],
            created_at: Carbon::parse($raw['created_at']),
            reason: $raw['reason'] ?? null,
            session_id: $raw['session_id'] ?? null,
            valid_until: isset($raw['valid_until'])
                ? Carbon::parse($raw['valid_until'])
                : null,
        );
    }
}
