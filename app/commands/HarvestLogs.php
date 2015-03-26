<?php

use App\Models\LogMigration2,
    Carbon\Carbon,
    Illuminate\Support\Facades\DB;

class HarvestLogs extends LogsBaseCommand
{

    private $batch = 0;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'logs:harvest';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command to harvest logs name';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        try
        {
            $last_batch  = LogMigration2::max("batch");
            $this->batch = $last_batch+=1;
        }
        catch (Exception $ex)
        {
            \Log::error($ex->getMessage());
        }
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function fire()
    {
        $objects                    = $this->getBucketObjects($this->log_prefix);
        $batch_log_migration_import = [];

        if ($objects) {

            $number = 1;

            foreach ($objects as $obj) {

                $key_names = explode('/', $obj['Key']);

                if (count($key_names) > 0 && ($key_names[1] !== "")) {

                    $file_name = $key_names[1];
                    $full_path = $this->bucket . "/" . $this->log_prefix . "/" . $file_name;

                    $log_migration = LogMigration2::where('log_name', $file_name)->first();
//                    $file_name_without_ext = str_replace('.gz', '', $file_name);
                    //check if exist
                    if ($log_migration && is_object($log_migration)) {
                        
                        $comment_msg = $number . '. ' . json_encode($key_names) . " (status:{$log_migration->status})";

                        if (($log_migration->status === "pending")) {
                            \Queue::push('processLog', ['full_path' => $full_path, "log_name" => $file_name]);
                            $log_migration->status = "on_queue";
                            $log_migration->update();

                            $this->info($number . '. ' . json_encode($key_names) . ' (push event from pending status)');
                            \Log::info($number . '. ' . json_encode($key_names) . ' (push event from pending status)');
                        }
                        
                        $this->comment($comment_msg);
                    }
                    else {
                        \Queue::push('processLog', ['full_path' => $full_path, "log_name" => $file_name]);
                        $new_log_migration = [
                            'log_name'   => $file_name,
                            'full_path'  => $full_path,
                            'batch'      => $this->batch,
                            'status'     => "on_queue",
                            "created_at" => Carbon::now(),
                            "updated_at" => Carbon::now()
                        ];

                        array_push($batch_log_migration_import, $new_log_migration);
                        $this->info($number . '. ' . json_encode($key_names));
                        \Log::info($number . '. ' . json_encode($key_names));
                    }

                    if (count($batch_log_migration_import) > 0 && count($batch_log_migration_import) <= 100) {
                        LogMigration2::insert($batch_log_migration_import);
                        $batch_log_migration_import = [];
                        DB::reconnect();
                    }
                    $number+=1;
                }
            }

            if (count($batch_log_migration_import) > 0) {
                LogMigration2::insert($batch_log_migration_import);
            }

            DB::reconnect();
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

    protected function processBasedOnStatus($status)
    {
        switch ($status) {
            case "processed":
                //cp to bac folder
                break;

            case "on_backup":
                //check if the file really on the backup, if not do the processed
                break;

            case "on_queue":
                break;

            default:
                break;
        }

        return;
    }

}
