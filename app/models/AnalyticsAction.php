<?php

namespace App\Models;

class AnalyticsAction extends \Eloquent
{

    protected $connection = 'analytics_mysql';
    protected $table      = "actions";
    public $timestamps    = false;

}
