<?php

namespace App\Services\Contracts;

use App\DTOs\GraphEdge;
use App\DTOs\GraphEdgeDraft;
use App\DTOs\GraphNode;
use App\DTOs\GraphNodeDraft;
use Carbon\Carbon;

interface GraphServiceInterface
{
    public function findById(string $id): ?GraphNode;

    /** @return GraphNode[] */
    public function findByIds(array $ids): array;

    /** @return GraphNode[] */
    public function search(string $query, int $limit = 10): array;

    public function nodeExists(string $id): bool;

    public function mergeNode(GraphNodeDraft $draft): GraphNode;

    public function mergeEdge(GraphEdgeDraft $draft, string $sessionId): GraphEdge;

    public function incrementMentionCount(string $id): void;

    public function setValidUntil(string $edgeId, Carbon $until): void;

    /**
     * @return array{nodes: GraphNode[], edges: GraphEdge[]}
     */
    public function getRelated(string $id, int $depth = 1): array;

    /**
     * Reduce confidence by $rate for nodes not reinforced since $notReinforcedSince.
     * Returns the number of nodes updated.
     */
    public function decayConfidence(float $rate, Carbon $notReinforcedSince): int;
}
