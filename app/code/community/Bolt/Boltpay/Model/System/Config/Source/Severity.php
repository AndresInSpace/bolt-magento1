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
require_once(Mage::getBaseDir('lib') . DS .  'Boltpay/DataDog/ErrorTypes.php');

class Bolt_Boltpay_Model_System_Config_Source_Severity
{
    public function toOptionArray()
    {
        return array(
            array('value' => DataDog_ErrorTypes::TYPE_ERROR, 'label' => Mage::helper('boltpay')->__('Error')),
            array('value' => DataDog_ErrorTypes::TYPE_WARNING, 'label' => Mage::helper('boltpay')->__('Warning')),
            array('value' => DataDog_ErrorTypes::TYPE_INFO, 'label' => Mage::helper('boltpay')->__('Info')),
        );
    }
}