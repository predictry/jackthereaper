<?php

use App\Models\LogMigration2,
    Carbon\Carbon,
    Illuminate\Support\Facades\DB,
    Symfony\Component\Console\Input\InputArgument,
    Symfony\Component\Filesystem\Exception\FileNotFoundException;

class CheckLogsFile extends LogsBaseCommand
{

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'logs:check-logs-file';

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
        $log_file                   = $this->argument('log_file');
        $batch_log_migration_import = [];
        $batch_log_migration_update = [];
        try
        {
            $fh      = fopen(storage_path($log_file), 'r');
            $counter = 1;
            while ($line    = fgets($fh)) {
                $pos = strpos($line, 'Notified LogKeeper of processed file');
                if ($pos !== false) {
                    $file_name      = substr($line, 81, -2);
                    $log_migration2 = LogMigration2::where('log_name', $file_name)->first();

                    if ($log_migration2) {
                        $this->info("{$counter}. found on the table {$file_name}");
                        array_push($batch_log_migration_update, $file_name);
                        $counter+=1;
                    }
                    else {
                        $new_log_migration = [
                            'log_name'   => $file_name,
                            'full_path'  => 'trackings/action-logs/' . $file_name,
                            'batch'      => 0,
                            'status'     => "on_queue",
                            "created_at" => Carbon::now(),
                            "updated_at" => Carbon::now()
                        ];

                        array_push($batch_log_migration_import, $new_log_migration);
                    }
                }
            }

            if (count($batch_log_migration_import) > 0) {
                LogMigration2::insert($batch_log_migration_import);
                $batch_log_migration_import = [];
                DB::reconnect();
            }

            if (count($batch_log_migration_update) > 0) {
                LogMigration2::whereIn('log_name', $batch_log_migration_update)->update(array('status' => 'processed'));
                DB::reconnect();
            }
        }
        catch (FileNotFoundException $ex)
        {
            $this->error($ex->getMessage());
        }
    }

    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return array(
            array('log_file', InputArgument::REQUIRED, 'The log file'),
        );
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
