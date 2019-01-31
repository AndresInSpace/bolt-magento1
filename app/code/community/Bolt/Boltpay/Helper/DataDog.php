<?php
/**
 * Bolt magento plugin
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * @category   Bolt
 * @package    Bolt_Boltpay
 * @copyright  Copyright (c) 2018 Bolt Financial, Inc (https://www.bolt.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

require_once(Mage::getBaseDir('lib') . DS .  'Boltpay/DataDog/Autoload.php');

class Bolt_Boltpay_Helper_DataDog extends Mage_Core_Helper_Abstract
{

    private $_apiKey;
    private $_severityConfig;
    private $_data = array();
    private $_datadog;

    public function __construct(){
        $this->_apiKey = $this->getApiKeyConfig();
        $this->_severityConfig=$this->getSeverityConfig();
        $this->_data['platform-version'] = 'Magento '.Mage::getVersion();
        $this->_data['bolt-plugin-version'] = static::getBoltPluginVersion();
    }

    /**
     * Function get DataDog
     * @return DataDog_Client
     */
    private function getDataDog()
    {
        if (!$this->_datadog) {
            $datadog = new DataDog_Client(
                $this->_apiKey,
                $this->_data['platform-version'],
                $this->_data['bolt-plugin-version']
            );

            if (isset($_SERVER['PHPUNIT_ENVIRONMENT']) && $_SERVER['PHPUNIT_ENVIRONMENT']) {
                $env = DataDog_Environment::TEST_ENVIRONMENT;
            } else {
                $env = Mage::getStoreConfig('payment/boltpay/test')
                    ? DataDog_Environment::DEVELOPMENT_ENVIRONMENT
                    : DataDog_Environment::PRODUCTION_ENVIRONMENT;
            }
            $datadog->setData('store_url',@Mage::getBaseUrl());
            $datadog->setData('env',$env);
            $this->_datadog = $datadog;
        }

        return $this->_datadog;
    }

    /**
     *
     * @param        $data
     * @param string $type
     *
     * @param array  $additionalData
     *
     * @return $this
     */
    public function log($data, $type = DataDog_ErrorTypes::TYPE_INFO, $additionalData = array())
    {
        if ($this->_apiKey && !in_array($type, $this->_severityConfig)){
            return $this;
        }

        $this->getDataDog()->log($data,$type,$additionalData);
        return $this;
    }

    /**
     * Log information
     *
     * @param $message
     *
     * @param array $additionalData
     * @return $this
     */
    public function logInfo($message, $additionalData = array())
    {
        if ($this->_apiKey && !in_array(DataDog_ErrorTypes::TYPE_INFO, $this->_severityConfig)){
            return $this;
        }
        $this->getDataDog()->logInfo($message,$additionalData);
        return $this;
    }

    /**
     * Log warning
     *
     * @param $message
     *
     * @param array $additionalData
     * @return $this
     */
    public function logWarning($message, $additionalData = array())
    {
        if ($this->_apiKey && !in_array(DataDog_ErrorTypes::TYPE_WARNING, $this->_severityConfig)){
            return $this;
        }

        $this->getDataDog()->logWarning($message,$additionalData);
        return $this;

    }

    /**
     * Log exception
     *
     * @param    Exception $e
     *
     * @param array $additionalData
     * @return $this
     */
    public function logError(Exception $e, $additionalData = array())
    {
        if ($this->_apiKey && !in_array(DataDog_ErrorTypes::TYPE_ERROR, $this->_severityConfig)){
            return $this;
        }

        $this->getDataDog()->logError($e,$additionalData);
        return $this;
    }


    /**
     * Set log information
     *
     * @param string $attribute
     * @param mixed  $value
     *
     * @return $this
     */
    public function setData($attribute, $value)
    {
        $this->_data[$attribute] = $value;
        return $this;
    }

    /**
     * Set Environment
     * @param $env
     * @return $this
     */
    public function setEnv($env)
    {
        $this->_data['env'] = $env;
        return $this;
    }

    /**
     * Set log service information
     *
     * @param $service
     *
     * @return $this
     */
    public function setService($service)
    {
        $this->_data['service'] = $service;
        return $this;
    }

    /**
     * Get log information
     *
     * @param $attribute
     *
     * @return mixed
     */
    public function getData($attribute)
    {
        return isset($this->_data[$attribute]) ? $this->_data[$attribute] : '';
    }

    /**
     * Get Api key configuration
     * @return string
     */
    public function getApiKeyConfig(){
        return Mage::getStoreConfig('payment/boltpay/datadog_key');
    }

    /**
     * Get severity configuration
     * @return array
     */
    public function getSeverityConfig(){
        $severityString = Mage::getStoreConfig('payment/boltpay/datadog_key_severity');
        $severities = explode(',',$severityString);
        return $severities;
    }

    /**
     * Get Bolt Plugin Version
     * @return string|null
     */
     protected static function getBoltPluginVersion() {
        $versionElm =  Mage::getConfig()->getModuleConfig("Bolt_Boltpay")->xpath("version");

        if(isset($versionElm[0])) {
            return (string)$versionElm[0];
        }

        return null;
    }

}
