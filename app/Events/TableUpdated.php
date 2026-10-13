<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TableUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public int $campaignId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('campaign.'.$this->campaignId)];
    }

    public function broadcastAs(): string
    {
        return 'table.updated';
    }
}
