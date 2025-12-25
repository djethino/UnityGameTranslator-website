<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vote extends Model
{
    protected $fillable = [
        'translation_id',
        'user_id',
        'value',
    ];

    public function translation()
    {
        return $this->belongsTo(Translation::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
