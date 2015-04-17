<?php

namespace App\Models;

class AnalyticsSaleStat extends \Eloquent
{

    protected $connection = 'analytics_mysql';
    protected $table      = "sales_stats";
    protected $fillable   = ['tenant_id', 'action_id', 'session_id', 'user_id', 'item_id', 'qty', 'sub_total', 'created'];
    public $timestamps    = false;

}
