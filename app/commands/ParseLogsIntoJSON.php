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
        $this->bucket          = $this->argument('bucket');
        $this->log_prefix      = $this->option('prefix');
        $this->log_prefix      = isset($this->log_prefix) ? $this->log_prefix : false;
        $this->log_name_option = $this->option("log_name");
        $this->limit           = $this->option('limit');
        $this->limit           = (isset($this->limit)) ? $this->limit : 1000;
        $start_date            = $this->option('start_date');
        $end_date              = $this->option('end_date');

        $this->printTitle();
        $bucket_objects = $this->getBucketObjects($this->bucket, $this->log_prefix);

        $counter = 1;

        $dates         = Helper::getListDateRange($start_date, $end_date);
        $counter_dates = 0;
        $length_dates  = count($dates);

        foreach ($bucket_objects as $obj) {
            $key_names          = explode('/', $obj['Key']);
            $str_date_formatted = null;

            if ($this->log_prefix) {
                if (count($key_names) > 0 && ($key_names[0] === $this->log_prefix)) {
                    if ($key_names[1] === "")
                        continue;
                }
                $file_name = $key_names[1];
            }
            else
                $file_name = $key_names[0];

            if ($this->log_name_option && isset($this->log_name_option)) {
                if ($this->log_name_option != $file_name) {
                    continue;
                }
                else
                    $this->info("found {$file_name}");
            }

            if ($file_name !== "") {
                $arr_filename = explode('.', $file_name);
            }

            $file_name_without_ext = str_replace('.gz', '', $file_name);

            if (($file_name_without_ext === "") || ($file_name_without_ext === ".DS_Store") || in_array($file_name_without_ext . '.json', $this->processed_logs) || in_array($file_name_without_ext . '.json', $this->processing_logs))
                continue;

            if (is_array($arr_filename) && isset($arr_filename[1]))
                $str_date = $arr_filename[1];

            if ($str_date !== "") {
                $date               = date_create_from_format('Y-m-d-H', $str_date);
                $str_date_formatted = $date->format('Y-m-d');

                if (!in_array($str_date_formatted, $dates)) { //current file is not within date range//then continue
                    $this->info("{$file_name} is not within date range");
                    continue;
                }
                else {
                    $this->error("{$file_name} is within date range");
                    $counter_dates +=1;
                }
            }

            if ($file_name !== "") {
                $start_time = time();
                //download the object to the local storage
                $this->downloadObject($this->bucket, $obj['Key'], storage_path("logs/s3_tmp/{$file_name}"));

                //extract the zip log
                $this->extractObject(storage_path("logs/s3_tmp/{$file_name}"));

                if (\File::exists(storage_path("logs/s3_tmp/{$file_name_without_ext}"))) {
                    $rows = $this->readFile(storage_path("logs/s3_tmp/{$file_name_without_ext}"));
                    file_put_contents(storage_path("logs/extract/{$file_name_without_ext}" . ".json"), json_encode($rows, JSON_PRETTY_PRINT));
                }
                $end_time = time();
                $diff     = $end_time - $start_time;
                $this->info("Processing time for log {$file_name}: {$diff} sec");

                $this->deleteTempLogFiles($file_name);

                $this->info($counter . ") " . json_encode($this->getDetailOfFilename($file_name)));
                $counter+=1;

                if ($counter % 100 == 1)
                    sleep(2);

                array_push($this->processing_logs, $file_name);
                array_push($this->processing_detail_logs, [
                    'log_name'   => $file_name_without_ext . '.json',
                    'full_path'  => storage_path("logs/extract/{$file_name_without_ext}" . ".json"),
                    'batch'      => $this->batch,
                    'created_at' => new Carbon(),
                    'updated_at' => new Carbon()
                ]);


                if (count($this->processing_detail_logs) > 10) {
                    LogExtraction::insert($this->processing_detail_logs);
                    $this->processing_detail_logs = [];
                }
            }

            if ($counter % $this->limit === 0) {
                LogExtraction::insert($this->processing_detail_logs);
                $this->processing_detail_logs = [];
                die;
            }
        }

        if (count($this->processing_detail_logs) > 0) {
            LogExtraction::insert($this->processing_detail_logs);
            $this->processing_detail_logs = [];
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

//                $this->info(json_encode($row));

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
