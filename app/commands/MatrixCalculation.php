<?php

use App\Models\AnalyticsAction,
    App\Models\AnalyticsLogMigration,
    App\Models\AnalyticsTenant,
    App\Pongo\Helpers\Helper,
    App\Pongo\Repositories\AnalyticsTenantRepository,
    Carbon\Carbon,
    Illuminate\Console\Command,
    Illuminate\Support\Facades\Cache,
    Symfony\Component\Console\Input\InputOption;

class MatrixCalculation extends Command
{

    protected $batch                  = 0;
    protected $processed_logs         = [];
    protected $processing_logs        = [];
    protected $processing_detail_logs = [];
    protected $tenants                = [];

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'logs:matrix-calculation';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Calculate matrix.';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        ini_set('memory_limit', '512M');
        parent::__construct();
        $last_batch           = AnalyticsLogMigration::max("batch");
        $count_processed_logs = AnalyticsLogMigration::count();
        $this->batch          = $last_batch+=1;


        if (Cache::has('processed_logs')) {
            $this->processed_logs = Cache::get('processed_logs');
        }

        if (!Cache::has('processed_logs') || (count($this->processed_logs) != $count_processed_logs)) {
            $processed_logs = AnalyticsLogMigration::all();
            if ($processed_logs) {
                $this->processed_logs = $processed_logs->lists("log_name", "id");
                Cache::add('processed_logs', $this->processed_logs, 1440);
            }
        }

        $tenants       = AnalyticsTenant::all();
        $this->tenants = $tenants->lists("tenant", "id");
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function fire()
    {
        $log_json_files_path             = \File::files(storage_path('logs/extract'));
        $counter_total_actions_each_file = 0;
        $counter                         = 1;
        $start_date                      = $this->option('start_date');
        $end_date                        = $this->option('end_date');

        $dates         = Helper::getListDateRange($start_date, $end_date);
        $counter_dates = 0;
        $length_dates  = count($dates);

        foreach ($log_json_files_path as $log_json_path) {

            $start_time    = new Carbon();
            $str_date      = $file_name     = "";
            $arr_file_path = explode('/', trim($log_json_path));

            if (is_array($arr_file_path))
                $file_name = end($arr_file_path);

            if ($file_name !== "") {
                $arr_filename = explode('.', $file_name);
                if (in_array($file_name, $this->processed_logs) || in_array($file_name, $this->processing_logs)) {
                    $this->info("{$file_name} has been processed or currently processing");
                    continue;
                }
            }

            if (is_array($arr_filename) && isset($arr_filename[1]))
                $str_date = $arr_filename[1];

            if ($str_date !== "") {

                $date                    = date_create_from_format('Y-m-d-H', $str_date);
                $full_str_date_formatted = $date->format('Y-m-d H:i:s');
                $str_date_formatted      = $date->format('Y-m-d');

                if (!in_array($str_date_formatted, $dates)) { //current file is not within date range//then continue
                    $this->error("{$file_name} is not within date range");
                    continue;
                }
                else
                    $counter_dates +=1;

                $tenant_actions_stats = $this->assignTenantActionIndexes($full_str_date_formatted);

                $tenant_sales_stats              = $this->assignTenantSaleIndexes();
                $file                            = \File::get($log_json_path);
                $counter_total_actions_each_file = 0;

                if ($file) {

                    $arr_json = json_decode($file, true);

                    if (!is_array($arr_json) || count($arr_json) <= 0)
                        continue;

                    foreach ($arr_json as $row) {
                        $tenant = trim(array_get($row, 'deserialized_query.tenant_id', ''));

                        if ($tenant !== "") {

                            //access_log
                            $date = array_get($row, 'access_log.date', '');
                            $time = array_get($row, 'access_log.time', '');

                            //deserialized_query
                            $action          = trim(array_get($row, 'deserialized_query.action.name', ''));
                            $user            = array_get($row, "deserialized_query.user");
                            $items           = array_get($row, "deserialized_query.items", []);
                            $session_id      = array_get($row, 'deserialized_query.session_id', '');
                            $session_user_id = array_get($row, 'deserialized_query.user_id', '');
                            $user_id         = (isset($user) && count($user) > 0 && isset($user['id'])) ? $user['id'] : $session_user_id;

                            if ($action !== "") {

                                if (isset($tenant_actions_stats[$tenant]) && isset($tenant_actions_stats[$tenant][$action])) {
                                    $is_rec = array_get($row, 'deserialized_query.action.rec', false);

                                    if ($is_rec)
                                        $tenant_actions_stats[$tenant][$action]['recommendation_stats'] +=1;
                                    else
                                        $tenant_actions_stats[$tenant][$action]['regular_stats'] +=1;

                                    //Sales
                                    if ($action === "buy") {
                                        $unique_id = \Str::random(10);
                                        $buy_rows  = $this->getBuyRows($tenant, $items, $user_id, $session_id, $date . ' ' . $time, $unique_id);
                                        if (count($buy_rows) > 0)
                                            foreach ($buy_rows as $buy_row) {
                                                $this->info("Item id: {$buy_row['item_id']} at {$date} {$time}");
                                                array_push($tenant_sales_stats[$tenant], $buy_row);
                                            }
                                    }
                                    else
                                        $this->info("({$action}) at {$date} {$time}");
                                }
                                $counter_total_actions_each_file+=1;
                            }
                        }

                        $counter+=1;
                    }

                    $arr_json = null;
                    $file     = null;

                    AnalyticsTenantRepository::saveActionsStatsCollection($tenant_actions_stats);
                    AnalyticsTenantRepository::saveSalesStatsCollection($tenant_sales_stats);

                    array_push($this->processing_logs, $file_name);
                    array_push($this->processing_detail_logs, [
                        'log_name'       => $file_name,
                        'log_created_at' => $full_str_date_formatted,
                        'created_at'     => $start_time,
                        'updated_at'     => new Carbon(),
                        'total_actions'  => $counter_total_actions_each_file,
                        'batch'          => $this->batch
                    ]);
                }
            }

            if (count($this->processing_detail_logs) > 0) { //save after 50
                //this should be an event
                AnalyticsLogMigration::insert($this->processing_detail_logs);
                $this->processing_detail_logs = [];
            }
//            File::delete($log_json_path);
        }

        if (count($this->processing_detail_logs) > 0) { //save after 50
            //this should be an event
            AnalyticsLogMigration::insert($this->processing_detail_logs);
            $this->processing_detail_logs = [];
        }

        $processed_logs = AnalyticsLogMigration::all();
        if ($processed_logs) {
            $this->processed_logs = $processed_logs->lists("log_name", "id");
            Cache::add('processed_logs', $this->processed_logs, 1440);
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
        return array(
            array('start_date', null, InputOption::VALUE_REQUIRED, 'The start date of the log', null),
            array('end_date', null, InputOption::VALUE_REQUIRED, 'The end date of the log', null)
        );
    }

    private function assignTenantActionIndexes($str_date_formated)
    {
        $tenant_actions_stats = [];
        $cache_actions        = null;
        $cache_tenants        = null;

        if (Cache::has('actions')) {
            $cache_actions = Cache::get('actions');
        }

        if (Cache::has('tenants')) {
            $cache_tenants = Cache::get('tenants');
        }

        if (is_null($cache_tenants) || count($cache_tenants) <= 0) {
            $tenants       = AnalyticsTenant::all();
            $cache_tenants = $tenants->toArray();
            Cache::add('tenants', $cache_tenants, 1440);
        }

        if (is_null($cache_actions) || count($cache_actions) <= 0) {
            $actions       = AnalyticsAction::all();
            $cache_actions = $actions->toArray();
            Cache::add('actions', $cache_actions, 1440);
        }

        //assign tenants
        foreach ($cache_tenants as $tenant) {
            $tenant_actions_stats[$tenant['tenant']] = [];

            //assign actions
            foreach ($cache_actions as $action) {
                $tenant_actions_stats[$tenant['tenant']][$action['name']] = [
                    'action_id'            => $action['id'],
                    'tenant_id'            => $tenant['id'],
                    'regular_stats'        => 0, //regular counter
                    'recommendation_stats' => 0, //recommendation counter
                    'created'              => $str_date_formated
                ];
            }
        }
        

        return $tenant_actions_stats;
    }

    private function assignTenantSaleIndexes()
    {
        $tenant_sales_stats = [];

        $cache_actions = null;
        $cache_tenants = null;

        if (Cache::has('actions')) {
            $cache_actions = Cache::get('actions');
        }

        if (is_null($cache_tenants)) {
            $tenants       = AnalyticsTenant::all();
            $cache_tenants = $tenants->toArray();
            Cache::add('tenants', $cache_tenants, 1440);
        }

        //assign tenants
        foreach ($cache_tenants as $tenant) {
            $tenant_sales_stats[$tenant['tenant']] = [];
        }

        return $tenant_sales_stats;
    }

    private function getBuyRows($tenant, $items, $user_id, $session_id, $str_date_formated, $unique_order_id)
    {
        $rows = [];
        try
        {
            foreach ($items as $item) {

                $tenant_id = array_search($tenant, $this->tenants);
                if (!$tenant_id)
                    continue;

                if (isset($item['item_id'])) {

                    $is_rec   = isset($item['rec']) ? $item['rec'] : false;
                    $buy_stat = [
                        'tenant_id'  => $tenant_id,
                        'action_id'  => 3, //buy
                        'user_id'    => $user_id,
                        'session_id' => $session_id,
                        'item_id'    => $item['item_id'],
                        'group_id'   => $unique_order_id,
                        'qty'        => isset($item['qty']) ? $item['qty'] : 1,
                        'sub_total'  => isset($item['sub_total']) ? $item['sub_total'] : 0,
                        'created'    => $str_date_formated,
                        'is_reco'    => ($is_rec) ? true : false
                    ];
                    array_push($rows, $buy_stat);
                }
            }
            return $rows;
        }
        catch (Exception $ex)
        {
            \Log::info($ex->getMessage());
        }

        return $rows;
    }

}
