<?php

namespace App\Http\Resources\Queue;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QueueTicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ticket_number' => $this->ticket_number,
            'status' => $this->status,
            'service_type' => $this->service_type,
            'queue_position' => $this->queue_position,
            'call_attempt' => $this->call_attempt,
            'priority' => [
                'score' => $this->priority_score,
                'category' => $this->priority_category,
                'flags' => [
                    'is_emergency' => $this->is_emergency,
                    'is_pregnant' => $this->is_pregnant,
                    'is_senior' => $this->is_senior,
                    'is_pwd' => $this->is_pwd,
                    'is_pediatric' => $this->is_pediatric,
                    'is_bhw_endorsed' => $this->is_bhw_endorsed,
                ],
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}