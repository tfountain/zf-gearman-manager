<?php

namespace ZfGearmanManager;

use GearmanManager\Bridge\GearmanPeclManager;
use Zend\ServiceManager\ServiceLocatorAwareInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

class ZfGearmanPeclManager extends GearmanPeclManager implements ServiceLocatorAwareInterface
{
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

        $this->log('Loading '.count($workers) .' from config');

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
     * Initialiases the worker function
     *
     * Overrides the base class version of this method in order to use
     * the service locator to instantiate the worker class
     *
     * @param  string $func
     * @return object|false
     */
    protected function init_func($func)
    {
        $fqcn = $this->getWorkerFqcn($func);
        if (!$fqcn) {
            return false;
        }

        $worker = $this->getServiceLocator()->get($fqcn);
        if ($worker) {
            $this->log("Creating a $func object", self::LOG_LEVEL_WORKER_INFO);
            return $worker;
        }

        $this->log("Function $func not found");

        return false;
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
