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
    function getBucketObjects($bucket, $log_prefix = false)
    {
        try
        {
            $params = ['Bucket' => $bucket];
            if ($log_prefix) {
                $params = array_merge($params, ['Prefix' => $log_prefix]);
            }

            $iterator = $this->s3->getIterator("ListObjects", $params);
            return $iterator;
        }
        catch (Exception $ex)
        {
            \Log::error($ex->getMessage());
            return false;
        }
    }

    function getFilename($obj, $log_prefix)
    {
        $file_name = "";
        $key_names = explode('/', $obj['Key']);

        if ($log_prefix) {
            if (count($key_names) > 0 && ($key_names[0] === $log_prefix)) {
                if ($key_names[1] === "")
                    $file_name = "";
            }
            $file_name = $key_names[1];
        }
        else
            $file_name = $key_names[0];

        return $file_name;
    }

    function removeRemoteObject($key, $bucket = null)
    {
        try
        {
            $result = $this->s3->deleteObject([
                'Bucket' => (is_null($bucket)) ? $this->bucket : $bucket,
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

    function extractObject($keyname)
    {
        // Raising this value may increase performance
        $buffer_size   = 4096; // read 4kb at a time
        $out_file_name = str_replace('.gz', '', $keyname);

        // Open our files (in binary mode)
        $file     = gzopen($keyname, 'rb');
        $out_file = fopen($out_file_name, 'wb');

        // Keep repeating until the end of the input file
        while (!gzeof($file)) {
            // Read buffer-size bytes
            // Both fwrite and gzread and binary-safe
            fwrite($out_file, gzread($file, $buffer_size));
        }

        // Files are done, close files
        fclose($out_file);
        gzclose($file);
    }

    function downloadObject($bucket, $keyname, $dest)
    {
        // Save object to a file.
        $result = $this->s3->getObject(array(
            'Bucket' => $bucket,
            'Key'    => $keyname,
            'SaveAs' => $dest
        ));

        return ($result) ? true : false;
    }

}

/* End of file LogsBaseCommand.php */