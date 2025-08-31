<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InterestAlias extends Model
{
    protected $fillable = ['interest_id','alias','locale'];

    public function interest() {
        return $this->belongsTo(Interest::class);
    }
}
