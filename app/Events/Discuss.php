<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class Discuss implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;
    private $sender_id;
    private $pawnshop_id;
    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct($sender_id,$pawnshop_id)
    {
        $this->sender_id = $sender_id;
        $this->pawnshop_id = $pawnshop_id;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel|array
     */
    public function broadcastOn()
    {
        return ['discussion_channel_'.$this->pawnshop_id];
    }
    public function broadcastAs()
    {
        return 'new-discussion';
    }
    public function broadcastWith(){
        return ['sender_id' => $this->sender_id];
    }
}
