<?php

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use Joomla\Plugin\System\Dogqnocaptcha\Extension\Dogqnocaptcha;

return new class () implements ServiceProviderInterface
{
    public function register(Container $container)
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $plugin = new Dogqnocaptcha(
                    $container->get(DispatcherInterface::class),
                    (array) PluginHelper::getPlugin('system', 'dogqnocaptcha')
                );
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};