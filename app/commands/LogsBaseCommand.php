<?php

/**
 * Author       : Rifki Yandhi
 * Date Created : Feb 5, 2015 5:03:54 PM
 * File         : LogsBaseCommand.php
 * Copyright    : rifkiyandhi@gmail.com
 * Function     : 
 */
use Illuminate\Console\Command;

class LogsBaseCommand extends Command
{

    public $bucket        = "", $bucket_backup = "", $log_prefix    = "";
    public $s3            = null;

    /**
     * Create a new command instance.
     * c
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        try
        {
            $this->s3            = App::make('aws')->get('s3');
            $this->bucket        = getenv('TRACKING_BUCKET');
            $this->bucket_backup = getenv('TRACKING_BACKUP_BUCKET');
            $this->log_prefix    = getenv('TRACKING_BUCKET_ACCESS_LOGS');
        }
        catch (Exception $ex)
        {
            \Log::error($ex->getMessage());
        }
    }

    /**
     * Get bucket objects
     * @return array | boolean
     */
    function getBucketObjects()
    {
        try
        {
            $iterator = $this->s3->getIterator("ListObjects", ['Bucket' => $this->bucket, 'Prefix' => $this->log_prefix]);
            return $iterator;
        }
        catch (Exception $ex)
        {
            \Log::error($ex->getMessage());
            return false;
        }
    }

    function removeRemoteObject($key)
    {
        try
        {
            $result = $this->s3->deleteObject([
                'Bucket' => $this->bucket,
                'Key'    => $key
            ]);

            return ($result) ? true : false;
        }
        catch (Exception $ex)
        {
            \Log::error($ex->getMessage());
            return false;
        }
    }

}

/* End of file LogsBaseCommand.php */