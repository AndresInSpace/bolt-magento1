<?php

require_once('TestHelper.php');

class Bolt_Boltpay_Block_Checkout_BoltpayTest extends PHPUnit_Framework_TestCase
{
    private $app = null;

    private $currentMock;

    /**
     * @var $testHelper Bolt_Boltpay_TestHelper
     */
    private $testHelper = null;

    public function setUp()
    {
        $this->app = Mage::app('default');

        $this->currentMock = $this->getMockBuilder('Bolt_Boltpay_Block_Checkout_Boltpay')
            ->setMethods(array('isAdminAndUseJsInAdmin', 'buildOnCheckCallback', 'buildOnSuccessCallback', 'buildOnCloseCallback'))
            ->disableOriginalConstructor()
            ->disableOriginalClone()
            ->disableArgumentCloning()
            ->getMock()
        ;

        $this->session  = Mage::getSingleton('customer/session');
        $this->quote    = Mage::getSingleton('checkout/session')->getQuote();

        $this->testHelper = new Bolt_Boltpay_TestHelper();
    }

    public function testIsEnableMerchantScopedAccount()
    {

    }

    public function testIsAllowedReplaceScriptOnCurrentPage()
    {

    }

    /**
     * @inheritdoc
     */
    public function testBuildCartData()
    {
        $autoCapture = true;
        $this->app->getStore()->setConfig('payment/boltpay/auto_capture', $autoCapture);

        // Prepare test response object
        $testBoltResponse = new stdClass();
        $testBoltResponse->token = md5('bolt');

        $result = $this->currentMock->buildCartData($testBoltResponse);

        $cartData = array(
            'authcapture' => $autoCapture,
            'orderToken' => md5('bolt'),
        );

        $this->assertEquals($cartData, $result);
    }

    /**
     * @inheritdoc
     */
    public function testBuildCartDataWithEmptyTokenField()
    {
        $autoCapture = true;
        $this->app->getStore()->setConfig('payment/boltpay/auto_capture', $autoCapture);

        // Prepare test response object
        $testBoltResponse = new stdClass();

        $result = $this->currentMock->buildCartData($testBoltResponse);

        $cartData = array(
            'authcapture'   => $autoCapture,
            'orderToken'    => '',
        );

        $this->assertEquals($cartData, $result, 'Something wrong with testBuildCartDataWithEmptyTokenField()');
    }

    /**
     * @inheritdoc
     */
    public function testBuildCartDataWithoutAutoCapture()
    {
        $autoCapture = false;
        $this->app->getStore()->setConfig('payment/boltpay/auto_capture', $autoCapture);

        // Prepare test response object
        $testBoltResponse = new stdClass();
        $testBoltResponse->token = md5('bolt');

        $result = $this->currentMock->buildCartData($testBoltResponse);

        $cartData = array(
            'authcapture' => $autoCapture,
            'orderToken' => md5('bolt'),
        );

        $this->assertEquals($cartData, $result, 'Something wrong with testBuildCartDataWithoutAutoCapture()');
    }

    /**
     * @inheritdoc
     */
    public function testBuildCartDataWithApiError()
    {
        $autoCapture = true;
        $this->app->getStore()->setConfig('payment/boltpay/auto_capture', $autoCapture);

        // Prepare test response object
        $testBoltResponse = new stdClass();
        $testBoltResponse->token = md5('bolt');

        $apiErrorMessage = 'Some error from api.';
        Mage::register('api_error', $apiErrorMessage);

        $result = $this->currentMock->buildCartData($testBoltResponse);

        $cartData = array(
            'authcapture' => $autoCapture,
            'orderToken' => md5('bolt'),
            'error' => $apiErrorMessage
        );

        $this->assertEquals($cartData, $result, 'Something wrong with testBuildCartDataWithApiError()');
    }

    public function testIsBoltOnlyPayment()
    {

    }

    public function testGetPublishableKey()
    {

    }

    public function testGetAdditionalCSS()
    {

    }

    public function testGetIpAddress()
    {

    }

    public function testGetReservedUserId()
    {

    }

    public function testUrl_get_contents()
    {

    }

    public function testCanUseBolt()
    {

    }

    public function testIsBoltActive()
    {

    }

    public function testGetCartDataJs()
    {

    }

    public function testGetLocationEstimate()
    {

    }

    public function testGetQuote()
    {

    }

    public function testIsAllowedConnectJsOnCurrentPage()
    {

    }

    public function testGetTheme()
    {

    }

    public function testGetConfigSelectors()
    {

    }

    public function testGetPublishableKeyForRoute()
    {

    }

    public function testIsTestMode()
    {

    }

    public function testGetCartURL()
    {

    }

    public function testGetSelectorsCSS()
    {

    }

    public function testGetSuccessURL()
    {

    }

    /**
     * @inheritdoc
     */
    public function testBuildBoltCheckoutJavascript()
    {
        $autoCapture = true;
        $this->app->getStore()->setConfig('payment/boltpay/auto_capture', $autoCapture);

        $cartData = array(
            'authcapture' => $autoCapture,
            'orderToken' => md5('bolt')
        );

        $hintData = array();

        $checkoutType = Bolt_Boltpay_Block_Checkout_Boltpay::CHECKOUT_TYPE_MULTI_PAGE;
        $immutableQuote = $this->getMockBuilder('Mage_Sales_Model_Quote')
            ->getMock()
        ;

        $this->app->getStore()->setConfig('payment/boltpay/check', '');
        $this->app->getStore()->setConfig('payment/boltpay/on_checkout_start', '');
        $this->app->getStore()->setConfig('payment/boltpay/on_shipping_details_complete', '');
        $this->app->getStore()->setConfig('payment/boltpay/on_shipping_options_complete', '');
        $this->app->getStore()->setConfig('payment/boltpay/on_payment_submit', '');
        $this->app->getStore()->setConfig('payment/boltpay/success', '');
        $this->app->getStore()->setConfig('payment/boltpay/close', '');

        $immutableQuoteID = 6;
        $immutableQuote->expects($this->once())
            ->method('getId')
            ->will($this->returnValue($immutableQuoteID));

        $jsonCart = json_encode($cartData);
        $jsonHints = '{}';
        if (sizeof($hintData) != 0) {
            // Convert $hint_data to object, because when empty data it consists array not an object
            $jsonHints = json_encode($hintData, JSON_FORCE_OBJECT);
        }
        $onSuccessCallback = 'function(transaction, callback) { console.log(test) }';

        $expected = $this->testHelper->buildCartDataJs($jsonCart, $immutableQuoteID, $jsonHints, array(
            'onSuccessCallback' => $onSuccessCallback
        ));

        $this->currentMock
            ->method('buildOnCheckCallback')
            ->will($this->returnValue(''));
        $this->currentMock
            ->method('buildOnSuccessCallback')
            ->will($this->returnValue($onSuccessCallback));
        $this->currentMock
            ->method('buildOnCloseCallback')
            ->will($this->returnValue(''));

        $result = $this->currentMock->buildBoltCheckoutJavascript($checkoutType, $immutableQuote, $hintData, $cartData);

        $this->assertEquals($expected, $result);
    }

    public function testGetTrackJsUrl()
    {

    }

    public function testGetCssSuffix()
    {

    }

    public function testGetSaveOrderURL()
    {

    }
}
