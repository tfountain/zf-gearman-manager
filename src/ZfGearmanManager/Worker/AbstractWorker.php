<?php

namespace ZfGearmanManager\Worker;

abstract class AbstractWorker implements WorkerInterface
{
    protected $sm;

    public function setServiceLocator($sm)
    {
        $this->sm = $sm;

        return $this;
    }

    public function getServiceLocator()
    {
        return $this->sm;
    }
}
