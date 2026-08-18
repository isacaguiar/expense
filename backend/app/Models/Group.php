<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Group extends Model
{
    protected $table = 'ex_groups';

    protected $fillable = [
        'create_date',
        'deleted',
        'description',
        'name',
    ];

    protected $casts = [
        'create_date' => 'datetime',
        'deleted' => 'boolean',
    ];

    public $timestamps = true;

    public function expenses()
    {
        return $this->hasMany(Expense::class, 'group_id');
    }

    public function participations()
    {
        return $this->hasMany(Participation::class, 'group_id');
    }

    public function members()
    {
        return $this->belongsToMany(User::class, 'ex_groups_members', 'group_id', 'user_id');
    }

}
