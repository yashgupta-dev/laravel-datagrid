<?php

namespace CodeCorner\Datagrid\Models;

use Illuminate\Database\Eloquent\Model;

class SavedFilter extends Model
{
    protected $table = 'datagrid_saved_filters';

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
    ];
}
