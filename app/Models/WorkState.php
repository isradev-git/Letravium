<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkState extends Model
{
    protected $fillable = ['novel_id', 'name', 'position'];
}
