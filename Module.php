<?php

namespace ZfGearmanManager;

class Module
{
    public function getAutoloaderConfig()
    {
        return array(
            'Zend\Loader\StandardAutoloader' => array(
                'namespaces' => array(
                    __NAMESPACE__ => __DIR__ . '/src/' . __NAMESPACE__,
                ),
            ),
        );
    }

    public function getServiceConfig()
    {
        return array(
            'factories' => array(
                'GearmanClient' => function ($sm) {
                    $gmClient = new \GearmanClient();

                    // add default server (localhost) - TODO populate this from config
                    $gmClient->addServer();

                    return $gmClient;
                },

                'ZfGearmanPeclManager' => function ($sm) {
                    $manager = new ZfGearmanPeclManager();
                    $manager->setServiceLocator($sm);

                    return $manager;
                }
            )
        );
    }
}
