<?php

namespace App\Events;

use Carbon\Carbon;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GraphUpdatedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Carbon $timestamp;

    public function __construct(
        public readonly int $userId,
        public readonly string $sessionId,
        public readonly int $nodesAdded,
        public readonly int $edgesAdded,
    ) {
        $this->timestamp = Carbon::now();
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'session_id' => $this->sessionId,
            'nodes_added' => $this->nodesAdded,
            'edges_added' => $this->edgesAdded,
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
