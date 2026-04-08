<?php

declare(strict_types=1);

namespace App\Events\AutoDialer;

use App\Models\AutoDialerDestination;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DestinationAdded
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public AutoDialerDestination $destination,
        public int $userId,
    ) {}
}
