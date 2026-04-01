<?php

namespace App\DTOs;

use App\Enums\Origin;
use App\Enums\RelationType;

/**
 * What the AI returns for an edge before Laravel validates and writes it.
 */
readonly class GraphEdgeDraft
{
    public function __construct(
        public string $source_id,
        public string $target_id,
        public RelationType $type,
        public Origin $origin,
        public float $strength,
        public ?string $reason = null,
    ) {}
}
