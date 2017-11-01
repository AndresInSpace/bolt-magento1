<?php
file_put_contents('var/log/shipping_and_tax_new.log', date('Y-m-d H:i:s') . ": SHIPPING AND TAX REQUEST\n", FILE_APPEND);


if (version_compare(phpversion(), '5.2.0', '<')) {
    echo 'It looks like you have an invalid PHP version. Magento supports PHP 5.2.0 or newer';
    exit;
}

$magentoRootDir = getcwd();
$bootstrapFilename = $magentoRootDir . '/app/bootstrap.php';
$mageFilename = $magentoRootDir . '/app/Mage.php';

if (!file_exists($bootstrapFilename)) {
    echo 'Bootstrap file not found';
    exit;
}
if (!file_exists($mageFilename)) {
    echo 'Mage file not found';
    exit;
}
require $bootstrapFilename;
require $mageFilename;

if (!Mage::isInstalled()) {
    echo 'Application is not installed yet, please complete install wizard first.';
    exit;
}

if (isset($_SERVER['MAGE_IS_DEVELOPER_MODE'])) {
    Mage::setIsDeveloperMode(true);
}

#ini_set('display_errors', 1);

Mage::$headersSentThrowsException = false;
Mage::init('admin');
Mage::app()->loadAreaPart(Mage_Core_Model_App_Area::AREA_GLOBAL, Mage_Core_Model_App_Area::PART_EVENTS);
Mage::app()->loadAreaPart(Mage_Core_Model_App_Area::AREA_ADMINHTML, Mage_Core_Model_App_Area::PART_EVENTS);

try {

    $hmac_header = $_SERVER['HTTP_X_BOLT_HMAC_SHA256'];

    $request_json = file_get_contents('php://input');

    Mage::log('SHIPPING AND TAX REQUEST: ' . $request_json, null, 'shipping_and_tax.log');

    $boltHelper = Mage::helper('boltpay/api');

    if (! $boltHelper->verify_hook($request_json, $hmac_header)) exit;

    $request_data = json_decode($request_json);

    $shipping_address = $request_data->shipping_address;

    $region = Mage::getModel('directory/region')->loadByName($shipping_address->region, $shipping_address->country_code)->getCode();

    $address_data = array(
        'email'      => $shipping_address->email,
        'firstname'  => $shipping_address->first_name,
        'lastname'   => $shipping_address->last_name,
        'street'     => $shipping_address->street_address1 . ($shipping_address->street_address2 ? "\n" . $shipping_address->street_address2 : ''),
        'company'    => $shipping_address->company,
        'city'       => $shipping_address->locality,
        'region'     => $region,
        'postcode'   => $shipping_address->postal_code,
        'country_id' => $shipping_address->country_code,
        'telephone'  => $shipping_address->phone
    );

    $display_id = $request_data->cart->display_id;

    $quote = Mage::getModel('sales/quote')
        ->getCollection()
        ->addFieldToFilter('reserved_order_id', $display_id)
        ->getFirstItem();

    if ($quote->getCustomerId()) {

        $customer = Mage::getModel("customer/customer")->load($quote->getCustomerId());
        $address =$customer->getPrimaryShippingAddress();

        if (!$address) {
            $address = Mage::getModel('customer/address');

            $address->setCustomerId($customer->getId())
                ->setCustomer($customer)
                ->setIsDefaultShipping('1')
                ->setSaveInAddressBook('1')
                ->save();


            $address->addData($address_data);
            $address->save();

            $customer->addAddress($address)
                ->setDefaultShippingg($address->getId())
                ->save();
        }
    }

    $quote->getShippingAddress()->addData($address_data)->save();

    $billingAddress = $quote->getBillingAddress();

    $quote->getBillingAddress()->addData(array(
        'email'      => $billingAddress->getEmail()     ?: $shipping_address->email,
        'firstname'  => $billingAddress->getFirstname() ?: $shipping_address->first_name,
        'lastname'   => $billingAddress->getLastname()  ?: $shipping_address->last_name,
        'street'     => implode("\n", $billingAddress->getStreet()) ?: $shipping_address->street_address1 . ($shipping_address->street_address2 ? "\n" . $shipping_address->street_address2 : ''),
        'company'    => $billingAddress->getCompany()   ?: $shipping_address->company,
        'city'       => $billingAddress->getCity()      ?: $shipping_address->locality,
        'region'     => $billingAddress->getRegion()    ?: $region,
        'postcode'   => $billingAddress->getPostcode()  ?: $shipping_address->postal_code,
        'country_id' => $billingAddress->getCountryId() ?: $shipping_address->country_code,
        'telephone'  => $billingAddress->getTelephone() ?: $shipping_address->phone
    ))->save();

    $quote->getShippingAddress()->setCollectShippingRates(true)->collectShippingRates()->save();

    $response = array(
      'shipping_options' => array(),
    );

    /*****************************************************************************************
     * Calculate tax
     *****************************************************************************************/
    $store = Mage::getModel('core/store')->load($quote->getStoreId());
    $taxCalculationModel = Mage::getSingleton('tax/calculation');
    $shipping_tax_class_id = Mage::getStoreConfig('tax/classes/shipping_tax_class',$quote->getStoreId());
    $rate_request = $taxCalculationModel->getRateRequest($quote->getShippingAddress(), $quote->getBillingAddress(), $quote->getCustomerTaxClassId(), $store);

    $items = $quote->getAllItems();

    $items_price = 0;

    foreach($items as $item) {
        $items_price += $item->getPrice()*$item->getQty();
    }

    $applyTaxAfterDiscount = Mage::helper('tax')->applyTaxAfterDiscount();
    $totals = $quote->getTotals();

    if (@$totals['discount'] && $applyTaxAfterDiscount) {
        $items_price += $totals['discount']->getValue();
    }

    $total_tax = $items_price * $taxCalculationModel->getRate($rate_request->setProductClassId($item->getProduct()->getTaxClassId()));

    $tax_remain = $total_tax - round($total_tax);

    $total_tax = round($total_tax);

    $response['tax_result'] = array(
        "amount" => $total_tax
    );
    /*****************************************************************************************/


    /*****************************************************************************************
     * Calculate shipping and shipping tax
     *****************************************************************************************/
    $rates = $quote->getShippingAddress()->getAllShippingRates();

    foreach ($rates as $rate) {

      $price = $rate->getPrice();

      $is_tax_included = Mage::helper('tax')->shippingPriceIncludesTax();

      $tax_rate = $taxCalculationModel->getRate($rate_request->setProductClassId($shipping_tax_class_id));

      if ($is_tax_included) {

          $price_excluding_tax = $price / ( 1 +  $tax_rate / 100);

          $tax_amount = 100 * ($price - $price_excluding_tax);

          $price = $price_excluding_tax;

      } else {

          $tax_amount = $price * $tax_rate;
      }

      $cost = round(100 *  $price);

      $option = array(
        "service"    => $rate->getCarrierTitle().' - '.$rate->getMethodTitle(),
        "cost"       => $cost,
        "tax_amount" => $cost == 0 ? 0 : round(round(($tax_amount + $tax_remain) * 100) / 100)
      );

      $response['shipping_options'][] = $option;
    }
    /*****************************************************************************************/

    $key = Mage::getStoreConfig('payment/boltpay/management_key');
    $key = Mage::helper('core')->decrypt($key);

    $response = json_encode($response, JSON_PRETTY_PRINT);

    header('Content-type: application/json');
    header("X-Merchant-Key: $key");
    header('X-Nonce: ' . rand(100000000, 999999999));
    echo $response;

} catch (Exception $e) {
    Mage::helper('boltpay/bugsnag')-> getBugsnag()->notifyException($e);
    throw $e;
}

