<?php

use App\Models\LogMigration2;

class ReverseBackupLogs extends LogsBaseCommand
{

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'logs:reverse-backup';

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
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function fire()
    {
        $last_backed_up_logs = LogMigration2::where('status', 'on_backup')->orderBy('updated_at', 'DESC')->take(800)->skip(0)->get();
        $logs_name           = [];

        foreach ($last_backed_up_logs as $item) {
            array_push($logs_name, $item['log_name']);
        }

        $objects = $this->getBucketObjects($this->bucket_backup);

        $number      = 1;
        $comment_msg = "";

        foreach ($objects as $obj) {

            $key_names = explode('/', $obj['Key']);

            if (is_array($key_names)) {

                $file_name = $key_names[0];
                $key_name  = $file_name;
            }

            if (($file_name !== "") && in_array($file_name, $logs_name) && $this->s3->doesObjectExist($this->bucket_backup, $file_name)) {

                $copy_model = $this->s3->copyObject([
                    'Bucket'     => "{$this->bucket}/{$this->log_prefix}",
                    'Key'        => $file_name,
                    'CopySource' => "{$this->bucket_backup}/{$file_name}"
                ]);


                if (count($copy_model->toArray()) > 0) {
                    $comment_msg    = $number . '. ' . $file_name;
                    $comment_msg .= " | Successfully reverse backed up";
                    $this->info("Moved successfully to source bucket {$this->bucket}/{$this->log_prefix}.");
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
                    $number +=1;
                    $this->comment($comment_msg);
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
