<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LLMJob extends Model
{
    protected $table = 'llm_jobs';

    protected $fillable = [
        'type','status','context_post_id','input','options','result',
        'error_code','error_message','started_at','finished_at'
    ];

    protected $casts = [
        'input'       => 'array',
        'options'     => 'array',
        'result'      => 'array',
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];
}
