<?php

namespace ZfGearmanManager\Worker;

use GearmanJob;

interface WorkerInterface
{
    /**
     * Run the job
     *
     * @param  GearmanJob $job
     * @param  array $log
     * @return boolean
     */
    public function run($job, &$log);
}
