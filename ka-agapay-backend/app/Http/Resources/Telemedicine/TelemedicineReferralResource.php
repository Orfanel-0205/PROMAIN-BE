<?php
// app/Http/Resources/Telemedicine/TelemedicineReferralResource.php

namespace App\Http\Resources\Telemedicine;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TelemedicineReferralResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'referral_type'  => $this->referral_type,
            'referred_to'    => $this->referred_to,
            'reason'         => $this->reason,
            'instructions'   => $this->instructions,
            'follow_up_date' => $this->follow_up_date?->toDateString(),
            'is_urgent'      => $this->is_urgent,
            'status'         => $this->status,
            'issued_by' => $this->whenLoaded('issuedBy', fn() => [
                'id'   => $this->issuedBy->user_id,
                'name' => $this->issuedBy->first_name . ' ' . $this->issuedBy->last_name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}