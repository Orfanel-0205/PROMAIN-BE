<?php
// app/Models/QueuePriorityRule.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QueuePriorityRule extends Model
{
    protected $fillable = [
        'rule_key',
        'label',
        'score_weight',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}