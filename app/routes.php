<?php

/*
  |--------------------------------------------------------------------------
  | Application Routes
  |--------------------------------------------------------------------------
  |
  | Here is where you can register all of the routes for an application.
  | It's a breeze. Simply tell Laravel the URIs it should respond to
  | and give it the Closure to execute when that URI is requested.
  |
 */

Route::get('/', function() {

    $bucket     = "trackings";
    $log_prefix = "action-logs";
    $s3         = App::make('aws')->get('s3');
    try
    {
        $iterator = $s3->getIterator("ListObjects", ['Bucket' => $bucket, 'Prefix' => $log_prefix]);
        foreach ($iterator as $obj) {
            echo $obj['Key'] . "<br/>";
        }
    }
    catch (Exception $ex)
    {
        \Log::error($ex->getMessage());
//        return false;
    }

    echo '<pre>';
//    print_r($iterator);
    echo "<br/>----<br/>";
    echo '</pre>';
    die;

    return View::make('hello');
});
