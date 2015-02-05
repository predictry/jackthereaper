<?php

use App\Models\LogMigration2,
    Illuminate\Console\Command;

class HarvestLogs extends Command
{

    private $bucket     = "", $log_prefix = "", $batch      = 0;
    private $s3         = null;

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
            $this->s3         = App::make('aws')->get('s3');
            $this->bucket     = $_ENV['TRACKING_BUCKET'];
            $this->log_prefix = $_ENV['TRACKING_BUCKET_ACCESS_LOGS'];


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
        $objects                    = $this->getBucketObjects();
        $batch_log_migration_import = [];

        if ($objects) {

            $number = 1;

            foreach ($objects as $obj) {

                $key_names = explode('/', $obj['Key']);

                if (count($key_names) > 0 && ($key_names[1] !== "")) {

                    $file_name = $key_names[1];
                    $full_path = getenv('TRACKING_BUCKET') . "/" . getenv('TRACKING_BUCKET_ACCESS_LOGS') . "/" . $file_name;

                    $log_migration = LogMigration2::where('log_name', $file_name)->first();
//                    $file_name_without_ext = str_replace('.gz', '', $file_name);
                    //check if exist
                    if ($log_migration) {

                        if ($log_migration->status === "pending") {
                            \Queue::push('processLog', ['full_path' => $full_path, "log_name" => $file_name]);
                            $log_migration->status = "on_queue";
                            $log_migration->update();
                        }
                    }
                    else {

                        \Queue::push('processLog', ['full_path' => $full_path, "log_name" => $file_name]);
                        $new_log_migration = [
                            'log_name'   => $file_name,
                            'full_path'  => $full_path,
                            'batch'      => $this->batch,
                            'status'     => "on_queue",
                            "created_at" => \Carbon\Carbon::now(),
                            "updated_at" => \Carbon\Carbon::now()
                        ];

                        array_push($batch_log_migration_import, $new_log_migration);
                    }

                    if (count($batch_log_migration_import) > 0 && count($batch_log_migration_import) <= 100) {
                        LogMigration2::insert($batch_log_migration_import);
                        $batch_log_migration_import = [];
                    }

                    $this->info($number . '. ' . json_encode($key_names));
                    \Log::info($number . '. ' . json_encode($key_names));
                    $number+=1;
                }
            }

            if (count($batch_log_migration_import) > 0) {
                LogMigration2::insert($batch_log_migration_import);
            }
        }
    }

    /**
     * Get bucket objects
     * @return array | boolean
     */
    private function getBucketObjects()
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
