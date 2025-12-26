<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnalyticsDaily extends Model
{
    protected $table = 'analytics_daily';

    protected $fillable = [
        'date',
        'page_views',
        'unique_visitors',
        'downloads',
        'uploads',
        'registrations',
        'countries',
        'referrers',
        'devices',
        'browsers',
    ];

    protected $casts = [
        'date' => 'date',
        'page_views' => 'integer',
        'unique_visitors' => 'integer',
        'downloads' => 'integer',
        'uploads' => 'integer',
        'registrations' => 'integer',
        'countries' => 'array',
        'referrers' => 'array',
        'devices' => 'array',
        'browsers' => 'array',
    ];
}
