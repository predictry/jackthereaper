<?php

namespace App\Models;

class LogMigration extends \Eloquent
{

    protected $fillable = ["log_name", "batch", "total_logs", "failed_executed_jobs"];
    protected $table    = 'log_migrations';

}
