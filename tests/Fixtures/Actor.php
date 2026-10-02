<?php

namespace CodeCorner\Datagrid\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

class Actor extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];
}
