<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Participation extends Model
{
    use HasFactory;

    protected $table = 'ex_participations';

    protected $fillable = [
        'state',
        'group_id',
        'quota_id',
    ];

    protected $casts = [
        'state' => 'string',
    ];

    public function group()
    {
        return $this->belongsTo(Group::class, 'group_id');
    }

    public function quota()
    {
        return $this->belongsTo(Quota::class, 'quota_id');
    }
}
