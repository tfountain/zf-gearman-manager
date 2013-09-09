<?php

namespace ZfGearmanManager\Worker;

interface WorkerInterface
{
    public function run($data,&$log);
}
