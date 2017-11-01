<?php
/**
 * Class Bolt_Boltpay_Helper_Api
 *
 * The Magento Helper class that provides utility methods for the following operations:
 *
 * 1. Fetching the transaction info by calling the Fetch Bolt API endpoint.
 * 2. Verifying Hook Requests.
 * 3. Saves the order in Magento system.
 * 4. Makes the calls towards Bolt API.
 * 5. Generates Bolt order submission data.
 */
class Bolt_Boltpay_Helper_Api extends Bolt_Boltpay_Helper_Data {

    const API_URL_TEST = 'https://api-sandbox.boltapp.com/';
    const API_URL_PROD = 'https://api.boltapp.com/';

    /**
     * A call to Fetch Bolt API endpoint. Gets the transaction info.
     *
     * @param string $reference        Bolt transaction reference
     * @param int $tries
     *
     * @throws Mage_Core_Exception     thrown if multiple (3) calls fail
     * @return bool|mixed Transaction info
     */
    public function fetchTransaction($reference, $tries = 3) {

        $bugsnag = Mage::helper('boltpay/bugsnag')-> getBugsnag();

        if ($tries-- == 0) {
            $message = "BoltPay Gateway error: Fetch Transaction call failed multiple times for transaction referenced: $reference";
            $bugsnag->notifyError('Exception', $message);
            Mage::throwException($message);
        }

        try {
            $response = $this->transmit($reference, null);
            return $this->handleErrorResponse($response);
        } catch (Exception $e) {
            return $this->fetchTransaction($reference, $tries);
        }
    }

    /**
     * Verifying Hook Requests using pre-exchanged signing secret key.
     *
     * @param $payload
     * @param $hmac_header
     * @return bool
     */
    private function verify_hook_secret($payload, $hmac_header) {

        $signing_secret = Mage::helper('core')->decrypt(Mage::getStoreConfig('payment/boltpay/signing_key'));
        $computed_hmac  = trim(base64_encode(hash_hmac('sha256', $payload, $signing_secret, true)));

        return $hmac_header == $computed_hmac;
    }

    /**
     * Verifying Hook Requests via API call.
     *
     * @param $payload
     * @param $hmac_header
     * @return bool
     * @throws Exception
     */
    private function verify_hook_api($payload, $hmac_header) {

        try {

            $url = $this->getApiUrl() . "/v1/merchant/verify_signature";

            $key = Mage::getStoreConfig('payment/boltpay/management_key');
            $key = Mage::helper('core')->decrypt($key);

            $ch = curl_init($url);

            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                "X-Merchant-Key: $key",
                "X-Bolt-Hmac-Sha256: $hmac_header",
                "Content-type: application/json",
            ));

            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

            curl_exec($ch);

            $response = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            return $response == 200;

        } catch (Exception $e) {
            Mage::helper('boltpay/bugsnag')-> getBugsnag()->notifyException($e);
            return false;
        }

    }

    /**
     * Verifying Hook Requests. If signing secret is not defined fallback to api call.
     *
     * @param $payload
     * @param $hmac_header
     * @return bool
     */
    public function verify_hook($payload, $hmac_header) {

        return $this->verify_hook_secret($payload, $hmac_header) || $this->verify_hook_api($payload, $hmac_header);
    }

    /**
     * Processes order creation. Called from both frontend and API.
     *
     * @param $reference                Bolt transaction reference
     * @param null $session_quote_id    Session quote id, if triggered from frontend
     * @return mixed                    Order on successful creation
     * @throws Exception
     */
    public function createOrder($reference, $session_quote_id = null) {

        // fetch transaction info
        $transaction = $this->fetchTransaction($reference);

        $transactionStatus = $transaction->status;

        // shipping carrier set up during checkout
        $service = $transaction->order->cart->shipments[0]->service;

        $quote_id = $transaction->order->cart->order_reference;

        // check if the quotes matches, frontend only
        if ($session_quote_id && $session_quote_id != $quote_id) {
            return false;
        }

        $display_id = $transaction->order->cart->display_id;

        $quote = Mage::getModel('sales/quote')
            ->getCollection()
            ->addFieldToFilter('reserved_order_id', $display_id)
            ->getFirstItem();

        // adding guest user email to order
        if (empty($quote->getCustomerEmail())) {

            $email = $transaction->from_credit_card->billing_address->email_address;
            $quote->setCustomerEmail($email);
            $quote->save();
        }

        $quote->getShippingAddress()->setShouldIgnoreValidation(true)->save();
        $quote->getBillingAddress()->setShouldIgnoreValidation(true)->save();

        /********************************************************************
         * Setting up shipping method by finding the carier code that matches
         * the one set during checkout
         ********************************************************************/

        $rates = $quote->getShippingAddress()->getAllShippingRates();

        foreach ($rates as $rate) {
            if ($rate->getCarrierTitle() . ' - ' . $rate->getMethodTitle() == $service) {

                $shippingMethod = $rate->getCarrier() . '_' . $rate->getMethod();
                $quote->getShippingAddress()->setShippingMethod($shippingMethod)->save();
                break;
            }
        }

        // setting Bolt as payment method

        $data = array('method' => Bolt_Boltpay_Model_Payment::METHOD_CODE);

        $quote->getShippingAddress()->setPaymentMethod($data['method'])->save();

        $payment = $quote->getPayment();

        $payment->importData($data);

        // adding transaction data to payment instance
        $payment->setAdditionalInformation('bolt_transaction_status', $transactionStatus);
        $payment->setAdditionalInformation('bolt_reference', $reference);
        $payment->setAdditionalInformation('bolt_merchant_transaction_id', $transaction->id);
        $payment->setTransactionId($transaction->id);

        $quote->collectTotals()->save();

        // a call to internal Magento service for orde creation
        $service = Mage::getModel('sales/service_quote', $quote);
        $service->submitAll();

        // deactivate quote
        $quote->setIsActive(false);
        $quote->save();

        $order = $service->getOrder();

        Mage::getModel('boltpay/payment')->handleOrderUpdate($order);

        return $order;

    }

    /**
     * Calls the Bolt API endpoint.
     *
     * @param string $command  The endpoint to be called
     * @param string $data     an object to be encoded to JSON as the value passed to the endpoint
     * @param string $object   defines part of endpoint url which is normally/always??? set to merchant
     * @param string $type     Defines the endpoint type (i.e. order|transactions|sign) that is used as part of the url
     * @return mixed           Object derived from Json got as a response
     */
    public function transmit($command, $data, $object='merchant', $type='transactions') {

        $url = $this->getApiUrl() . 'v1/';

        Mage::log(sprintf("Making an API call to %s", $command), null, 'bolt.log');
        if($command == 'sign') {
            $url .= $object . '/' . $command;
        } elseif ($command == null || $command == '') {
            $url .= $object;
        } elseif ($command == 'orders') {
            $url .= $object . '/' . $command;
        } else {
            $url .= $object . '/' . $type . '/' . $command;
        }

        $ch = curl_init($url);
        $params = "";
        if ($data != null) {
            $params = json_encode($data);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        }

        if ($command == 'oauth' && $type == 'division') {
            $key = Mage::getStoreConfig('payment/boltpay/merchant_key');
        } elseif ($command == '' && $type == '' && $object == 'merchant') {
            $key = Mage::getStoreConfig('payment/boltpay/merchant_key');
        } elseif ($command == 'sign') {
            $key = Mage::getStoreConfig('payment/boltpay/management_key');
        } else {
            $key = Mage::getStoreConfig('payment/boltpay/management_key');
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($params),
            'X-Merchant-Key: ' . Mage::helper('core')->decrypt($key),
            'X-Nonce: ' . rand(100000000, 999999999),
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);
        if ($result === false) {
            $curl_info = var_dump(curl_getinfo($ch));
            $curl_err = curl_error($ch);
            curl_close($ch);

            $message ="Curl info: " . $curl_info;

            Mage::log($message, null, 'bolt.log');

            Mage::throwException($message);
        }
        $resultJSON = json_decode($result);
        $jsonError = $this->handleJSONParseError();
        if ($jsonError != null) {
            curl_close($ch);
            $message ="JSON Parse Type: " . $jsonError . " Response: " . $result;
            Mage::throwException($message);
        }
        curl_close($ch);
        Mage::getModel('boltpay/payment')->debugData($resultJSON);

        return $resultJSON;
    }

    /**
     * Bolt Api call response wrapper method that checks for potential error responses.
     *
     * @param mixed $response   A response received from calling a Bolt endpoint
     *
     * @throws  Mage_Core_Exception  thrown if an error is detected in a response
     * @return mixed  If there is no error then the response is returned unaltered.
     */
    public function handleErrorResponse($response) {

        if (is_null($response)) {
            $message ="BoltPay Gateway error: No response from Bolt. Please re-try again";
            Mage::throwException($message);
        } elseif (self::isResponseError($response)) {
            $message = sprintf("BoltPay Gateway error: %s", serialize($response));
            Mage::throwException($message);
        }
        return $response;
    }

    /**
     * A helper methond for checking errors in JSON object.
     *
     * @return null|string
     */
    public function handleJSONParseError() {
        switch (json_last_error()) {
            case JSON_ERROR_NONE:
                return null;

            case JSON_ERROR_DEPTH:
                return 'Maximum stack depth exceeded';

            case JSON_ERROR_STATE_MISMATCH:
                return 'Underflow or the modes mismatch';

            case JSON_ERROR_CTRL_CHAR:
                return 'Unexpected control character found';

            case JSON_ERROR_SYNTAX:
                return 'Syntax error, malformed JSON';

            case JSON_ERROR_UTF8:
                return 'Malformed UTF-8 characters, possibly incorrectly encoded';

            default:
                return 'Unknown error';
        }
    }

    /**
     * Returns the Bolt API url, sandbox or production, depending on the store configuration.
     *
     * @return string  the api url, sandbox or production
     */
    public function getApiUrl() {
        return Mage::getStoreConfig('payment/boltpay/test') ?
            self::API_URL_TEST :
            self::API_URL_PROD;
    }

    /**
     * Generates order data for sending to Bolt.
     *
     * @param $quote            Maagento quote instance
     * @param array $items      array of Magento products
     * @return array            The order payload to be sent as to bolt in API call as a PHP array
     */
    public function buildOrder($quote, $items) {
        $cart = $this->buildCart($quote, $items);
        return array(
            'cart' => $cart
        );
    }

    /**
     * Generates cart submission data for sending to Bolt order cart field.
     *
     * @param $quote    Maagento quote instance
     * @param $items    array of Magento products
     * @return array    The cart data part of the order payload to be sent as to bolt in API call as a PHP array
     */
    public function buildCart($quote, $items) {
        $totals   = $quote->getTotals();

        $productMediaConfig = Mage::getModel('catalog/product_media_config');

        $cart_submission_data = array(
            'order_reference' => $quote->getId(),
            'display_id' => $quote->getReservedOrderId(),
            'items' => array_map(function ($item) use ($quote, $productMediaConfig) {
                $image_url = $productMediaConfig->getMediaUrl($item->getProduct()->getThumbnail());
                $product = Mage::getModel('catalog/product')->load($item->getProductId());
                $unitPrice = round($item->getPrice() * 100);
                return array(
                    'reference' => $quote->getId(),
                    'image_url' => $image_url,
                    'name' => $item->getName(),
                    'sku' => $product->getData('sku'),
                    'description' => $product->getDescription(),
                    'total_amount' => $unitPrice * $item->getQty(),
                    'unit_price' => $unitPrice,
                    'quantity' => $item->getQty()
                );
            }, $items),
        );

        $total_key = $totals['subtotal'] ? 'subtotal' : 'grand_total';

        $cart_submission_data['total_amount'] = round($totals[$total_key]->getValue() * 100);

        if (@$totals['discount']) {

            $amount = round($totals['discount']->getValue() * 100);

            $cart_submission_data['discounts'] = array(array(
                'amount' => -1 * $amount,
                'description' => $totals['discount']->getTitle(),
            ));

            $cart_submission_data['total_amount'] += $amount;

        }

        $currency = $quote->getQuoteCurrencyCode();

        $cart_submission_data['currency'] = $currency;

        return $cart_submission_data;
    }

    /**
     * Checks if the Bolt API response indicates an error.
     *
     * @param $response     Bolt API response
     * @return bool         true if there is an error, false otherwise
     */
    public function isResponseError($response) {
        return array_key_exists('errors', $response) || array_key_exists('error_code', $response);
    }
}

