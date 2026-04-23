<?php
// app/Http/Resources/Referral/ReferralUpdateResource.php

namespace App\Http\Resources\Referral;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReferralUpdateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'update_type' => $this->update_type,
            'from_status' => $this->from_status,
            'to_status'   => $this->to_status,
            'notes'       => $this->notes,
            'metadata'    => $this->metadata,
            'updated_by'  => $this->whenLoaded('updatedBy', fn() => [
                'id'   => $this->updatedBy->user_id,
                'name' => $this->updatedBy->first_name . ' ' . $this->updatedBy->last_name,
            ]),
            'created_at'  => $this->created_at?->toIso8601String(),
        ];
    }
}
