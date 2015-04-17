<?php

namespace App\Models;

class LogExtraction extends \Eloquent
{

    protected $table    = "log_extractions";
    protected $fillable = ['log_name', 'batch'];

}
