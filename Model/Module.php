<?php
/**
 * Copyright © MagePal LLC. All rights reserved.
 * See COPYING.txt for license details.
 * http://www.magepal.com | support@magepal.com
 */

namespace MagePal\Core\Model;

use Exception;
use InvalidArgumentException;
use Magento\Framework\App\Cache\Type\Config;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\Filesystem\Directory\ReadFactory;
use Magento\Framework\HTTP\ClientFactory;
use Magento\Framework\Module\ModuleListInterface;

class Module
{
    const URL = "https://updates.magepal.com/extensions.json";
    const CACHE_KEY = 'magepal_extension_installed_list';
    const DATA_VERSION = '1.0.1';
    const LIFE_TIME = 604800;

    /** @var int $updateCounter */
    protected $updateCounter = 0;

    /** @var string[] $ignoreList */
    private $ignoreList = [
        'MagePal_Core'
    ];

    private $filterModule = 'MagePal_';

    private $composerJsonData = [];

    private $myExtensionList = [];

    private $outDatedExtensionList = [];

    /**
     * @var ModuleListInterface
     */
    private $moduleList;
    /**
     * @var ComponentRegistrarInterface
     */
    private $componentRegistrar;
    /**
     * @var ReadFactory
     */
    private $readFactory;
    /**
     * @var ClientFactory
     */
    private $httpClientFactory;
    /**
     * @var Config
     */
    private $cache;

    /**
     * @param ModuleListInterface $moduleList
     * @param ComponentRegistrarInterface $componentRegistrar
     * @param ClientFactory $httpClientFactory
     * @param ReadFactory $readFactory
     * @param Config $cache
     */
    public function __construct(
        ModuleListInterface $moduleList,
        ComponentRegistrarInterface $componentRegistrar,
        ClientFactory $httpClientFactory,
        ReadFactory $readFactory,
        Config $cache
    ) {
        $this->moduleList  = $moduleList;
        $this->componentRegistrar = $componentRegistrar;
        $this->readFactory = $readFactory;
        $this->httpClientFactory = $httpClientFactory;
        $this->cache = $cache;
    }

    public function getUpdateCount()
    {
        $this->getOutDatedExtension();
        return $this->updateCounter;
    }

    /**
     * @return array
     */
    public function getOutDatedExtension()
    {
        if (empty($this->outDatedExtensionList)) {
            if (!$data = $this->cache->load(self::CACHE_KEY)) {
                $this->loadOutDatedExtension();
            } else {
                $dataObject = $this->decodeJson($data, true);

                if (!array_key_exists('data_version', $dataObject)
                    || $dataObject['data_version'] != self::DATA_VERSION) {
                    $this->loadOutDatedExtension();
                } else {
                    if (array_key_exists('count', $dataObject)) {
                        $this->updateCounter = $dataObject['count'];
                    }

                    if (array_key_exists('extensions', $dataObject)) {
                        $this->outDatedExtensionList = $dataObject['extensions'];
                    }
                }
            }
        }

        return $this->outDatedExtensionList;
    }

    public function loadOutDatedExtension()
    {
        try {
            $extensionList = $this->getMyExtensionList();
            $feed =  $this->callApi(self::URL, $this->getPostData());
            $latestVersions = $feed['extensions'] ?? [];

            foreach ($extensionList as $item) {
                $item['latest_version'] = $item['install_version'];
                $item['has_update'] = false;
                $item['url'] = 'https://www.magepal.com/extensions.com';
                $item['name'] = $this->getTitleFromModuleName($item['moduleName']);

                if (array_key_exists($item['composer_name'], $latestVersions)) {
                    $latest = $latestVersions[$item['composer_name']];
                    $item['latest_version'] = $latest['latest_version'];
                    $item['has_update'] = version_compare($item['latest_version'], $item['install_version']) > 0;
                    $item['url'] = $latest['url'];
                    $item['name'] = $latest['name'] ?? $item['name'];

                    if ($item['has_update']) {
                        $this->updateCounter += 1;
                    }
                }

                $this->outDatedExtensionList[] = $item;
            }

            $dataObject = [
                'count' => $this->updateCounter,
                'extensions' => $this->outDatedExtensionList,
                'data_version' => self::DATA_VERSION
            ];

            $this->cache->save(json_encode($dataObject), self::CACHE_KEY, [], self::LIFE_TIME);
        } catch (Exception $e) {
            $this->outDatedExtensionList = [];
        }

        return $this->outDatedExtensionList;
    }

    private function getTitleFromModuleName($moduleName)
    {
        $moduleName = str_replace($this->filterModule, '', $moduleName);
        return join(preg_split(
            '/(?<=[a-z])(?=[A-Z])/x',
            $moduleName
        ), " ");
    }

    /**
     * @return array
     */
    public function getPostData()
    {
        $result = [];
        foreach ($this->getMyExtensionList() as $key => $value) {
            $result[$key] = $value['install_version'] ?? '0.0.0';
        }

        return $result;
    }

    /**
     * @return array
     */
    public function getMyExtensionList()
    {
        if (empty($this->myExtensionList)) {
            foreach ($this->moduleList->getNames() as $name) {
                if (strpos($name, $this->filterModule) !== false && !in_array($name, $this->ignoreList)) {
                    $composerName = $this->getInstalledComposerName($name);
                    if ($composerName) {
                        $this->myExtensionList[$composerName] = [
                            'moduleName' => $name,
                            'composer_name' => $composerName,
                            'install_version' => $this->getInstalledVersion($name),
                        ];
                    }
                }
            }
        }

        return $this->myExtensionList;
    }

    /**
     * @param $moduleName
     * @param bool $assoc
     * @return mixed
     */
    public function getLocalComposerData($moduleName, $assoc = false)
    {
        if (!array_key_exists($moduleName, $this->composerJsonData)) {
            $path = $this->componentRegistrar->getPath(
                ComponentRegistrar::MODULE,
                $moduleName
            );

            try {
                $directoryRead = $this->readFactory->create($path);
                $composerJsonData = $directoryRead->readFile('composer.json');
                $this->composerJsonData[$moduleName] = $this->decodeJson($composerJsonData, $assoc);
            } catch (Exception $e) {
                $this->composerJsonData[$moduleName] = [];
            }
        }

        return  $this->composerJsonData[$moduleName];
    }

    /**
     * @param $moduleName
     * @return mixed|string
     */
    public function getInstalledVersion($moduleName)
    {
        $version = '0.0.0';
        if ($data = $this->getLocalComposerData($moduleName, true)) {
            $version = $data['version'] ?? $version;
        }

        return $version;
    }

    /**
     * @param $moduleName
     * @return mixed|string
     */
    public function getInstalledComposerName($moduleName)
    {
        $name = '';
        if ($data = $this->getLocalComposerData($moduleName, true)) {
            $name = $data['name'] ?? '';
        }

        return $name;
    }

    /**
     * @param $data
     * @param $assoc
     * @return array|object
     * @throws InvalidArgumentException
     */
    public function decodeJson($data, $assoc = false)
    {
        $result = json_decode($data, $assoc);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException("Unable to unserialize value. Error: " . json_last_error_msg());
        }

        return $result;
    }

    /**
     * @param $url
     * @param $post
     * @return array
     */
    protected function callApi($url, $post)
    {
        $client = $this->httpClientFactory->create();
        $client->setOption(CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36');
        //$client->setOption(CURLOPT_RETURNTRANSFER, 1);
        $client->setOption(CURLOPT_FOLLOWLOCATION, 1);
        $client->get($url. "?" . http_build_query($post));

        try {
            $result = $this->decodeJson($client->getBody(), true);
        } catch (Exception $e) {
            $result = [];
        }

        return $result;
    }
}
