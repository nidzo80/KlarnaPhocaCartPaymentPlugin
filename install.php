<?php
/**
 * @package    Plg_Pcp_Klarna
 * @license    GNU General Public License version 3 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Application\AdministratorApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Version;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            InstallerScriptInterface::class,
            new class ($container->get(AdministratorApplication::class)) implements InstallerScriptInterface {

                const MIN_JOOMLA_VERSION = '5.3';
                const MIN_PHP_VERSION    = '8.1';

                protected AdministratorApplication $app;

                public function __construct(AdministratorApplication $app)
                {
                    $this->app = $app;
                }

                public function install(InstallerAdapter $adapter): bool
                {
                    $this->enablePlugin($adapter);
                    return true;
                }

                public function update(InstallerAdapter $adapter): bool
                {
                    return true;
                }

                public function uninstall(InstallerAdapter $adapter): bool
                {
                    return true;
                }

                public function preflight(string $type, InstallerAdapter $adapter): bool
                {
                    return $this->checkCompatible();
                }

                public function postflight(string $type, InstallerAdapter $adapter): bool
                {
                    return true;
                }

                protected function checkCompatible(): bool
                {
                    if (!(new Version())->isCompatible(self::MIN_JOOMLA_VERSION)) {
                        $this->app->enqueueMessage(
                            'Klarna plugin requires Joomla ' . self::MIN_JOOMLA_VERSION . ' or newer.',
                            'error'
                        );
                        return false;
                    }

                    if (!(version_compare(PHP_VERSION, self::MIN_PHP_VERSION) >= 0)) {
                        $this->app->enqueueMessage(
                            'Klarna plugin requires PHP ' . self::MIN_PHP_VERSION . ' or newer.',
                            'error'
                        );
                        return false;
                    }

                    return true;
                }

                protected function enablePlugin(InstallerAdapter $adapter): void
                {
                    $plugin          = new \stdClass();
                    $plugin->type    = 'plugin';
                    $plugin->element = $adapter->getElement();
                    $plugin->folder  = (string) $adapter->getManifest()->attributes()['group'];
                    $plugin->enabled = 1;

                    Factory::getContainer()
                           ->get(DatabaseInterface::class)
                           ->updateObject('#__extensions', $plugin, ['type', 'element', 'folder']);
                }
            }
        );
    }
};
