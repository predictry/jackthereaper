<?php

use App\Models\LogExtraction,
    App\PBLogs\Libraries\MapJSONUri,
    App\Pongo\Helpers\Helper,
    Carbon\Carbon,
    Illuminate\Support\Facades\Cache,
    Symfony\Component\Console\Input\InputArgument,
    Symfony\Component\Console\Input\InputOption;

class ParseLogsIntoJSON extends LogsBaseCommand
{

    private $processed_logs         = [];
    private $processing_logs        = [];
    private $processing_detail_logs = [];
    private $log_name_option        = "";
    private $batch                  = 0;
    private $limit                  = 1000;
    private $action_stats_columns   = [
        "id", "action_id", "tenant_id", "regular_stats", "recommended_stats", "created", "is_reco"
    ];
    private $sales_stats_columns    = [
        "id", "tenant_id", "action_id", "user_id", "session_id", "item_id", "group_id", "qty", "sub_total", "is_reco", "created"
    ];

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'logs:parse-to-json';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Parse logs to JSON formated';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        ini_set('memory_limit', '2048M');
        parent::__construct();
        $last_batch           = LogExtraction::max("batch");
        $count_processed_logs = LogExtraction::count();
        $this->batch          = $last_batch+=1;

        if (Cache::has('extraction_processed_logs')) {
            $this->processed_logs = Cache::get('extraction_processed_logs');
        }
        if (!Cache::has('extraction_processed_logs') || (count($this->processed_logs) != $count_processed_logs)) {
            $processed_logs = LogExtraction::all();

            if ($processed_logs) {
                $this->processed_logs = $processed_logs->lists("log_name", "id");
                Cache::add('extraction_processed_logs', $this->processed_logs, 1440);
            }
        }
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function fire()
    {
        $counter       = 1;
        $counter_dates = 0;

        $this->bucket = $this->argument('bucket');

        $this->log_prefix = $this->option('prefix');
        $this->log_prefix = isset($this->log_prefix) ? $this->log_prefix : false;

        $this->log_name_option = $this->option("log_name");

        $this->limit = $this->option('limit');
        $this->limit = (isset($this->limit)) ? $this->limit : 1000;

        $start_date = $this->option('start_date');
        $end_date   = $this->option('end_date');

        $this->printTitle();
        $bucket_objects = $this->getBucketObjects($this->bucket, $this->log_prefix);

        $dates        = Helper::getListDateRange($start_date, $end_date);
        $length_dates = count($dates);


        //1. fetch list of object based on
        //2. filter objects based on date range AND
        // name
        // limit
        //3. Download the object from S3
        //4. Parse the log into JSON
        //5. Push the parse JSON log into S3 Bucket
        // While parsing the log into JSON
        // - create batch import sql content
        // - push (import sql content) into the queue
        //5. Delete downloaded object from local
        //6. Log the processed access
        //7. Push / Upload JSON formatted log into S3

        foreach ($bucket_objects as $obj) {

            $str_date           = $str_date_formatted = null;
            $file_name          = $this->getFilename($obj, $this->log_prefix);
            if ($file_name !== "") {
                $arr_filename = explode('.', $file_name);
                if (is_array($arr_filename)) {
                    $str_date = $arr_filename[1];
                }
            }
            else
                continue;

            $this->info($file_name);
            $pos = strpos($file_name, ".gz");

            if ($pos !== false && ($str_date !== "")) {
                $file_name_without_ext = str_replace('.gz', '', $file_name);
                $date                  = date_create_from_format('Y-m-d-H', $str_date);
                $str_date_formatted    = $date->format('Y-m-d');

                if (in_array($str_date_formatted, $dates)) {
                    $this->info("in_array");
                    if (!\File::exists(storage_path("logs/s3_tmp/{$file_name_without_ext}"))) { //check if file exist
                        //download the object to the local storage
                        $this->downloadObject($this->bucket, $obj['Key'], storage_path("logs/s3_tmp/{$file_name}"));

                        //extract the zip log
                        $this->extractObject(storage_path("logs/s3_tmp/{$file_name}"));

                        //check if extract file exists
                        if (\File::exists(storage_path("logs/s3_tmp/{$file_name_without_ext}"))) { //check if file exist
                            $this->info("extract file exists");
                            $rows = $this->readFile(storage_path("logs/s3_tmp/{$file_name_without_ext}"));

                            file_put_contents(storage_path("logs/extract/{$file_name_without_ext}" . ".json"), json_encode($rows, JSON_PRETTY_PRINT));
                        }

                        $this->info("ready to upload file");
                        $this->s3->putObject([
                            'Bucket'     => "trackings/action-logs-json-formated-test",
                            "Key"        => "{$file_name_without_ext}" . ".json",
                            "SourceFile" => storage_path("logs/extract/{$file_name_without_ext}" . ".json")
                        ]);

                        // We can poll the object until it is accessible
                        $this->s3->waitUntil('ObjectExists', array(
                            'Bucket' => "trackings/action-logs-json-formatted",
                            "Key"    => "{$file_name_without_ext}" . ".json",
                        ));

                        $this->deleteTempLogFiles($file_name);
                    }
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
        return array(
            array('bucket', InputArgument::REQUIRED, 'The bucket name'),
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
            array('prefix', null, InputOption::VALUE_OPTIONAL, 'The prefix name', null),
            array('log_name', null, InputOption::VALUE_OPTIONAL, 'The specific log name gz', null),
            array('limit', null, InputOption::VALUE_OPTIONAL, 'Limit number of logs fetch', null),
            array('start_date', null, InputOption::VALUE_REQUIRED, 'The start date of the log', null),
            array('end_date', null, InputOption::VALUE_REQUIRED, 'The end date of the log', null)
        );
    }

    protected function getDetailOfFilename($file_name)
    {
        $keys = [
            'distribution_id',
            'date_and_hour',
            'unique_id',
            'ext'
        ];

        $values = explode('.', $file_name);
        $arr    = (count($values) === count($keys)) ? array_combine($keys, $values) : $values;
        return $arr;
    }

    protected function getDeserializeQuery($strQuery, $log_data = false)
    {
        $mapJSONUri = new MapJSONUri();
        try
        {
            $data = $mapJSONUri->mapUriParamsToJSON($strQuery);
            return $data;
        }
        catch (Exception $ex)
        {
            \Log::info($ex->getMessage());
            return false;
        }
    }

    protected function printTitle()
    {
        $str = "Bucket name: {$this->bucket}; Prefix name = " . (isset($this->log_prefix) && ($this->log_prefix) ? $this->log_prefix : '-') . "; ";
        $str .= "Log name: " . (isset($this->log_name_option) && ($this->log_name_option) ? $this->log_name_option : '-') . "; ";
        $str .= "Start date: " . $this->option('start_date') . "; ";
        $str .= "End date: " . $this->option('end_date');
        $this->comment($str);
    }

    protected function deleteTempLogFiles($file_name)
    {
        $file_name_without_ext = str_replace('.gz', '', $file_name);
        \File::delete(storage_path("logs/s3_tmp/{$file_name}")); //delete zip log
        \File::delete(storage_path("logs/s3_tmp/{$file_name_without_ext}")); //delete extract log
    }

    protected function readFile($file_path)
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

                $deserialized_query = '';
                if (isset($combine['cs-uri-query']) && $combine['cs-uri-query'] !== "-" && $combine['date'] && $combine['time']) {
                    $deserialized_query = $this->getDeserializeQuery($combine['cs-uri-query'], $combine);
                }

                $allowed_headers = [
                    'date', 'time', 'c-ip', 'cs-uri-stem'
                ];

                foreach ($combine as $key => $val) {
                    if (!in_array($key, $allowed_headers))
                        unset($combine[$key]);
                }

                array_push($rows, ['access_log' => $combine, 'deserialized_query' => $deserialized_query]);
                $counter+=1;
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

}
