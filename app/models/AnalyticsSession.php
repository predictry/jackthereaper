<?php

namespace App\Models;

class AnalyticsSession extends \Eloquent
{

    protected $connection = 'analytics_mysql';
    protected $table      = "sessions";
    protected $fillable   = ['tenant_id', 'session', 'log_created', 'created_at', 'updated_at'];

}
