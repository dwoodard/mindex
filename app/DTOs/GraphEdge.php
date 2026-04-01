<?php

namespace App\DTOs;

use App\Enums\Origin;
use App\Enums\RelationType;
use Carbon\Carbon;

readonly class GraphEdge
{
    public function __construct(
        public string $id,
        public string $source_id,
        public string $target_id,
        public RelationType $type,
        public Origin $origin,
        public float $strength,
        public Carbon $created_at,
        public ?string $reason = null,
        public ?string $session_id = null,
        public ?Carbon $valid_until = null,
    ) {}
}
