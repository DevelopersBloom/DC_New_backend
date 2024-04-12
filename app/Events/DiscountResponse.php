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

class DiscountResponse implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;
    private $pawnshop_id;
    private $sender_id;
    private $contract_id;
    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct($sender_id,$pawnshop_id,$contract_id)
    {
        $this->sender_id = $sender_id;
        $this->pawnshop_id = $pawnshop_id;
        $this->contract_id = $contract_id;
    }

    public function broadcastAs()
    {
        return 'new-discount-response';
    }
    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel|array
     */
    public function broadcastOn()
    {
        return ['discount_channel_'.$this->pawnshop_id];
    }

    public function broadcastWith(){
        return ['sender_id' => $this->sender_id, 'contract_id' => $this->contract_id];
    }
}
