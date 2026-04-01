<?php

namespace App\Services;

use App\DTOs\GraphEdge;
use App\DTOs\GraphEdgeDraft;
use App\DTOs\GraphNode;
use App\DTOs\GraphNodeDraft;
use App\Enums\NodeType;
use App\Enums\Origin;
use App\Enums\RelationType;
use App\Services\Contracts\GraphServiceInterface;
use Carbon\Carbon;
use Laudis\Neo4j\Contracts\ClientInterface;
use Laudis\Neo4j\Types\Node;
use Laudis\Neo4j\Types\Relationship;

class GraphService implements GraphServiceInterface
{
    public function __construct(
        private readonly ClientInterface $client,
    ) {}

    public function findById(string $id): ?GraphNode
    {
        $result = $this->client->run(
            'MATCH (n {id: $id}) RETURN n LIMIT 1',
            ['id' => $id],
        );

        if ($result->isEmpty()) {
            return null;
        }

        /** @var Node $node */
        $node = $result->first()->get('n');

        return $this->hydrateNode($node);
    }

    /**
     * @param  string[]  $ids
     * @return GraphNode[]
     */
    public function findByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $result = $this->client->run(
            'MATCH (n) WHERE n.id IN $ids RETURN n',
            ['ids' => $ids],
        );

        $nodes = [];
        foreach ($result as $row) {
            /** @var Node $node */
            $node = $row->get('n');
            $nodes[] = $this->hydrateNode($node);
        }

        return $nodes;
    }

    /**
     * @return GraphNode[]
     */
    public function search(string $query, int $limit = 10): array
    {
        $result = $this->client->run(
            'MATCH (n) WHERE toLower($query) CONTAINS toLower(n.label) RETURN n LIMIT $limit',
            ['query' => $query, 'limit' => $limit],
        );

        $nodes = [];
        foreach ($result as $row) {
            /** @var Node $node */
            $node = $row->get('n');
            $nodes[] = $this->hydrateNode($node);
        }

        return $nodes;
    }

    public function nodeExists(string $id): bool
    {
        $result = $this->client->run(
            'MATCH (n {id: $id}) RETURN count(n) AS total',
            ['id' => $id],
        );

        return (int) $result->first()->get('total') > 0;
    }

    public function mergeNode(GraphNodeDraft $draft): GraphNode
    {
        $now = Carbon::now()->toIso8601String();
        $label = $draft->type->value;

        $props = [
            'id' => $draft->id,
            'label' => $draft->label,
            'type' => $draft->type->value,
            'origin' => $draft->origin->value,
            'confidence' => $draft->confidence,
            'decay_rate' => $draft->decay_rate,
            'anchored' => $draft->anchored,
            'properties' => json_encode($draft->properties),
        ];

        $cypher = <<<'CYPHER'
        MERGE (n {id: $id})
        ON CREATE SET n += $props, n.created_at = $now, n.updated_at = $now, n.mention_count = 0
        ON MATCH SET n += $props, n.updated_at = $now
        WITH n
        CALL apoc.create.addLabels(n, [$label]) YIELD node
        RETURN node AS n
        CYPHER;

        // Try with APOC first; fall back to a no-dynamic-label variant
        try {
            $result = $this->client->run($cypher, [
                'id' => $draft->id,
                'props' => $props,
                'now' => $now,
                'label' => $label,
            ]);

            /** @var Node $node */
            $node = $result->first()->get('n');
        } catch (\Throwable) {
            // APOC not available — use plain MERGE without dynamic label
            $fallback = <<<'CYPHER'
            MERGE (n {id: $id})
            ON CREATE SET n += $props, n.created_at = $now, n.updated_at = $now, n.mention_count = 0
            ON MATCH SET n += $props, n.updated_at = $now
            RETURN n
            CYPHER;

            $result = $this->client->run($fallback, [
                'id' => $draft->id,
                'props' => $props,
                'now' => $now,
            ]);

            /** @var Node $node */
            $node = $result->first()->get('n');
        }

        return $this->hydrateNode($node);
    }

    public function mergeEdge(GraphEdgeDraft $draft, string $sessionId): GraphEdge
    {
        $now = Carbon::now()->toIso8601String();
        $edgeId = "{$draft->source_id}__{$draft->type->value}__{$draft->target_id}";

        $props = [
            'id' => $edgeId,
            'source_id' => $draft->source_id,
            'target_id' => $draft->target_id,
            'type' => $draft->type->value,
            'origin' => $draft->origin->value,
            'strength' => $draft->strength,
            'reason' => $draft->reason,
        ];

        // Relationship type must be embedded — Cypher does not support dynamic types as parameters.
        // Safe because $draft->type is a validated enum value.
        $relType = $draft->type->value;
        $cypher = <<<CYPHER
        MATCH (a {id: \$source_id}), (b {id: \$target_id})
        MERGE (a)-[r:{$relType} {id: \$edge_id}]->(b)
        ON CREATE SET r += \$props, r.created_at = \$now, r.session_id = \$session_id
        ON MATCH SET r.strength = \$strength, r.session_id = \$session_id, r.updated_at = \$now
        RETURN r
        CYPHER;

        $result = $this->client->run($cypher, [
            'source_id' => $draft->source_id,
            'target_id' => $draft->target_id,
            'edge_id' => $edgeId,
            'props' => $props,
            'now' => $now,
            'session_id' => $sessionId,
            'strength' => $draft->strength,
        ]);

        /** @var Relationship $rel */
        $rel = $result->first()->get('r');

        return $this->hydrateEdge($rel);
    }

    public function incrementMentionCount(string $id): void
    {
        $this->client->run(
            <<<'CYPHER'
            MATCH (n {id: $id})
            SET n.mention_count = coalesce(n.mention_count, 0) + 1,
                n.updated_at = $now
            CYPHER,
            ['id' => $id, 'now' => Carbon::now()->toIso8601String()],
        );
    }

    public function setValidUntil(string $edgeId, Carbon $until): void
    {
        $this->client->run(
            <<<'CYPHER'
            MATCH ()-[r {id: $edge_id}]->()
            SET r.valid_until = $valid_until
            CYPHER,
            ['edge_id' => $edgeId, 'valid_until' => $until->toIso8601String()],
        );
    }

    /**
     * @return array{nodes: GraphNode[], edges: GraphEdge[]}
     */
    public function getRelated(string $id, int $depth = 1): array
    {
        $cypher = <<<'CYPHER'
        MATCH (root {id: $id})
        MATCH (root)-[r]-(related)
        RETURN related AS n, r
        CYPHER;

        // For depth > 1, use variable-length path
        if ($depth > 1) {
            $cypher = <<<'CYPHER'
            MATCH (root {id: $id})
            MATCH p = (root)-[r*1..$depth]-(related)
            UNWIND relationships(p) AS rel
            WITH collect(DISTINCT related) AS relNodes, collect(DISTINCT rel) AS relEdges
            UNWIND relNodes AS n
            UNWIND relEdges AS r
            RETURN n, r
            CYPHER;
        }

        $result = $this->client->run($cypher, ['id' => $id, 'depth' => $depth]);

        $nodes = [];
        $edges = [];
        $seenNodes = [];
        $seenEdges = [];

        foreach ($result as $row) {
            /** @var Node $node */
            $node = $row->get('n');
            $nodeId = (string) ($node->getProperties()->get('id') ?? $node->getId());

            if (! isset($seenNodes[$nodeId])) {
                $seenNodes[$nodeId] = true;
                $nodes[] = $this->hydrateNode($node);
            }

            /** @var Relationship $rel */
            $rel = $row->get('r');
            $relId = (string) ($rel->getProperties()->get('id') ?? $rel->getId());

            if (! isset($seenEdges[$relId])) {
                $seenEdges[$relId] = true;
                $edges[] = $this->hydrateEdge($rel);
            }
        }

        return compact('nodes', 'edges');
    }

    public function decayConfidence(float $rate, Carbon $notReinforcedSince): int
    {
        $result = $this->client->run(
            <<<'CYPHER'
            MATCH (n)
            WHERE n.anchored = false
              AND (n.last_reinforced_at IS NULL OR n.last_reinforced_at < $threshold)
            WITH n, CASE
                    WHEN n.confidence - $rate < 0.05 THEN 0.05
                    ELSE n.confidence - $rate
                END AS newConf
            SET n.confidence = newConf,
                n.faded = newConf < 0.2,
                n.updated_at = $now
            RETURN count(n) AS updated
            CYPHER,
            [
                'threshold' => $notReinforcedSince->toIso8601String(),
                'rate' => $rate,
                'now' => Carbon::now()->toIso8601String(),
            ],
        );

        return (int) $result->first()->get('updated');
    }

    private function hydrateNode(Node $node): GraphNode
    {
        $props = $node->getProperties();

        $rawProperties = $props->get('properties');
        $properties = is_string($rawProperties)
            ? (json_decode($rawProperties, associative: true) ?? [])
            : [];

        $lastReinforcedAt = $props->hasKey('last_reinforced_at') && $props->get('last_reinforced_at') !== null
            ? Carbon::parse((string) $props->get('last_reinforced_at'))
            : null;

        return new GraphNode(
            id: (string) $props->get('id'),
            label: (string) $props->get('label'),
            type: NodeType::from((string) $props->get('type')),
            origin: Origin::from((string) $props->get('origin')),
            confidence: (float) $props->get('confidence'),
            created_at: $props->hasKey('created_at') && $props->get('created_at') !== null
                ? Carbon::parse((string) $props->get('created_at'))
                : Carbon::now(),
            updated_at: $props->hasKey('updated_at') && $props->get('updated_at') !== null
                ? Carbon::parse((string) $props->get('updated_at'))
                : Carbon::now(),
            mention_count: (int) ($props->hasKey('mention_count') ? $props->get('mention_count') : 0),
            properties: $properties,
            decay_rate: (float) ($props->hasKey('decay_rate') ? $props->get('decay_rate') : 0.02),
            anchored: (bool) ($props->hasKey('anchored') ? $props->get('anchored') : false),
            last_reinforced_at: $lastReinforcedAt,
            faded: (bool) ($props->hasKey('faded') ? $props->get('faded') : false),
        );
    }

    private function hydrateEdge(Relationship $rel): GraphEdge
    {
        $props = $rel->getProperties();

        $validUntil = $props->hasKey('valid_until') && $props->get('valid_until') !== null
            ? Carbon::parse((string) $props->get('valid_until'))
            : null;

        $edgeId = $props->hasKey('id')
            ? (string) $props->get('id')
            : "{$props->get('source_id')}__{$props->get('type')}__{$props->get('target_id')}";

        return new GraphEdge(
            id: $edgeId,
            source_id: (string) $props->get('source_id'),
            target_id: (string) $props->get('target_id'),
            type: RelationType::from((string) $props->get('type')),
            origin: Origin::from((string) $props->get('origin')),
            strength: (float) $props->get('strength'),
            created_at: Carbon::parse((string) $props->get('created_at')),
            reason: $props->hasKey('reason') ? ($props->get('reason') !== null ? (string) $props->get('reason') : null) : null,
            session_id: $props->hasKey('session_id') ? ($props->get('session_id') !== null ? (string) $props->get('session_id') : null) : null,
            valid_until: $validUntil,
        );
    }
}
