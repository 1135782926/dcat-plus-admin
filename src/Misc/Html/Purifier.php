<?php

namespace Dcat\Admin\Misc\Html;


use Exception;
use HTMLPurifier;
use HTMLPurifier_Config;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;

class Purifier
{

    /**
     * @var Filesystem
     */
    protected $files;

    /**
     * @var Repository
     */
    protected $config;

    /**
     * @var HTMLPurifier
     */
    protected $purifier;

    /**
     * Constructor
     *
     * @param Filesystem $files
     * @param Repository $config
     * @throws Exception
     */
    public function __construct(Filesystem $files, Repository $config)
    {
        $this->files = $files;
        $this->config = $config;

        $this->setUp();
    }

    public static function cleanHtml($dirty, $config = null)
    {
        static $instance = null;
        if (null === $instance) {
            $app = app();
            $defaultConfig = (include __DIR__ . '/config.php');
            $instance = new Purifier($app['files'], new \Illuminate\Config\Repository($defaultConfig));
        }
        return $instance->clean($dirty, $config);
    }

    /**
     * Setup
     *
     * @throws Exception
     */
    private function setUp()
    {
        $this->checkCacheDirectory();

        // Create a new configuration object
        $config = HTMLPurifier_Config::createDefault();

        // Allow configuration to be modified
        if (!$this->config->get('finalize')) {
            $config->autoFinalize = false;
        }

        $config->loadArray($this->getConfig());

        // Load custom definition if set
        if ($definitionConfig = $this->config->get('settings.custom_definition')) {
            $this->addCustomDefinition($definitionConfig, $config);
        }

        // Load custom elements if set
        if ($elements = $this->config->get('settings.custom_elements')) {
            if ($def = $config->maybeGetRawHTMLDefinition()) {
                $this->addCustomElements($elements, $def);
            }
        }

        // Load custom attributes if set
        if ($attributes = $this->config->get('settings.custom_attributes')) {
            if ($def = $config->maybeGetRawHTMLDefinition()) {
                $this->addCustomAttributes($attributes, $def);
            }
        }

        // Create HTMLPurifier object
        $this->purifier = new HTMLPurifier($this->configure($config));
    }

    /**
     * Add a custom definition
     *
     * @see http://htmlpurifier.org/docs/enduser-customize.html
     * @param array $definitionConfig
     * @param HTML_Purifier_Config $configObject Defaults to using default config
     *
     * @return HTML_Purifier_Config $configObject
     */
    private function addCustomDefinition(array $definitionConfig, $configObject = null)
    {
        if (!$configObject) {
            $configObject = HTMLPurifier_Config::createDefault();
            $configObject->loadArray($this->getConfig());
        }

        // Setup the custom definition
        $configObject->set('HTML.DefinitionID', $definitionConfig['id']);
        $configObject->set('HTML.DefinitionRev', $definitionConfig['rev']);

        // Enable debug mode
        if (!isset($definitionConfig['debug']) || $definitionConfig['debug']) {
            $configObject->set('Cache.DefinitionImpl', null);
        }

        // Start configuring the definition
        if ($def = $configObject->maybeGetRawHTMLDefinition()) {
            // Create the definition attributes
            if (!empty($definitionConfig['attributes'])) {
                $this->addCustomAttributes($definitionConfig['attributes'], $def);
            }

            // Create the definition elements
            if (!empty($definitionConfig['elements'])) {
                $this->addCustomElements($definitionConfig['elements'], $def);
            }
        }

        return $configObject;
    }

    /**
     * Add provided attributes to the provided definition
     *
     * @param array $attributes
     * @param HTMLPurifier_HTMLDefinition $definition
     *
     * @return HTMLPurifier_HTMLDefinition $definition
     */
    private function addCustomAttributes(array $attributes, $definition)
    {
        foreach ($attributes as $attribute) {
            // Get configuration of attribute
            $required = !empty($attribute[3]) ? true : false;
            $onElement = $attribute[0];
            $attrName = $required ? $attribute[1] . '*' : $attribute[1];
            $validValues = $attribute[2];

            $definition->addAttribute($onElement, $attrName, $validValues);
        }

        return $definition;
    }

    /**
     * Add provided elements to the provided definition
     *
     * @param array $elements
     * @param HTMLPurifier_HTMLDefinition $definition
     *
     * @return HTMLPurifier_HTMLDefinition $definition
     */
    private function addCustomElements(array $elements, $definition)
    {
        foreach ($elements as $element) {
            // Get configuration of element
            $name = $element[0];
            $contentSet = $element[1];
            $allowedChildren = $element[2];
            $attributeCollection = $element[3];
            $attributes = isset($element[4]) ? $element[4] : null;

            if (!empty($attributes)) {
                $definition->addElement($name, $contentSet, $allowedChildren, $attributeCollection, $attributes);
            } else {
                $definition->addElement($name, $contentSet, $allowedChildren, $attributeCollection);
            }
        }
    }

    /**
     * Check/Create cache directory
     */
    private function checkCacheDirectory()
    {
        $cachePath = $this->config->get('cachePath');

        if ($cachePath) {
            if (!$this->files->isDirectory($cachePath)) {
                $this->files->makeDirectory($cachePath, $this->config->get('cacheFileMode', 0755));
            }
        }
    }

    /**
     * @param HTMLPurifier_Config $config
     *
     * @return HTMLPurifier_Config
     */
    protected function configure(HTMLPurifier_Config $config)
    {
        return HTMLPurifier_Config::inherit($config);
    }

    /**
     * @param null $config
     *
     * @return mixed|null
     */
    protected function getConfig($config = null)
    {
        $default_config = [];
        $default_config['Core.Encoding'] = $this->config->get('encoding');
        $default_config['Cache.SerializerPath'] = $this->config->get('cachePath');
        $default_config['Cache.SerializerPermissions'] = $this->config->get('cacheFileMode', 0755);

        if (!$config) {
            $config = $this->config->get('settings.default');
        } elseif (is_string($config)) {
            $config = $this->config->get('settings.' . $config);
        }

        if (!is_array($config)) {
            $config = [];
        }

        $config = $default_config + $config;

        return $config;
    }

    /**
     * @param      $dirty
     * @param null $config
     *
     * @return mixed
     */
    public function clean($dirty, $config = null)
    {
        if (is_array($dirty)) {
            return array_map(function ($item) use ($config) {
                return $this->clean($item, $config);
            }, $dirty);
        }

        return $this->purifier->purify($dirty, $this->getConfig($config));
    }

    /**
     * Get HTMLPurifier instance.
     *
     * @return \HTMLPurifier
     */
    public function getInstance()
    {
        return $this->purifier;
    }
}
