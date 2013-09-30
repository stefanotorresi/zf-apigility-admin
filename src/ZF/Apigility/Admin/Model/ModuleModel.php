<?php

namespace ZF\Apigility\Admin\Model;

use ReflectionObject;
use Zend\Code\Generator\ValueGenerator;
use Zend\ModuleManager\ModuleManager;
use Zend\Stdlib\Glob;
use Zend\View\Model\ViewModel;
use Zend\View\Renderer\PhpRenderer;
use Zend\View\Resolver;
use ZF\Apigility\Admin\Exception;
use ZF\Apigility\ApigilityModuleInterface;

class ModuleModel
{
    /**
     * Services for each module
     * @var array
     */
    protected $services = array();

    /**
     * @var ModuleManager
     */
    protected $moduleManager;

    /**
     * @var array
     */
    protected $modules;

    /**
     * @var array
     */
    protected $restConfig;

    /**
     * @var array
     */
    protected $rpcConfig;

    /**
     * @var ValueGenerator
     */
    protected static $valueGenerator;

    /**
     * @param  ModuleManager $moduleManager
     * @param  array $restConfig
     * @param  array $rpcConfig
     */
    public function __construct(ModuleManager $moduleManager, array $restConfig, array $rpcConfig)
    {
        $this->moduleManager = $moduleManager;
        $this->restConfig    = array_keys($restConfig);
        $this->rpcConfig     = array_keys($rpcConfig);
    }

    /**
     * Export the $config array in a human readable format
     *
     * @param  array $config
     * @param  integer $space the initial indentation value
     * @return string
     */
    public static function exportConfig($config, $indent = 0)
    {
        if (empty(static::$valueGenerator)) {
            static::$valueGenerator = new ValueGenerator();
        }
        static::$valueGenerator->setValue($config);
        static::$valueGenerator->setArrayDepth($indent);

        return static::$valueGenerator;
    }

    /**
     * Retrieve modules
     *
     * @return ModuleEntity[]
     */
    public function getModules()
    {
        $modules = $this->getEnabledModules();
        return array_values($modules);
    }

    /**
     * @param  string $moduleName
     * @return null|ModuleEntity
     */
    public function getModule($moduleName)
    {
        $moduleName = $this->normalizeModuleName($moduleName);
        $modules = $this->getEnabledModules();
        if (!array_key_exists($moduleName, $modules)) {
            return null;
        }

        return $modules[$moduleName];
    }

    /**
     * Create a module
     *
     * @param  string $module
     * @param  string $path
     * @param  integer $ver
     * @return boolean
     */
    public function createModule($module, $path = '.')
    {
        $modulePath = sprintf('%s/module/%s', $path, $module);
        if (file_exists($modulePath)) {
            return false;
        
        }
        mkdir("$modulePath/config", 0777, true);
        mkdir("$modulePath/view", 0777, true);
        mkdir("$modulePath/src/$module/V1/Rest", 0777, true);
        mkdir("$modulePath/src/$module/V1/Rpc", 0777, true);

        if (!file_put_contents("$modulePath/config/module.config.php", "<" . "?php\nreturn array(\n);")) {
            return false;
        }

        $view = new ViewModel(array(
            'module'  => $module
        ));

        $resolver = new Resolver\TemplateMapResolver(array(
            'module/skeleton' => __DIR__ . '/../../../../../view/module/skeleton.phtml'
        ));

        $view->setTemplate('module/skeleton');
        $renderer = new PhpRenderer();
        $renderer->setResolver($resolver);

        if (!file_put_contents("$modulePath/Module.php", "<" . "?php\nrequire __DIR__ . '/src/$module/Module.php';")) {
            return false;
        }
        if (!file_put_contents("$modulePath/src/$module/Module.php", "<" . "?php\n" . $renderer->render($view))) {
            return false;
        }

        // Add the module in application.config.php
        $application = require "$path/config/application.config.php";
        if (isset($application['modules']) && !in_array($module, $application['modules'])) {
            $application['modules'][] = $module;
            copy ("$path/config/application.config.php", "$path/config/application.config.old");
            $content = <<<EOD
<?php
/**
 * Configuration file generated by ZF Apigility Admin
 *
 * The previous config file has been stored in application.config.old
 */

EOD;

            $content .= 'return '. self::exportConfig($application) . ";\n";
            if (!file_put_contents("$path/config/application.config.php", $content)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Update a module (adding the ApigilityModule interface)
     *
     * @param  string $module
     * @return boolean
     */
    public function updateModule($module)
    {
        $modules = $this->moduleManager->getLoadedModules();

        if (!isset($modules[$module])) {
            return false;
        }

        if ($modules[$module] instanceof ApigilityModuleInterface) {
            return false;
        }

        $objModule = new ReflectionObject($modules[$module]);
        $content   = file_get_contents($objModule->getFileName());

        $replacement = preg_replace(
            '/' . "\n" . 'class\s([a-z_\x7f-\xff][a-z0-9_\x7f-\xff]*)\s{/i',
            "use ZF\Apigility\ApigilityModuleInterface;\n\nclass $1 implements ApigilityModuleInterface\n{",
            $content
        );

        if ($replacement === $content) {
            $replacement = preg_replace(
                '/implements\s/',
                'implements ZF\Apigility\ApigilityModuleInterface,',
                $content
            );
        }

        copy($objModule->getFileName(), $objModule->getFileName() . '.old');
        if (!file_put_contents($objModule->getFileName(), $replacement)) {
            return false;
        }

        return true;
    }

    /**
     * Returns list of all API-First-enabled modules
     *
     * @return array
     */
    protected function getEnabledModules()
    {
        if (is_array($this->modules)) {
            return $this->modules;
        }

        $this->modules = array();
        foreach ($this->moduleManager->getLoadedModules() as $moduleName => $module) {
            if (!$module instanceof ApigilityModuleInterface) {
                continue;
            }

            $services = $this->getServicesByModule($moduleName);
            $versions = $this->getVersionsByModule($moduleName, $module);
            $entity   = new ModuleEntity($moduleName, $services['rest'], $services['rpc']);
            $entity->exchangeArray(array(
                'versions' => $versions,
            ));

            $this->modules[$entity->getName()] = $entity;
        }

        return $this->modules;
    }

    /**
     * Retrieve all services for a given module
     *
     * Returns null if the module is not API-enabled.
     *
     * Returns an array with the elements "rest" and "rpc" on success, with
     * each being an array of controller service names.
     *
     * @param  string $module
     * @return null|array
     */
    protected function getServicesByModule($module)
    {
        $services = array(
            'rest' => $this->discoverServicesByModule($module, $this->restConfig),
            'rpc'  => $this->discoverServicesByModule($module, $this->rpcConfig),
        );
        return $services;
    }

    /**
     * Retrieve versions by module
     *
     * Checks each REST and RPC service name for a 
     * version subnamespace; if found, that version 
     * is added to the list.
     * 
     * @param  string $moduleName 
     * @param  array $services 
     * @return array
     */
    protected function getVersionsByModule($moduleName, ApigilityModuleInterface $module)
    {
        $r        = new ReflectionObject($module);
        $path     = dirname($r->getFileName());
        $dirSep   = sprintf('(?:%s|%s)', preg_quote('/'), preg_quote('\\'));
        $pattern  = sprintf(
            '#%s%s%ssrc%s%s#',
            $dirSep,
            $moduleName,
            $dirSep,
            $dirSep,
            $moduleName
        );
        if (!preg_match($pattern, $path)) {
            $path = sprintf('%s/src/%s', $path, $moduleName);
        }
        if (!file_exists($path)) {
            return array();
        }

        $versions  = array();
        foreach (Glob::glob($path . DIRECTORY_SEPARATOR . 'V*') as $dir) {
            if (preg_match('/\\V(?P<version>\d+)$/', $dir, $matches)) {
                $versions[] = (int) $matches['version'];
            }
        }
        sort($versions);
        return $versions;
    }

    /**
     * Loops through an array of controllers, determining which match the given module.
     *
     * @param  string $module
     * @param  array $config
     * @return array
     */
    protected function discoverServicesByModule($module, array $config)
    {
        $services = array();
        foreach ($config as $controller) {
            if (strpos($controller, $module) === 0) {
                $services[] = $controller;
            }
        }
        return $services;
    }

    /**
     * @param  string $name
     * @return string
     */
    protected function normalizeModuleName($name)
    {
        return str_replace('\\', '.', $name);
    }
}