<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrialActivationLog extends Model
{
    protected $fillable = [
        'store_id',
        'admin_id',
        'action',
        'old_plan_slug',
        'new_plan_slug',
        'trial_start',
        'trial_end',
        'notes',
    ];

    protected $casts = [
        'trial_start' => 'datetime',
        'trial_end' => 'datetime',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
