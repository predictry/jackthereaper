<?php

use App\Models\LogMigration2;

class ReverseBackupLogs extends LogsBaseCommand
{

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'logs:reverseBackup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reverse Backup Logs';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->bucket = $this->bucket_backup;
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function fire()
    {
        $last_backed_up_logs = LogMigration2::where('status', 'processed')->orderBy('updated_at', 'DESC')->take(800)->skip(0)->get();
        $logs_name           = [];

        foreach ($last_backed_up_logs as $item) {
            array_push($logs_name, $item['log_name']);
        }

        $objects = $this->getBucketObjects();

        $comment_msg = "";

        foreach ($objects as $obj) {

            $key_names = explode('/', $obj['Key']);

            if (count($key_names) > 0 && ($key_names[1] !== "")) {

                $file_name = $key_names[1];
                $key_name  = $this->log_prefix . "/" . $file_name;
            }

            if (in_array($file_name, $logs_name) && !$this->s3->doesObjectExist($this->bucket_backup, $file_name)) {
                $copy_model = $this->s3->copyObject([
                    'Bucket'     => $this->bucket,
                    'Key'        => $file_name,
                    'CopySource' => "{$this->bucket_backup}/{$key_name}"
                ]);

                if (count($copy_model->toArray()) > 0) {
                    $comment_msg .= " | Successfully reverse backed up";
                    $this->info("Moved successfully to source bucket {$this->bucket}.");
                    $arr_copy_model = $copy_model->toArray();
                    if (isset($arr_copy_model['ETag']) && $arr_copy_model['ETag'] !== "") {
                        $this->removeRemoteObject($obj['Key'], $this->bucket_backup);
                        $comment_msg .= " and removed from backup";
                    }

                    $log_migration = LogMigration2::where('log_name', $file_name)->first();
                    if ($log_migration) {
                        $log_migration->status = 'pending';
                        $log_migration->update();
                    }
                    $comment_msg.= ".";
                }

                $this->comment($comment_msg);
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
