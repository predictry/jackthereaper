<?php

use App\Models\LogMigration2,
    Illuminate\Console\Command,
    Illuminate\Filesystem\FileNotFoundException,
    Symfony\Component\Console\Input\InputOption;

class CheckProcessed extends Command
{

    private $sqs                     = null;
    private $processed_logs_names    = [];
    private $queue_url               = "https://sqs.ap-southeast-1.amazonaws.com/284064871105/predictry-logs";
    private $processed_log_file_path = '';

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'logs:checkProcessed';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check file that has been processed manually. Resource from file. (.txt)';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->sqs = App::make('aws')->get('sqs');
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function fire()
    {
        $this->processed_log_file_path = $this->option('file_path');
        if (!is_null($this->processed_log_file_path) && $this->processed_log_file_path !== "") {
            $this->processed_log_file_path = storage_path($this->processed_log_file_path);
            try
            {
                $i = 1;
                foreach (file($this->processed_log_file_path) as $line) {
                    if ($i > 1) {
                        $arr = explode(',', str_replace('"', '', $line));

                        if (count($arr) > 0) {
                            array_push($this->processed_logs_names, $arr[0]);
                        }
                    }
                    $i++;
                }
            }
            catch (FileNotFoundException $exception)
            {
                die("The file doesn't exist");
            }

            do {
                $result_receive = $this->sqs->receiveMessage(array(
                    'QueueUrl' => $this->queue_url,
                ));

                if (!is_null($result_receive) && is_object($result_receive)) {
                    if (count($result_receive->getPath('Messages')) > 0) {
                        $msg = current($result_receive->getPath('Messages'));

                        if (!is_null($msg))
                            foreach ($result_receive->getPath('Messages/*/Body') as $messageBody) {
                                // Do something with the message
                                if ($messageBody !== "") {
                                    $obj = json_decode($messageBody);
                                    if (in_array($obj->data->log_name, $this->processed_logs_names)) {

                                        $result_delete = $this->sqs->deleteMessage(array(
                                            'QueueUrl'      => $this->queue_url,
                                            'ReceiptHandle' => $msg['ReceiptHandle']
                                        ));

                                        if (is_object($result_delete)) {
                                            $log_migration = LogMigration2::where('log_name', $obj->data->log_name)->first();
                                            if ($log_migration) {
                                                $log_migration->status = 'processed';
                                                $log_migration->update();
                                            }

                                            $this->info("Job deleted from the queue. Filename: {$obj->data->log_name}");
                                            \Log::info("Job deleted from the queue. Filename: {$obj->data->log_name}");
                                        }
                                    }
                                }
                            }
                    }
                }
            } while ($result_receive);
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
        return array(array('file_path', null, InputOption::VALUE_REQUIRED, 'Path of the file, and paste after storage. (Put the file in app/storage)', null));
    }

}
