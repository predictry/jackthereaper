<?php

namespace App\Models;

class AnalyticsActionStat extends \Eloquent
{

    protected $connection = 'analytics_mysql';
    protected $table      = "actions_stats";
    protected $fillable   = ['action_id', 'tenant_id', 'regular_stats', 'recommendation_stats', 'created'];
    public $timestamps    = false;

}
