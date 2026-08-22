<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GroupCycleSnapshot extends Model
{
    protected $table = 'ex_group_cycle_snapshots';

    protected $fillable = [
        'group_id',
        'cycle_start',
        'cycle_end',
        'totals',
        'expenses',
        'balances',
    ];

    protected $casts = [
        'cycle_start' => 'date',
        'cycle_end' => 'date',
        'totals' => 'array',
        'expenses' => 'array',
        'balances' => 'array',
    ];

    public function group()
    {
        return $this->belongsTo(Group::class, 'group_id');
    }
}
