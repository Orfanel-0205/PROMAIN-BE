<?php
// app/Http/Resources/Queue/QueueLogResource.php

namespace App\Http\Resources\Queue;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QueueLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'action'       => $this->action,
            'from_status'  => $this->from_status,
            'to_status'    => $this->to_status,
            'performed_by' => $this->whenLoaded('performedBy', fn() => [
                'id'   => $this->performedBy->id,
                'name' => $this->performedBy->name,
            ]),
            'metadata'     => $this->metadata,
            'performed_at' => $this->performed_at?->toIso8601String(),
        ];
    }
}