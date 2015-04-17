<?php

namespace App\Pongo\Helpers;

use Carbon\Carbon;

/**
 * Author       : Rifki Yandhi
 * Date Created : Apr 1, 2015 7:54:47 PM
 * File         : Helper.php
 * Copyright    : rifkiyandhi@gmail.com
 * Function     : 
 */
class Helper
{

    public static function getListDateRange($start_date, $end_date)
    {
        $dates = [];

        $dt_start = Carbon::createFromFormat("Y-m-d", $start_date);
        $dt_end   = Carbon::createFromFormat("Y-m-d", $end_date);

        array_push($dates, $dt_start->toDateString());

        $diff_days = $dt_end->diffInDays($dt_start);
        if ($diff_days > 0)
            for ($i = 1; $i < $diff_days; $i++) {
                array_push($dates, $dt_start->addDays(1)->toDateString());
            }

        return $dates;
    }

}

/* End of file Helper.php */