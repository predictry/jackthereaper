<?php

namespace App\Models;

class AnalyticsLogMigration extends \Eloquent
{

    protected $connection = 'analytics_mysql';
    protected $table      = "logs_migration";
    protected $fillable   = ['log_name', 'batch', 'total_actions', 'status', 'created_at', 'updated_at'];

}
