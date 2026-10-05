<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One month of audience, kept for good: the distinct visitors of the site and the distinct copies
 * of the mod and the Manager in use that month.
 *
 * 🔴 **The numbers the days cannot add up to** (user, 2026-10-05). A person who comes on twenty days
 * is twenty daily visitors and one monthly visitor; only a fingerprint that lasts the month can tell
 * them apart, and it is erased once the month is counted (AggregateAnalytics::aggregateMonth). The
 * month in progress is recounted every night, so its row is never more than a day behind.
 */
class AnalyticsMonthly extends Model
{
    protected $table = 'analytics_monthly';

    protected $fillable = ['month', 'unique_visitors', 'mod_copies', 'manager_copies'];

    protected $casts = [
        'month' => 'date',
        'unique_visitors' => 'integer',
        'mod_copies' => 'integer',
        'manager_copies' => 'integer',
    ];
}
