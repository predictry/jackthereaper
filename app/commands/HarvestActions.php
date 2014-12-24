<?php

use App\Models\LogMigration,
    App\PBLogs\Libraries\MapJSONUri,
    Illuminate\Console\Command,
    Illuminate\Support\Facades\App,
    Illuminate\Support\Facades\Validator,
    Symfony\Component\Console\Input\InputOption;

class HarvestActions extends Command
{

    private $bucket         = "", $log_prefix     = "";
    private $s3, $limit          = 0, $delay          = 0, $total_counter  = 0, $counter        = 0, $counter_failed = 0, $batch          = 0;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'actions:harvest';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description.';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        $this->bucket     = $_ENV['TRACKING_BUCKET'];
        $this->log_prefix = $_ENV['TRACKING_BUCKET_ACCESS_LOGS'];
        try
        {
            $this->s3 = App::make('aws')->get('s3');
            if (!\File::exists(storage_path("downloads/s3/{$this->bucket}/{$this->log_prefix}/finish/"))) {
                \File::makeDirectory(storage_path("downloads/s3/{$this->bucket}/{$this->log_prefix}/finish/"));
            }

            $last_batch  = LogMigration::max("batch");
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
//        $action_name = $this->argument("name");

        $limit       = $this->option("limit");
        $this->limit = isset($limit) ? $limit : 100;

        $delay       = $this->option("delay");
        $this->delay = isset($delay) ? $delay : 5;

        //CONSOLE MSG 1
        $this->info("Start to harvest with limit {$this->limit} and delay of each batch {$this->delay} second(s) ");

        $objects = $this->getBucketObjects();

        if ($objects) {

            foreach ($objects as $obj) {
                $array_keyname = explode("/", str_replace('.gz', '', $obj['Key']));
                $file_name     = $array_keyname[1];

                $is_log_exists = LogMigration::where("log_name", $file_name)->count();
                $this->comment("Object key: {$obj['Key']}. Downloading ...");
                try
                {
                    if (!$is_log_exists && $this->downloadObject($obj['Key'])) {
                        $this->counter        = $this->counter_failed = 0;

                        $current_log_model = LogMigration::create([
                                    'log_name' => $file_name,
                                    'batch'    => $this->batch
                        ]);

                        $this->extractObject(storage_path("downloads/s3/{$this->bucket}/{$obj['Key']}"));

                        //check if uncompressed file available
                        if (\File::exists(storage_path("downloads/s3/{$this->bucket}/" . str_replace('.gz', '', $obj['Key'])))) {

                            //CONSOLE MSG 2
                            $this->info("Downloaded. File uncompressed. Saved as JSON.");

                            $rows = $this->readFile(storage_path("downloads/s3/{$this->bucket}/" . str_replace('.gz', '', $obj['Key'])));
                            \File::delete(storage_path("downloads/s3/{$this->bucket}/" . str_replace('.gz', '', $obj['Key'])));
                        }

                        //save to json
                        file_put_contents(storage_path("downloads/s3/{$this->bucket}/{$this->log_prefix}/finish/" . $file_name . ".json"), json_encode($rows, JSON_PRETTY_PRINT));
//                        $this->removeRemoteObject($obj['Key']); //remove the object in s3

                        if ($current_log_model->id) {
                            $current_log_model->total_logs           = $this->counter;
                            $current_log_model->failed_executed_logs = $this->counter_failed;
                            $current_log_model->update();

                            $this->total_counter+=$this->counter;
                        }
                    }
                    else if ($is_log_exists) {
                        $this->comment("Has been completed.");
                    }
                }
                catch (Exception $ex)
                {
                    $this->info($ex->getMessage());
                }
            }
        }

        $this->info("{$this->counter} action(s) has been executed.");
    }

    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return array(
//            array('name', InputArgument::REQUIRED, 'The name of the action that you want to harvest.'),
        );
    }

    /**
     * Get the console command options.
     *
     * @return array
     */
    protected function getOptions()
    {
        return array(
            array('limit', null, InputOption::VALUE_OPTIONAL, 'Limit of each batch will be process', null),
            array('delay', null, InputOption::VALUE_OPTIONAL, 'Time in seconds of how long the delay between each batch', null),
        );
    }

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

    private function extractObject($keyname)
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

    private function downloadObject($key)
    {
        // Save object to a file.
        $result = $this->s3->getObject(array(
            'Bucket' => $this->bucket,
            'Key'    => $key,
            'SaveAs' => storage_path("downloads/s3/{$this->bucket}/{$key}")
        ));

        return ($result) ? true : false;
    }

    private function removeRemoteObject($key)
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

    private function readFile($file_path)
    {
        $headers = $rows    = [];
        $counter = 0;
        try
        {
            $fh = fopen($file_path, 'r');

            while ($line = fgets($fh)) {
                //check if comment

                if (substr($line, 0, 1) == "#") {

                    if ($counter === 1) {
                        $row = explode(" ", $line);

                        foreach ($row as $header) {
                            if (substr($header, 0, 1) == "#")
                                continue;

                            array_push($headers, $header);
                        }
                    }
                    $counter+=1;
                    continue;
                }

                $row     = explode("\t", $line);
                $combine = array_combine($headers, $row);
                array_push($rows, $combine);

                $counter+=1;

                if (isset($combine['cs-uri-query']) && $combine['cs-uri-query'] !== "-" && $combine['date'] && $combine['time']) {
                    $this->processQueries($combine['cs-uri-query'], $combine);
                }
            }

            fclose($fh);
            return $rows;
        }
        catch (Exception $ex)
        {
            \Log::error($ex->getMessage());
            return false;
        }
    }

    private function processQueries($strQuery, $log_data)
    {
        $mapJSONUri = new MapJSONUri();
        try
        {
            $data = $mapJSONUri->mapUriParamsToJSON($strQuery);
            $this->_store($data, $log_data['date'], $log_data['time'], $log_data);
            $this->info($this->counter . ') ' . str_replace("/", "", $log_data['cs-uri-stem']) . ' => ' . $log_data['cs-bytes'] . ' bytes | ' . "{$log_data['date']} {$log_data['time']}"); //CONSOLE MSG 3
        }
        catch (Exception $ex)
        {
            $this->counter-=1;
            $this->counter_failed += 1;
            $this->error($this->counter . ') ' . str_replace("/", "", $log_data['cs-uri-stem']) . ' => ' . $log_data['cs-bytes'] . ' bytes | ' . "{$log_data['date']} {$log_data['time']} >>> {$ex->getMessage()}"); //CONSOLE MSG 4
        }
    }

    /**
     * Queue the action
     *
     * Return void
     */
    private function _store($data, $date, $time, $log_data = null)
    {
        $action_validator = Validator::make(['action' => $data->action], array("action" => "required"));
        if ($action_validator->passes()) {

            $browser_inputs = [
                'tenant_id'  => (isset($data->tenant_id)) ? $data->tenant_id : null,
                'api_key'    => (isset($data->api_key)) ? $data->api_key : null,
                'user_id'    => (isset($data->user_id)) ? $data->user_id : null,
                'session_id' => (isset($data->session_id)) ? $data->session_id : null,
                'browser_id' => (isset($data->browser_id)) ? $data->browser_id : null
            ];

            $rules = [
                "tenant_id"  => "required",
                "api_key"    => "required",
                "session_id" => "required",
                "items"      => "array"
            ];

            $inputs = array_merge($browser_inputs, [
                'action'              => get_object_vars($data->action),
                'user'                => (isset($data->user) && !is_null($data->user)) ? get_object_vars($data->user) : [],
                'items'               => [],
                "log_date_created_at" => "{$date}",
                "log_time_created_at" => "{$time}"
            ]);

            if (isset($data->items)) {
                foreach ($data->items as $obj) {
                    if (!is_null($obj)) {
                        array_push($inputs['items'], get_object_vars($obj));
                    }
                }
            }

            if (!is_null($log_data)) {
                $inputs['action']['cs-referer'] = $log_data['cs(Referer)'];
                $inputs['action']['c-ip']       = $log_data['c-ip'];
            }

            $input_validator = Validator::make($inputs, $rules);
            if ($input_validator->passes()) {
                /* queue data */
                $queue_data['browser_inputs'] = $browser_inputs;
                $queue_data['inputs']         = $inputs;
                $queue_data['job_id']         = \Illuminate\Support\Str::random(10);

                if (str_replace("/", "", $log_data['cs-uri-stem']) === "check_delete_item.gif") {

                    if (isset($data->widget_instance_id)) {
                        $queue_data['widget_instance_id'] = $data->widget_instance_id;
                        $queue_data['item_id']            = $data->item_id;
                    }
                    $date = \Carbon\Carbon::now()->addMinutes(3);
                    \Queue::later($date, 'App\Pongo\Queues\CheckDeletion@fire', $queue_data);
                    \Log::info("App\Pongo\Queues\CheckDeletion@fire", $queue_data);
                }
                else
                    \Queue::push('App\Pongo\Queues\SendAction@store', $queue_data);

                if ($log_data) {
                    $this->counter+=1;
                }
            }
            else
                $this->info($input_validator->errors()->first());
        }
        else
            $this->info($action_validator->errors()->first());
        //return Response::json($response, $this->http_status);
    }

}
