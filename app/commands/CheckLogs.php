<?php

use App\Models\LogMigration2;

class CheckLogs extends LogsBaseCommand
{

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'logs:check';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check if the logs has been processed. Backup the file to different bucket.';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function fire()
    {
        $objects     = $this->getBucketObjects();
        $number      = 1;
        $comment_msg = "";
        foreach ($objects as $obj) {

            $key_names = explode('/', $obj['Key']);

            if (count($key_names) > 0 && ($key_names[1] !== "")) {

                $file_name = $key_names[1];
                $key_name  = $this->log_prefix . "/" . $file_name;

                $log_migration = LogMigration2::where('log_name', $file_name)->first();
                if ($log_migration && is_object($log_migration) && ($log_migration->status === "processed")) {

                    $comment_msg = $number . '. ' . json_encode($key_names) . " (status:{$log_migration->status})";

                    //does the object exist in backup dir?
                    if (!$this->s3->doesObjectExist($this->bucket_backup, $file_name)) {
                        $copy_model = $this->s3->copyObject([
                            'Bucket'     => $this->bucket_backup,
                            'Key'        => $file_name,
                            'CopySource' => "{$this->bucket}/{$key_name}"
                        ]);

                        if (count($copy_model->toArray()) > 0) {
                            $comment_msg .= " | Successfully backup";
                            $this->info("Moved successfully to backup bucket {$this->bucket_backup}.");
                            $arr_copy_model = $copy_model->toArray();
                            if (isset($arr_copy_model['ETag']) && $arr_copy_model['ETag'] !== "") {
                                $this->removeRemoteObject($obj['Key']);
                                $comment_msg .= " and removed from source";
                            }

                            $log_migration->status = 'on_backup';
                            $log_migration->update();
                            $comment_msg.= ".";
                        }
                    }
                    else {
                        $comment_msg .= " | has been backup.";
                    }

                    $this->comment($comment_msg);
                    $number+=1;
                }
            }
        }
    }

    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return [];
    }

    /**
     * Get the console command options.
     *
     * @return array
     */
    protected function getOptions()
    {
        return [];
    }

}
