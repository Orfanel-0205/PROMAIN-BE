<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Announcement extends Model
{
    protected $fillable = [
        'created_by',
        'title',
        'body',
        'status',
        'published_at'
    ];
    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }
}
