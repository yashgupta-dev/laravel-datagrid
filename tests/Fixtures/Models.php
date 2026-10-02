<?php

namespace CodeCorner\Datagrid\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $guarded = [];

    public $timestamps = false;
}

class Post extends Model
{
    protected $guarded = [];

    public $timestamps = false;
}

class User extends Model
{
    protected $guarded = [];

    protected $casts = [
        'tags' => 'array',
    ];

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function posts()
    {
        return $this->hasMany(Post::class);
    }
}
