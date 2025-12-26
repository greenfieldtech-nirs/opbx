<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\CallLog;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CallInitiated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public CallLog $callLog
    ) {
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('presence.org.' . $this->callLog->organization_id),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'call.initiated';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'call_id' => $this->callLog->call_id,
            'from_number' => $this->callLog->from_number,
            'to_number' => $this->callLog->to_number,
            'did_id' => $this->callLog->did_id,
            'status' => $this->callLog->status->value,
            'initiated_at' => $this->callLog->initiated_at?->toIso8601String(),
        ];
    }
}
