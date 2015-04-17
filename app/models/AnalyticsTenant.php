<?php

namespace App\Models;

class AnalyticsTenant extends \Eloquent
{

    protected $connection = 'analytics_mysql';
    protected $table      = "tenants";
    public $timestamps    = false;

}
