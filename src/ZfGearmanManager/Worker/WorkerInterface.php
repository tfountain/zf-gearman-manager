<?php

namespace ZfGearmanManager\Worker;

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
