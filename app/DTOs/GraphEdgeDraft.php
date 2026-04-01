<?php

namespace App\DTOs;

use App\Enums\Origin;

/**
 * What the AI returns for an edge before Laravel validates and writes it.
 */
readonly class GraphEdgeDraft
{
    public function __construct(
        public string $source_id,
        public string $target_id,
        public string $type,
        public Origin $origin,
        public float $strength,
        public ?string $reason = null,
    ) {}
}
