<?php

namespace ZfGearmanManager;

use GearmanManager\Bridge\GearmanPeclManager;
use Zend\ServiceManager\ServiceLocatorAwareInterface;
use Zend\ServiceManager\ServiceLocatorInterface;
use ZfGearmanManager\Worker\WorkerInterface;

class ZfGearmanPeclManager extends GearmanPeclManager implements ServiceLocatorAwareInterface
{
    /**
     * Service Locator
     *
     * @var ServiceLocatorInterface
     */
    protected $sm;

    /**
     * Overrides GearmanManager's constructor to remove all of the
     * 'start up' functionality (which is now in the start() method)
     *
     * This is mainly to allow other depdendencies to be passed to
     * the instance (i.e. in the service locator) before it starts
     * doing it's stuff
     */
    public function __construct()
    {
        if(!function_exists("posix_kill")){
            $this->show_help("The function posix_kill was not found. Please ensure POSIX functions are installed");
        }

        if(!function_exists("pcntl_fork")){
            $this->show_help("The function pcntl_fork was not found. Please ensure Process Control functions are installed");
        }
    }

    /**
     * Starts up the manager
     *
     * @return void
     */
    public function start()
    {
        $this->pid = getmypid();

        // Parse command line options. Loads the config file as well
        $this->getopt();

        // Register signal listeners
        $this->register_ticks();

        // Load up the workers
        $this->load_workers();

        if (empty($this->functions)){
            $this->log("No workers found");
            posix_kill($this->pid, SIGUSR1);
            exit();
        }

        // Validate workers in the helper process
        $this->fork_me("validate_workers");

        $this->log("Started with pid $this->pid", self::LOG_LEVEL_PROC_INFO);

        // Start the initial workers and set up a running environment
        $this->bootstrap();

        $this->process_loop();

        // Kill the helper if it is running
        if (isset($this->helper_pid)){
            posix_kill($this->helper_pid, SIGKILL);
        }

        $this->log("Exiting");
    }

    /**
     * Set service locator
     *
     * @param ServiceLocatorInterface $serviceLocator
     */
    public function setServiceLocator(ServiceLocatorInterface $serviceLocator)
    {
        $this->sm = $serviceLocator;

        return $this;
    }

    /**
     * Get service locator
     *
     * @return ServiceLocatorInterface
     */
    public function getServiceLocator()
    {
        return $this->sm;
    }

    /**
     * Helper function to load and filter worker files
     *
     * @return void
     */
    protected function load_workers()
    {
        $config = $this->getServiceLocator()->get('Config');
        if (!isset($config['gearman_workers']) || empty($config['gearman_workers'])) {
            return;
        }

        $workers = $config['gearman_workers'];

        $this->log('Loading '.count($workers) .' worker(s) from config');

        $this->functions = array();

        foreach ($workers as $function => $workerFqcn) {

            // TODO include/exclude functionality from GearmanManager

            if (!isset($this->functions[$function])){
                $this->functions[$function] = array();
            }

            if (!empty($this->config['functions'][$function]['dedicated_only'])){
                if(empty($this->config['functions'][$function]['dedicated_count'])){
                    $this->log("Invalid configuration for dedicated_count for function $function.", self::LOG_LEVEL_PROC_INFO);
                    exit();
                }

                $this->functions[$function]['dedicated_only'] = true;
                $this->functions[$function]["count"] = $this->config['functions'][$function]['dedicated_count'];

            } else {

                $min_count = max($this->do_all_count, 1);
                if(!empty($this->config['functions'][$function]['count'])){
                    $min_count = max($this->config['functions'][$function]['count'], $this->do_all_count);
                }

                if(!empty($this->config['functions'][$function]['dedicated_count'])){
                    $ded_count = $this->do_all_count + $this->config['functions'][$function]['dedicated_count'];
                } elseif(!empty($this->config["dedicated_count"])){
                    $ded_count = $this->do_all_count + $this->config["dedicated_count"];
                } else {
                    $ded_count = $min_count;
                }

                $this->functions[$function]["count"] = max($min_count, $ded_count);

            }

            /**
             * Note about priority. This exploits an undocumented feature
             * of the gearman daemon. This will only work as long as the
             * current behavior of the daemon remains the same. It is not
             * a defined part fo the protocol.
             */
            if(!empty($this->config['functions'][$function]['priority'])){
                $priority = max(min(
                    $this->config['functions'][$function]['priority'],
                    self::MAX_PRIORITY), self::MIN_PRIORITY);
            } else {
                $priority = 0;
            }

            $this->functions[$function]['priority'] = $priority;
        }
    }

    /**
     * Starts a worker for the PECL library
     *
     * Overrides the function from the parent class to remove the error suppression
     * from worker calls
     *
     * @param   array   $worker_list    List of worker functions to add
     * @param   array   $timeouts       list of worker timeouts to pass to server
     * @return  void
     *
     */
    protected function start_lib_worker($worker_list, $timeouts = array()) {

        $thisWorker = new \GearmanWorker();

        $thisWorker->addOptions(GEARMAN_WORKER_NON_BLOCKING);

        $thisWorker->setTimeout(5000);

        foreach($this->servers as $s){
            $this->log("Adding server $s", self::LOG_LEVEL_WORKER_INFO);
            $thisWorker->addServers($s);
        }

        foreach($worker_list as $w){
            $timeout = (isset($timeouts[$w]) ? $timeouts[$w] : null);
            $message = "Adding job $w";
            if($timeout){
                $message.= "; timeout: $timeout";
            }
            $this->log($message, self::LOG_LEVEL_WORKER_INFO);
            $thisWorker->addFunction($w, array($this, "do_job"), $this, $timeout);
        }

        $start = time();

        while(!$this->stop_work){

            if($thisWorker->work() ||
               $thisWorker->returnCode() == GEARMAN_IO_WAIT ||
               $thisWorker->returnCode() == GEARMAN_NO_JOBS) {

                if ($thisWorker->returnCode() == GEARMAN_SUCCESS) continue;

                if (!@$thisWorker->wait()){
                    if ($thisWorker->returnCode() == GEARMAN_NO_ACTIVE_FDS){
                        sleep(5);
                    }
                }

            }

            /**
             * Check the running time of the current child. If it has
             * been too long, stop working.
             */
            if($this->max_run_time > 0 && time() - $start > $this->max_run_time) {
                $this->log("Been running too long, exiting", self::LOG_LEVEL_WORKER_INFO);
                $this->stop_work = true;
            }

            if(!empty($this->config["max_runs_per_worker"]) && $this->job_execution_count >= $this->config["max_runs_per_worker"]) {
                $this->log("Ran $this->job_execution_count jobs which is over the maximum({$this->config['max_runs_per_worker']}), exiting", self::LOG_LEVEL_WORKER_INFO);
                $this->stop_work = true;
            }

        }

        $thisWorker->unregisterAll();


    }

    /**
     * Wrapper function handler for all registered functions
     * This allows us to do some nice logging when jobs are started/finished
     */
    public function do_job($job) {

        static $objects;

        if($objects===null) $objects = array();

        $w = $job->workload();

        $h = $job->handle();

        $job_name = $job->functionName();

        if($this->prefix){
            $func = $this->prefix.$job_name;
        } else {
            $func = $job_name;
        }

        $fqcn = $this->getWorkerFqcn($job_name);
        if ($fqcn) {
            $this->log("Creating a $func object", self::LOG_LEVEL_WORKER_INFO);
            $objects[$job_name] = $this->getServiceLocator()->get($fqcn);

            if (!$objects[$job_name] || !is_object($objects[$job_name])) {
                $this->log("Invalid worker class registered for $job_name (not an object?)");
                return;
            }

            if (!($objects[$job_name] instanceof WorkerInterface)) {
                $this->log("Worker class ".get_class($objects[$job_name])." registered for $job_name must implement ZfGearmanManager\Worker\WorkerInterface");
                return;
            }

        } else {
            $this->log("Function $func not found");
            return;
        }

        $this->log("($h) Starting Job: $job_name", self::LOG_LEVEL_WORKER_INFO);

        $this->log("($h) Workload: $w", self::LOG_LEVEL_DEBUG);

        $log = array();

        /**
         * Run the real function here
         */
        if(isset($objects[$job_name])){
            $this->log("($h) Calling object for $job_name.", self::LOG_LEVEL_DEBUG);
            $result = $objects[$job_name]->run($job, $log);
            unset($objects[$job_name]);

        } elseif(function_exists($func)) {
            $this->log("($h) Calling function for $job_name.", self::LOG_LEVEL_DEBUG);
            $result = $func($job, $log);
        } else {
            $this->log("($h) FAILED to find a function or class for $job_name.", self::LOG_LEVEL_INFO);
        }

        if(!empty($log)){
            foreach($log as $l){

                if(!is_scalar($l)){
                    $l = explode("\n", trim(print_r($l, true)));
                } elseif(strlen($l) > 256){
                    $l = substr($l, 0, 256)."...(truncated)";
                }

                if(is_array($l)){
                    foreach($l as $ln){
                        $this->log("($h) $ln", self::LOG_LEVEL_WORKER_INFO);
                    }
                } else {
                    $this->log("($h) $l", self::LOG_LEVEL_WORKER_INFO);
                }

            }
        }

        $result_log = $result;

        if(!is_scalar($result_log)){
            $result_log = explode("\n", trim(print_r($result_log, true)));
        } elseif(strlen($result_log) > 256){
            $result_log = substr($result_log, 0, 256)."...(truncated)";
        }

        if(is_array($result_log)){
            foreach($result_log as $ln){
                $this->log("($h) $ln", self::LOG_LEVEL_DEBUG);
            }
        } else {
            $this->log("($h) $result_log", self::LOG_LEVEL_DEBUG);
        }

        /**
         * Workaround for PECL bug #17114
         * http://pecl.php.net/bugs/bug.php?id=17114
         */
        $type = gettype($result);
        settype($result, $type);


        $this->job_execution_count++;

        return $result;

    }

    /**
     * Returns the fully qualified class name (from the module config)
     * for $func, or false if it doesn't exist
     *
     * @param  string $func
     * @return string|false
     */
    protected function getWorkerFqcn($func)
    {
        $config = $this->getServiceLocator()->get('Config');
        if (!isset($config['gearman_workers']) || !isset($config['gearman_workers'][$func])) {
            return false;
        }

        return $config['gearman_workers'][$func];
    }

    /**
     * Validates the PECL compatible worker files/functions
     */
    protected function validate_lib_workers()
    {
        foreach ($this->functions as $func => $props){
            $real_func = $this->prefix.$func;

            if (!$this->getWorkerFqcn($real_func)) {
                $this->log("Function $real_func not found");
                posix_kill($this->pid, SIGUSR2);
                exit();
            }
        }
    }
}
