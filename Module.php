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
                    $config = $sm->get('Config');
                    if(isset($config['gearman_client'])){
                        $conf = $config['gearman_client'];
                    }
                    $host = isset($conf['host']) ? $conf['host'] : '127.0.0.1';
                    $port = isset($conf['port']) ? $conf['port'] : 4730;

                    // add default server (localhost)
                    $gmClient->addServer($host, $port);

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
