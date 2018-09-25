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

/**
 * Class Bolt_Boltpay_Helper_Api
 *
 * The Magento Helper class that provides utility methods for the following operations:
 *
 * 1. Fetching the transaction info by calling the Fetch Bolt API endpoint.
 * 2. Verifying Hook Requests.
 * 3. Makes the calls towards Bolt API.
 * 4. Generates Bolt order submission data.
 */
class Bolt_Boltpay_Helper_Api extends Bolt_Boltpay_Helper_Data
{
    const ITEM_TYPE_PHYSICAL = 'physical';
    const ITEM_TYPE_DIGITAL  = 'digital';

    protected $curlHeaders;
    protected $curlBody;

    // Store discount types, internal and 3rd party.
    // Can appear as keys in Quote::getTotals result array.
    private $discountTypes = array(
        'discount',
        'giftcardcredit',
        'giftcardcredit_after_tax',
        'giftvoucher',
        'giftvoucher_after_tax',
        'aw_storecredit',
        'credit', // magestore-customer-credit
        'amgiftcard', // https://amasty.com/magento-gift-card.html
        'amstcred', // https://amasty.com/magento-store-credit.html
        'awraf',    //https://ecommerce.aheadworks.com/magento-extensions/refer-a-friend.html#magento1
    );

    /**
     * A call to Fetch Bolt API endpoint. Gets the transaction info.
     *
     * @param string $reference        Bolt transaction reference
     * @param int $tries
     *
     * @throws Exception     thrown if multiple (3) calls fail
     * @return bool|mixed Transaction info
     */
    public function fetchTransaction($reference, $tries = 3)
    {
        try {
            return $this->transmit($reference, null);
        } catch (Exception $e) {
            if (--$tries == 0) {
                $message = Mage::helper('boltpay')->__("BoltPay Gateway error: Fetch Transaction call failed multiple times for transaction referenced: %s", $reference);
                Mage::helper('boltpay/bugsnag')->notifyException(new Exception($message));
                Mage::helper('boltpay/bugsnag')->notifyException($e);
                throw $e;
            }

            return $this->fetchTransaction($reference, $tries);
        }
    }

    /**
     * Verifying Hook Requests using pre-exchanged signing secret key.
     *
     * @param $payload
     * @param $hmacHeader
     * @return bool
     */
    private function verify_hook_secret($payload, $hmacHeader)
    {
        $signingSecret = Mage::helper('core')->decrypt(Mage::getStoreConfig('payment/boltpay/signing_key'));
        $computedHmac  = trim(base64_encode(hash_hmac('sha256', $payload, $signingSecret, true)));

        return $hmacHeader == $computedHmac;
    }

    /**
     * Verifying Hook Requests via API call.
     *
     * @param $payload
     * @param $hmacHeader
     * @return bool if signature is verified
     */
    private function verify_hook_api($payload, $hmacHeader)
    {
        try {
            $url = Mage::helper('boltpay/url')->getApiUrl() . "/v1/merchant/verify_signature";

            $key = Mage::helper('core')->decrypt(Mage::getStoreConfig('payment/boltpay/api_key'));

            $ch = curl_init($url);

            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $httpHeader = array(
                "X-Api-Key: $key",
                "X-Bolt-Hmac-Sha256: $hmacHeader",
                "Content-type: application/json",
            );
            curl_setopt($ch, CURLOPT_HTTPHEADER, $httpHeader);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            Mage::helper('boltpay/bugsnag')->addMetaData(array('BOLT API REQUEST' => array('verify-hook-api-header'=>$httpHeader)),true);
            Mage::helper('boltpay/bugsnag')->addMetaData(array('BOLT API REQUEST' => array('verify-hook-api-data'=>$payload)),true);
            $result = curl_exec($ch);

            $response = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $this->setCurlResultWithHeader($ch, $result);

            $resultJSON = $this->getCurlJSONBody();
            Mage::helper('boltpay/bugsnag')->addMetaData(array('BOLT API RESPONSE' => array('verify-hook-api-response'=>$resultJSON)),true);

            return $response == 200;
        } catch (Exception $e) {
            Mage::helper('boltpay/bugsnag')->notifyException($e);
            return false;
        }

    }

    /**
     * Verifying Hook Requests. If signing secret is not defined fallback to api call.
     *
     * @param $payload
     * @param $hmacHeader
     * @return bool
     */
    public function verify_hook($payload, $hmacHeader)
    {
        return $this->verify_hook_secret($payload, $hmacHeader) || $this->verify_hook_api($payload, $hmacHeader);
    }

    /**
     * Calls the Bolt API endpoint.
     *
     * @param string $command  The endpoint to be called
     * @param string $data     an object to be encoded to JSON as the value passed to the endpoint
     * @param string $object   defines part of endpoint url which is normally/always??? set to merchant
     * @param string $type     Defines the endpoint type (i.e. order|transactions|sign) that is used as part of the url
     * @param null $storeId
     * @throws  Mage_Core_Exception  thrown if an error is detected in a response
     * @return mixed           Object derived from Json got as a response
     */
    public function transmit($command, $data, $object='merchant', $type='transactions', $storeId = null)
    {
        $url = Mage::helper('boltpay/url')->getApiUrl($storeId) . 'v1/';

        if($command == 'sign' || $command == 'orders') {
            $url .= $object . '/' . $command;
        } elseif ($command == null || $command == '') {
            $url .= $object;
        } else {
            $url .= $object . '/' . $type . '/' . $command;
        }

        //Mage::log(sprintf("Making an API call to %s", $url), null, 'bolt.log');

        $ch = curl_init($url);
        $params = "";
        if ($data != null) {
            $params = json_encode($data);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        }

        if ($command == '' && $type == '' && $object == 'merchant') {
            $key = Mage::getStoreConfig('payment/boltpay/publishable_key_multipage', $storeId);
        } else {
            $key = Mage::getStoreConfig('payment/boltpay/api_key', $storeId);
        }

        //Mage::log('KEY: ' . Mage::helper('core')->decrypt($key), null, 'bolt.log');

        $contextInfo = Mage::helper('boltpay/bugsnag')->getContextInfo();
        $headerInfo = array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($params),
            'X-Api-Key: ' . Mage::helper('core')->decrypt($key),
            'X-Nonce: ' . rand(100000000, 999999999),
            'User-Agent: BoltPay/Magento-' . $contextInfo["Magento-Version"],
            'X-Bolt-Plugin-Version: ' . $contextInfo["Bolt-Plugin-Version"]
        );
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headerInfo);
        Mage::helper('boltpay/bugsnag')->addMetaData(array('BOLT API REQUEST' => array('header'=>$headerInfo)));
        Mage::helper('boltpay/bugsnag')->addMetaData(array('BOLT API REQUEST' => array('data'=>$data)),true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);

        $result = curl_exec($ch);
        if ($result === false) {
            $curlInfo = var_export(curl_getinfo($ch), true);
            curl_close($ch);

            $message ="Curl info: " . $curlInfo;

            Mage::throwException($message);
        }

        $this->setCurlResultWithHeader($ch, $result);

        $resultJSON = $this->getCurlJSONBody();
        Mage::helper('boltpay/bugsnag')->addMetaData(array('BOLT API RESPONSE' => array('BOLT-RESPONSE'=>$resultJSON)),true);
        $jsonError = $this->handleJSONParseError();
        if ($jsonError != null) {
            curl_close($ch);
            $message ="JSON Parse Type: " . $jsonError . " Response: " . $result;
            Mage::throwException($message);
        }

        curl_close($ch);
        Mage::getModel('boltpay/payment')->debugData($resultJSON);

        return $this->_handleErrorResponse($resultJSON, $url, $params);
    }

    protected function setCurlResultWithHeader($curlResource, $result)
    {
        $curlHeaderSize = curl_getinfo($curlResource, CURLINFO_HEADER_SIZE);

        $this->curlHeaders = substr($result, 0, $curlHeaderSize);
        $this->curlBody = substr($result, $curlHeaderSize);

        $this->setBoltTraceId();
    }

    protected function setBoltTraceId()
    {
        if(empty($this->curlHeaders)) { return;
        }

        foreach(explode("\r\n", $this->curlHeaders) as $row) {
            if(preg_match('/(.*?): (.*)/', $row, $matches)) {
                if(count($matches) == 3 && $matches[1] == 'X-Bolt-Trace-Id') {
                    Mage::helper('boltpay/bugsnag')->setBoltTraceId($matches[2]);
                    break;
                }
            }
        }
    }

    protected function getCurlJSONBody()
    {
        return json_decode($this->curlBody);
    }

    /**
     * Bolt Api call response wrapper method that checks for potential error responses.
     *
     * @param mixed $response   A response received from calling a Bolt endpoint
     * @param $url
     *
     * @throws  Mage_Core_Exception  thrown if an error is detected in a response
     * @return mixed  If there is no error then the response is returned unaltered.
     */
    private function _handleErrorResponse($response, $url, $request)
    {
        if (is_null($response)) {
            $message = Mage::helper('boltpay')->__("BoltPay Gateway error: No response from Bolt. Please re-try again");
            Mage::throwException($message);
        } elseif (self::isResponseError($response)) {
            if (property_exists($response, 'errors')) {
                Mage::unregister("bolt_api_error");
                Mage::register("bolt_api_error", $response->errors[0]->message);
            }

            $message = Mage::helper('boltpay')->__("BoltPay Gateway error for %s: Request: %s, Response: %s", $url, $request, json_encode($response, true));

            Mage::helper('boltpay/bugsnag')->notifyException(new Exception($message));
            Mage::throwException($message);
        }

        return $response;
    }

    /**
     * A helper methond for checking errors in JSON object.
     *
     * @return null|string
     */
    public function handleJSONParseError()
    {
        switch (json_last_error()) {
            case JSON_ERROR_NONE:
                return null;

            case JSON_ERROR_DEPTH:
                return Mage::helper('boltpay')->__('Maximum stack depth exceeded');

            case JSON_ERROR_STATE_MISMATCH:
                return Mage::helper('boltpay')->__('Underflow or the modes mismatch');

            case JSON_ERROR_CTRL_CHAR:
                return Mage::helper('boltpay')->__('Unexpected control character found');

            case JSON_ERROR_SYNTAX:
                return Mage::helper('boltpay')->__('Syntax error, malformed JSON');

            case JSON_ERROR_UTF8:
                return Mage::helper('boltpay')->__('Malformed UTF-8 characters, possibly incorrectly encoded');

            default:
                return Mage::helper('boltpay')->__('Unknown error');
        }
    }

    /**
     * Generates order data for sending to Bolt.
     *
     * @param Mage_Sales_Model_Quote        $quote      Magento quote instance
     * @param Mage_Sales_Model_Quote_Item[] $items      array of Magento products
     * @param bool                          $multipage  Is checkout type Multi-Page Checkout, the default is true, set to false for One Page Checkout
     *
     * @return array            The order payload to be sent as to bolt in API call as a PHP array
     */
    public function buildOrder($quote, $items, $multipage)
    {
        $cart = $this->buildCart($quote, $items, $multipage);
        return array(
            'cart' => $cart
        );
    }

    /**
     * Generates cart submission data for sending to Bolt order cart field.
     *
     * @param Mage_Sales_Model_Quote        $quote      Magento quote instance
     * @param Mage_Sales_Model_Quote_Item[] $items      array of Magento products
     * @param bool                          $multipage  Is checkout type Multi-Page Checkout, the default is true, set to false for One Page Checkout
     *
     * @return array            The cart data part of the order payload to be sent as to bolt in API call as a PHP array
     */
    public function buildCart($quote, $items, $multipage)
    {
        /** @var Bolt_Boltpay_Helper_Data $boltHelper */
        $boltHelper = Mage::helper('boltpay');

        ///////////////////////////////////////////////////////////////////////////////////
        // Get quote totals
        ///////////////////////////////////////////////////////////////////////////////////

        /***************************************************
         * One known Magento error is that sometimes the subtotal and grand totals are doubled
         * because Magento adds duplicate address totals when it is doing its total aggregation
         * for customers with multiple shipping addresses
         * Totals in magento are ultimately attached to addresses, so the solution is to limit
         * the total number addresses to two maximum (one billing which always exist, and one shipping
         *
         * The following commented out code implements this.
         *
         * HOWEVER: WE CANNOT PUT THIS INTO GENERAL USE AS IT WOULD DROP SUPPORT FOR ITEMS SHIPPED TO DIFFERENT LOCATIONS
         ***************************************************/
        //////////////////////////////////////////////////////////
        //$addresses = $quote->getAllAddresses();
        //if (count($addresses) > 2) {
        //    for($i = 2; $i < count($addresses); $i++) {
        //        $address = $addresses[$i];
        //        $address->isDeleted(true);
        //    }
        //}
        ///////////////////////////////////////////////////////////
        /* Instead we will calculate the cost and use our calculated value to match against magento's calculation
         * If the totals do not match, then we will try halving the Magento total.  We use Magento's
         * instead of ours because on the potential complex nature of discounts and virtual products.
         */
        /***************************************************/

        $calculated_total = 0;
        $boltHelper->collectTotals($quote)->save();

        $totals = $quote->getTotals();
        ///////////////////////////////////////////////////////////////////////////////////

        ///////////////////////////////////////////////////////////
        // Generate base cart data, quote, order and items related.
        ///////////////////////////////////////////////////////////
        $cartSubmissionData = array(
            'order_reference' => $quote->getParentQuoteId(),
            'display_id'      => $quote->getReservedOrderId().'|'.$quote->getId(),
            'items'           => array_map(
                function ($item) use ($quote, &$calculatedTotal, $boltHelper) {
                    $imageUrl = $boltHelper->getItemImageUrl($item);
                    $product   = Mage::getModel('catalog/product')->load($item->getProductId());
                    $type = $product->getTypeId() == 'virtual' ? self::ITEM_TYPE_DIGITAL : self::ITEM_TYPE_PHYSICAL;

                    $calculatedTotal += round($item->getPrice() * 100 * $item->getQty());
                    return array(
                        'reference'    => $quote->getId(),
                        'image_url'    => $imageUrl,
                        'name'         => $item->getName(),
                        'sku'          => $product->getData('sku'),
                        'description'  => substr($product->getDescription(), 0, 8182) ?: '',
                        'total_amount' => round($item->getCalculationPrice() * 100) * $item->getQty(),
                        'unit_price'   => round($item->getCalculationPrice() * 100),
                        'quantity'     => $item->getQty(),
                        'type'         => $type
                    );
                }, $items
            ),
            'currency' => $quote->getQuoteCurrencyCode(),
        );
        ///////////////////////////////////////////////////////////

        /////////////////////////////////////////////////////////////////////////
        // Check for discounts and include them in the submission data if found.
        /////////////////////////////////////////////////////////////////////////
        $totalDiscount = 0;

        $cartSubmissionData['discounts'] = array();

        foreach ($this->discountTypes as $discount) {
            if (@$totals[$discount] && $amount = $totals[$discount]->getValue()) {
                // Some extensions keep discount totals as positive values,
                // others as negative, which is the Magento default.
                // Using the absolute value.
                $discountAmount = abs(round($amount * 100));

                $cartSubmissionData['discounts'][] = array(
                    'amount'      => $discountAmount,
                    'description' => $totals[$discount]->getTitle(),
                    'type'        => 'fixed_amount',
                );
                $totalDiscount -= $discountAmount;
            }
        }

        $calculatedTotal += $totalDiscount;
        /////////////////////////////////////////////////////////////////////////

        if ($multipage) {
            /////////////////////////////////////////////////////////////////////////////////////////
            // For multi-page checkout type send only subtotal, do not include shipping and tax info.
            /////////////////////////////////////////////////////////////////////////////////////////
            $totalKey = @$totals['subtotal'] ? 'subtotal' : 'grand_total';

            $cartSubmissionData['total_amount'] = round($totals[$totalKey]->getValue() * 100);
            $cartSubmissionData['total_amount'] += $totalDiscount;
            /////////////////////////////////////////////////////////////////////////////////////////
        } else {

            // Billing / shipping address fields that are required when the address data is sent to Bolt.
            $requiredAddressFields = array(
                'first_name',
                'last_name',
                'street_address1',
                'locality',
                'region',
                'postal_code',
                'country_code',
            );

            ///////////////////////////////////////////
            // Include billing address info if defined.
            ///////////////////////////////////////////
            $billingAddress  = $quote->getBillingAddress();

            if ($billingAddress) {
                $cartSubmissionData['billing_address'] = array(
                    'street_address1' => $billingAddress->getStreet1(),
                    'street_address2' => $billingAddress->getStreet2(),
                    'street_address3' => $billingAddress->getStreet3(),
                    'street_address4' => $billingAddress->getStreet4(),
                    'first_name'      => $billingAddress->getFirstname(),
                    'last_name'       => $billingAddress->getLastname(),
                    'locality'        => $billingAddress->getCity(),
                    'region'          => $billingAddress->getRegion(),
                    'postal_code'     => $billingAddress->getPostcode(),
                    'country_code'    => $billingAddress->getCountry(),
                    'phone'           => $billingAddress->getTelephone(),
                    'email'           => $billingAddress->getEmail() ?: $quote->getCustomerEmail(),
                    'phone_number'    => $billingAddress->getTelephone(),
                    'email_address'   => $billingAddress->getEmail() ?: $quote->getCustomerEmail(),
                );

                foreach ($requiredAddressFields as $field) {
                    if (empty($cartSubmissionData['billing_address'][$field])) {
                        unset($cartSubmissionData['billing_address']);
                        break;
                    }
                }
            }
            ///////////////////////////////////////////

            ////////////////////////////////////////////////////////////////////////////////////
            // For one page checkout type include tax and shipment / address data in submission.
            ////////////////////////////////////////////////////////////////////////////////////
            $cartSubmissionData['total_amount'] = round($totals["grand_total"]->getValue() * 100);

            if (@$totals['tax']) {
                $cartSubmissionData['tax_amount'] = round($totals['tax']->getValue() * 100);
                $calculatedTotal += $cartSubmissionData['tax_amount'];
            }

            $shippingAddress = $quote->getShippingAddress();

            $region = $shippingAddress->getRegion();
            if (empty($region) && !in_array($shippingAddress->getCountry(), array('US', 'CA'))) {
                $region = $shippingAddress->getCity();
            }

            if ($shippingAddress) {
                $cartShippingAddress = array(
                    'street_address1' => $shippingAddress->getStreet1(),
                    'street_address2' => $shippingAddress->getStreet2(),
                    'street_address3' => $shippingAddress->getStreet3(),
                    'street_address4' => $shippingAddress->getStreet4(),
                    'first_name'      => $shippingAddress->getFirstname(),
                    'last_name'       => $shippingAddress->getLastname(),
                    'locality'        => $shippingAddress->getCity(),
                    'region'          => $region,
                    'postal_code'     => $shippingAddress->getPostcode(),
                    'country_code'    => $shippingAddress->getCountry(),
                    'phone'           => $shippingAddress->getTelephone(),
                    'email'           => $shippingAddress->getEmail() ?: $quote->getCustomerEmail(),
                    'phone_number'    => $shippingAddress->getTelephone(),
                    'email_address'   => $shippingAddress->getEmail() ?: $quote->getCustomerEmail(),
                );

                if (@$totals['shipping']) {

                    $cartSubmissionData['shipments'] = array(array(
                        'shipping_address' => $cartShippingAddress,
                        'tax_amount'       => (int) round($shippingAddress->getShippingTaxAmount() * 100),
                        'service'          => $shippingAddress->getShippingDescription(),
                        'carrier'          => $shippingAddress->getShippingMethod(),
                        'reference'        => $shippingAddress->getShippingMethod(),
                        'cost'             => (int) round($totals['shipping']->getValue() * 100),
                    ));
                    $calculatedTotal += round($totals['shipping']->getValue() * 100);

                } else if (Mage::app()->getStore()->isAdmin()) {
                    $cartShippingAddress = Mage::getSingleton('admin/session')->getOrderShippingAddress();

                    if (empty($cartShippingAddress['email'])) {
                        $cartShippingAddress['email'] = $cartShippingAddress['email_address'] = $quote->getCustomerEmail();
                    }

                    /* @var Mage_Adminhtml_Block_Sales_Order_Create_Shipping_Method_Form $shippingMethodBlock */
                    $shippingMethodBlock =  Mage::app()->getLayout()->createBlock("adminhtml/sales_order_create_shipping_method_form");
                    $shipping_rate = $shippingMethodBlock->getActiveMethodRate();

                    if ($shipping_rate) {
                        /* @var Mage_Adminhtml_Block_Sales_Order_Create_Totals $totalsBlock */
                        $totalsBlock =  Mage::app()->getLayout()->createBlock("adminhtml/sales_order_create_totals_shipping");

                        /* @var Mage_Sales_Model_Quote_Address_Total $grandTotal */
                        $grandTotal = $totalsBlock->getTotals()['grand_total'];
                        /* @var Mage_Sales_Model_Quote_Address_Total $taxTotal */
                        $taxTotal = $totalsBlock->getTotals()['tax'];
                        /* @var Mage_Sales_Model_Quote_Address_Total $shippingTotal */
                        $shippingTotal = $totalsBlock->getTotals()['shipping'];

                        $cartSubmissionData['shipments'] = array(array(
                            'shipping_address' => $cartShippingAddress,
                            'tax_amount'       => 0,
                            'service'          => $shipping_rate->getMethodTitle(),
                            'carrier'          => $shipping_rate->getCarrierTitle(),
                            'cost'             => $shippingTotal ? (int) round($shippingTotal->getValue() * 100) : 0,
                        ));

                        $calculatedTotal += round($shippingTotal->getValue() * 100);

                        $cartSubmissionData['total_amount'] = (int) round($grandTotal->getValue() * 100);
                        $cartSubmissionData['tax_amount'] = $taxTotal ? (int) round($taxTotal->getValue() * 100) : 0;
                    }

                }

            }
            ////////////////////////////////////////////////////////////////////////////////////
        }

        //Mage::log(var_export($cart_submission_data, true), null, "bolt.log");
        // In some cases discount amount can cause total_amount to be negative. In this case we need to set it to 0.
        if($cartSubmissionData['total_amount'] < 0) {
            $cartSubmissionData['total_amount'] = 0;
        }

        return $this->getCorrectedTotal($calculatedTotal, $cartSubmissionData);
    }

    /**
     * Utility method that attempts to correct totals if the projected total that was calculated from
     * all items and the given discount, does not match the $magento calculated total.  The totals may vary
     * do to an error in the internal Magento code
     *
     * @param int $projectedTotal              total calculated from items, discounts, taxes and shipping
     * @param int $magentoDerivedCartData    totals returned by magento and formatted for Bolt
     *
     * @return array  the corrected Bolt formatted cart data.
     */
    private function getCorrectedTotal($projectedTotal, $magentoDerivedCartData)
    {
        // we'll check if we can simply dividing by two corrects the problem
        if ($projectedTotal == (int)($magentoDerivedCartData['total_amount']/2)) {
            $magentoDerivedCartData["total_amount"] = (int)($magentoDerivedCartData['total_amount']/2);

            /*  I will defer handling discounts, tax, and shipping until more info is collected
            /*  The placeholder code is left below to be filled in if and when more cases arise

            if (isset($magento_derived_cart_data["tax_amount"])) {
                $magento_derived_cart_data["tax_amount"] = (int)($magento_derived_cart_data["tax_amount"]/2);
            }

            if (isset($magento_derived_cart_data["discounts"])) {
                $magento_derived_cart_data[""] = (int)($magento_derived_cart_data[""]/2);
            }

            if (isset($magento_derived_cart_data["shipments"])) {
                $magento_derived_cart_data[""] = (int)($magento_derived_cart_data[""]/2);
            }
            */
        }

        // otherwise, we have no better thing to do than let the Bolt server do the checking
        return $magentoDerivedCartData;

    }

    /**
     * Checks if the Bolt API response indicates an error.
     *
     * @param $response     Bolt API response
     * @return bool         true if there is an error, false otherwise
     */
    public function isResponseError($response)
    {
        return property_exists($response, 'errors') || property_exists($response, 'error_code');
    }

    /**
     * Sets Plugin information in the response headers to callers of the API
     */
    public function setResponseContextHeaders()
    {
        $contextInfo = Mage::helper('boltpay/bugsnag')->getContextInfo();

        Mage::app()->getResponse()
            ->setHeader('User-Agent', 'BoltPay/Magento-' . $contextInfo["Magento-Version"], true)
            ->setHeader('X-Bolt-Plugin-Version', $contextInfo["Bolt-Plugin-Version"], true);
    }
}