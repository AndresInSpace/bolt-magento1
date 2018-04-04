<?php
/**
 * Magento
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magento.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade the Bolt extension
 * to a newer versions in the future. If you wish to customize this extension
 * for your needs please refer to http://www.magento.com for more information.
 *
 * @category   Bolt
 * @package    Bolt_Boltpay
 * @copyright  Copyright (c) 2018 Bolt Financial, Inc (http://www.bolt.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

/**
 * Class Bolt_Boltpay_Model_Shippingtaxrateprovider_Taxjar
 *
 * The Magento model that gets the TaxJar sales tax rate for a given quote address:
 *
 * 1. Getting tax rates
 */
class Bolt_Boltpay_Model_Shippingtaxrateprovider_Taxjar extends Bolt_Boltpay_Model_Shippingtaxrateprovider_Abstract {
    public function getRate() {
        $smartCalculator = $this->getSmartCalculator();

        $smartCalculatorResponse = $smartCalculator->getResponse();

        if ($smartCalculatorResponse['status'] == 200
            && isset($smartCalculatorResponse['body']['tax']['rate'])
            && isset($smartCalculatorResponse['body']['tax']['freight_taxable'])) {
            if($smartCalculatorResponse['body']['tax']['freight_taxable'] == true) {
                return $smartCalculatorResponse['body']['tax']['rate'] * 100;
            }
        } else {
            Mage::throwException("Bolt_Boltpay_Model_Shippingtaxrateprovider_Taxjar::getRate returned invalid tax rate.");
        }

        return 0;
    }

    protected function getSmartCalculator()
    {
        return Mage::getModel(
            'taxjar/smartcalcs',
            array('address' => $this->getQuote()->getShippingAddress())
        );
    }
}