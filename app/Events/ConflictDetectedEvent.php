<?php

namespace App\Events;

use App\DTOs\GraphNode;
use App\DTOs\GraphNodeDraft;
use Carbon\Carbon;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConflictDetectedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Carbon $timestamp;

    public function __construct(
        public readonly int $userId,
        public readonly GraphNode $existingNode,
        public readonly GraphNodeDraft $newNode,
    ) {
        $this->timestamp = Carbon::now();
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'existing_node' => [
                'id' => $this->existingNode->id,
                'label' => $this->existingNode->label,
                'type' => $this->existingNode->type->value,
                'origin' => $this->existingNode->origin->value,
                'confidence' => $this->existingNode->confidence,
                'created_at' => $this->existingNode->created_at->toIso8601String(),
                'updated_at' => $this->existingNode->updated_at->toIso8601String(),
                'mention_count' => $this->existingNode->mention_count,
                'properties' => $this->existingNode->properties,
                'decay_rate' => $this->existingNode->decay_rate,
                'anchored' => $this->existingNode->anchored,
                'last_reinforced_at' => $this->existingNode->last_reinforced_at?->toIso8601String(),
            ],
            'new_node' => [
                'id' => $this->newNode->id,
                'label' => $this->newNode->label,
                'type' => $this->newNode->type->value,
                'origin' => $this->newNode->origin->value,
                'confidence' => $this->newNode->confidence,
                'decay_rate' => $this->newNode->decay_rate,
                'anchored' => $this->newNode->anchored,
                'properties' => $this->newNode->properties,
            ],
            'timestamp' => $this->timestamp->toIso8601String(),
        ];
    }

    /**
     * @return Channel[]
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('graph.'.$this->userId),
        ];
    }
}
