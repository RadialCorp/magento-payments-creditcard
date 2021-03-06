<?php
/**
 * Copyright (c) 2013-2014 eBay Enterprise, Inc.
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * @copyright   Copyright (c) 2013-2014 eBay Enterprise, Inc. (http://www.ebayenterprise.com/)
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

use eBayEnterprise\RetailOrderManagement\Api;
use eBayEnterprise\RetailOrderManagement\Api\Exception\NetworkError;
use eBayEnterprise\RetailOrderManagement\Api\Exception\UnsupportedHttpAction;
use eBayEnterprise\RetailOrderManagement\Api\Exception\UnsupportedOperation;
use eBayEnterprise\RetailOrderManagement\Payload;
use eBayEnterprise\RetailOrderManagement\Payload\Exception\InvalidPayload;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class Radial_CreditCard_Model_Method_Ccpayment extends Mage_Payment_Model_Method_Cc
{
    const CREDITCARD_TIMEOUT_MESSAGE = 'Radial_CreditCard_Timeout';
    const CREDITCARD_DENIED_MESSAGE = 'Radial_CreditCard_Denied';
    const CREDITCARD_FAILED_MESSAGE = 'Radial_CreditCard_Failed';
    const SETTLEMENT_FAILED_MESSAGE = 'Radial_CreditCard_Settlement_Failed';
    const CREDITCARD_AVS_FAILED_MESSAGE = 'Radial_CreditCard_AVS_Failed';
    const CREDITCARD_CVV_FAILED_MESSAGE = 'Radial_CreditCard_CVV_Failed';
    const METHOD_NOT_ALLOWED_FOR_COUNTRY = 'Radial_CreditCard_Method_Not_Allowed_For_Country';
    const INVALID_EXPIRATION_DATE = 'Radial_CreditCard_Invalid_Expiration_Date';
    const INVALID_CARD_TYPE = 'Radial_CreditCard_Invalid_Card_Type';
    const SETTLEMENT_TYPE_CAPTURE = 'Debit';
    const SETTLEMENT_TYPE_REFUND = 'Credit';
    const PAYMENT_RESPONSE_CODE_AVS = 'AVS';
    const PAYMENT_RESPONSE_CODE_AVSCSC = 'AVSCSC';
    const PAYMENT_RESPONSE_CODE_CSC = 'CSC';
    const PAYMENT_RESPONSE_CODE_DECLF = 'DECLF';
    const PAYMENT_RESPONSE_CODE_DECL = 'DECL';
    const PAYMENT_RESPONSE_CODE_DECLR = 'DECLR';
    const PAYMENT_RESPONSE_CODE_APPROVED = 'APPROVED';
    const PAYMENT_RESPONSE_CODE_TIMEOUT = 'TIMEOUT';
    /**
     * Block type to use to render the payment method form.
     * @var string
     */
    protected $_formBlockType = 'radial_creditcard/form_cc';
    /**
     * Code unique to this payment method.
     * @var string
     */
    protected $_code          = 'radial_creditcard';
    /**
     * Is this payment method a gateway (online auth/charge) ?
     */
    protected $_isGateway               = true;

    /**
     * Can authorize online?
     */
    protected $_canAuthorize            = true;

    /**
     * Can capture funds online?
     */
    protected $_canCapture              = true;

    /**
     * Can capture partial amounts online?
     */
    protected $_canCapturePartial       = true;

    /**
     * Can refund online?
     */
    protected $_canRefund               = true;

    /**
     * Can refund partial qty online?
     */
    protected $_canRefundInvoicePartial = true;

    /**
     * Can void transactions online?
     */
    protected $_canVoid                 = false;

    /**
     * Can use this payment method in administration panel?
     */
    protected $_canUseInternal          = true;

    /**
     * Can show this payment method as an option on checkout payment page?
     */
    protected $_canUseCheckout          = true;

    /**
     * Is this payment method suitable for multi-shipping checkout?
     */
    protected $_canUseForMultishipping  = true;

    /**
     * Can save credit card information for future processing?
     */
    protected $_canSaveCc = true;

    /** @var bool */
    protected $_isUsingClientSideEncryption;
    /** @var Radial_CreditCard_Helper_Data */
    protected $_helper;
    /** @var Radial_Core_Helper_Data */
    protected $_coreHelper;
    /** @var Radial_Core_Helper_Http */
    protected $_httpHelper;
    /** @var Radial_Payments_Helper_Data */
    protected $_paymentsHelper;

    /** @var EbayEnterprise_MageLog_Helper_Data */
    protected $_logger;

    /** @var LoggerInterface */
    protected $_apiLogger;

    /** @var EbayEnterprise_MageLog_Helper_Context */
    protected $_context;
    
    /**
     * `__construct` overridden in Mage_Payment_Model_Method_Abstract as a no-op.
     * Override __construct here as the usual protected `_construct` is not called.
     * @param array $initParams May contain:
     *                          -  'helper' => Radial_CreditCard_Helper_Data
     *                          -  'core_helper' => Radial_Core_Helper_Data
     *                          -  'http_helper' => Radial_Core_Helper_Http
     *                          -  'payments_helper' => Radial_Payments_Helper_Data
     *                          -  'logger' => EbayEnterprise_MageLog_Helper_Data
     *                          -  'context' => EbayEnterprise_MageLog_Helper_Context
     *                          -  'api_logger' => LoggerInterface
     */
    public function __construct(array $initParams = [])
    {
        list(
            $this->_helper,
            $this->_coreHelper,
            $this->_httpHelper,
            $this->_paymentsHelper,
            $this->_logger,
            $this->_context,
            $this->_apiLogger
        ) = $this->_checkTypes(
            $this->_nullCoalesce($initParams, 'helper', Mage::helper('radial_creditcard')),
            $this->_nullCoalesce($initParams, 'core_helper', Mage::helper('radial_core')),
            $this->_nullCoalesce($initParams, 'http_helper', Mage::helper('radial_core/http')),
            $this->_nullCoalesce($initParams, 'payments_helper', Mage::helper('radial_payments')),
            $this->_nullCoalesce($initParams, 'logger', Mage::helper('ebayenterprise_magelog')),
            $this->_nullCoalesce($initParams, 'context', Mage::helper('ebayenterprise_magelog/context')),
            $this->_nullCoalesce($initParams, 'api_logger', Mage::helper('ebayenterprise_magelog'))
        );

        $this->_isUsingClientSideEncryption = 1;
    }
    /**
     * Type hinting for self::__construct $initParams
     * @param Radial_CreditCard_Helper_Data
     * @param Radial_Core_Helper_Data
     * @param Radial_Core_Helper_Http
     * @param Radial_Payments_Helper_Data
     * @param Mage_Checkout_Model_Session
     * @param EbayEnterprise_MageLog_Helper_Data
     * @param EbayEnterprise_MageLog_Helper_Context
     * @param LoggerInterface
     * @return array
     */
    protected function _checkTypes(
        Radial_CreditCard_Helper_Data $helper,
        Radial_Core_Helper_Data $coreHelper,
        Radial_Core_Helper_Http $httpHelper,
        Radial_Payments_Helper_Data $paymentsHelper,
        EbayEnterprise_MageLog_Helper_Data $logger,
        EbayEnterprise_MageLog_Helper_Context $context,
        LoggerInterface $apiLogger
    ) {
        return func_get_args();
    }
    /**
     * Return the value at field in array if it exists. Otherwise, use the
     * default value.
     * @param array      $arr
     * @param string|int $field Valid array key
     * @param mixed      $default
     * @return mixed
     */
    protected function _nullCoalesce(array $arr, $field, $default)
    {
        return isset($arr[$field]) ? $arr[$field] : $default;
    }
    /**
     * Get the session model to store checkout data in.
     * @return Mage_Checkout_Model_Session
     */
    protected function _getCheckoutSession()
    {
        return Mage::getSingleton('checkout/session');
    }
    /**
     * Override getting config data for the cctype configuration. Due to the
     * special requirements for what types are actually available (must be
     * mapped ROM tender type), when requesting configured cctypes, get the types
     * that are actually available.
     * @param string $field
     * @param int|string|null|Mage_Core_Model_Store $storeId
     * @return mixed
     */
    public function getConfigData($field, $storeId = null)
    {
        if ($field === 'cctypes') {
            return implode(',', array_keys($this->_helper->getAvailableCardTypes()));
        }
        return parent::getConfigData($field, $storeId);
    }
    /**
     * Assign post data to the payment info object.
     * @param array|Varien_Object $data Contains payment data submitted in checkout - Varien_Object in OPC, array otherwise
     * @return self
     */
    public function assignData($data)
    {
        parent::assignData($data);
        if (is_array($data)) {
            $data = Mage::getModel('Varien_Object', $data);
        }
        $this->getInfoInstance()->setCcLast4($data->getCcLast4());
        return $this;
    }
    /**
     * Validate card data.
     * @return self
     */
    public function validate()
    {
        // card type can and should always be validated as data is not encrypted
        $this->_validateCardType();

	$errorMsg = false;
	$ccType = '';
	$info = $this->getInfoInstance();
        if (strcmp($info->getCcType(), "MC") === 0 && !$this->_isUsingClientSideEncryption){
		//BIN Change for MC 
		/**
                 * to validate payment method is allowed for billing country or not
                 */
                $paymentInfo = $this->getInfoInstance();
         	if ($paymentInfo instanceof Mage_Sales_Model_Order_Payment) {
         	    $billingCountry = $paymentInfo->getOrder()->getBillingAddress()->getCountryId();
         	} else {
         	    $billingCountry = $paymentInfo->getQuote()->getBillingAddress()->getCountryId();
         	}
         	if (!$this->canUseForCountry($billingCountry)) {
         	    Mage::throwException(Mage::helper('payment')->__('Selected payment type is not allowed for billing country.'));
         	}
	
		$hashMC = '/^5[1-5][0-9]{14}$|^(222[1-8][0-9]{2}|2229[0-8][0-9]|22299[0-9]|22[3-9][0-9]{3}|2[3-6][0-9]{4}|27[01][0-9]{3}|2720[0-8][0-9]|27209[0-9])[0-9]{10}/';
		$hashMCCVV = '/^[0-9]{3}$|^[0-9]{4}$/';

		$ccNumber = $info->getCcNumber();
                // remove credit card number delimiters such as "-" and space
                $ccNumber = preg_replace('/[\-\s]+/', '', $ccNumber);

		if (!preg_match($hashMC, $ccNumber)) {
                        $errorMsg = Mage::helper('payment')->__('Credit card number mismatch with credit card type.');
                }	

		//validate credit card verification number
        	if ($errorMsg === false && $this->hasVerification()) {
			if (!$info->getCcCid() || !preg_match($hashMCCVV ,$info->getCcCid())){
                		$errorMsg = Mage::helper('payment')->__('Please enter a valid credit card verification number.');
            		}
        	}

		if ($ccType != 'SS' && !$this->_validateExpDate($info->getCcExpYear(), $info->getCcExpMonth())) {
	            $errorMsg = Mage::helper('payment')->__('Incorrect credit card expiration date.');
	        }

	        if($errorMsg){
	            Mage::throwException($errorMsg);
	        }

	        //This must be after all validation conditions
	        if ($this->getIsCentinelValidationEnabled()) {
	            $this->getCentinelValidator()->validate($this->getCentinelValidationData());
	        }

		return $this;
        } elseif ($this->_isUsingClientSideEncryption) {
            return $this->_validateWithEncryptedCardData();
        } else {
            return parent::validate();
        }
    }
    /**
     * Validate what data can still be validated.
     * @return self
     */
    protected function _validateWithEncryptedCardData()
    {
        $info = $this->getInfoInstance();
        return $this->_validateCountry($info)->_validateExpirationDate($info);
    }
    /**
     * Validate that the card type is one of the supported types.
     * @return self
     * @throws Radial_CreditCard_Exception If card type is not supported
     */
    protected function _validateCardType()
    {
        if (!in_array($this->getInfoInstance()->getCcType(), array_keys($this->_helper->getAvailableCardTypes()))) {
            throw Mage::exception('Radial_CreditCard', self::INVALID_CARD_TYPE);
        }
        return $this;
    }
    /**
     * Validate payment method is allowed for the customer's billing address country.
     * @param Mage_Payment_Model_Info $info
     * @return self
     */
    protected function _validateCountry(Mage_Payment_Model_Info $info)
    {
        /**
         * Get the order when dealing with an order payment, quote otherwise.
         * @see Mage_Payment_Model_Method_Abstract
         */
        if ($info instanceof Mage_Sales_Model_Order_Payment) {
            $billingCountry = $info->getOrder()->getBillingAddress()->getCountryId();
        } else {
            $billingCountry = $info->getQuote()->getBillingAddress()->getCountryId();
        }
        if (!$this->canUseForCountry($billingCountry)) {
            throw Mage::exception('Radial_CreditCard', $this->_helper->__(self::METHOD_NOT_ALLOWED_FOR_COUNTRY));
        }
        return $this;
    }
    /**
     * Validate the card expiration date.
     * @param Mage_Payment_Model_Info $info
     * @return self
     */
    protected function _validateExpirationDate(Mage_Payment_Model_Info $info)
    {
        if (!$this->_validateExpDate($info->getCcExpYear(), $info->getCcExpMonth())) {
            throw Mage::exception('Radial_CreditCard', $this->_helper->__(self::INVALID_EXPIRATION_DATE));
        }
        return $this;
    }
    /**
     * Authorize payment abstract method
     *
     * @param Varien_Object $payment
     * @param float         $amount unused; only here to maintain signature
     * @return self
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function authorize(Varien_Object $payment, $amount)
    {
        $api = $this->_getAuthApi($payment);
        $this->_prepareAuthRequest($api, $payment);
        Mage::dispatchEvent('radial_creditcard_auth_request_send_before', [
            'payload' => $api->getRequestBody(),
            'payment' => $payment,
        ]);
        // Log the request instead of expecting the SDK to have logged it.
        // Allows the data to be properly scrubbed of any PII or other sensitive
        // data prior to writing the log files.
        $logMessage = 'Sending credit card auth request.';
        $cleanedRequestXml = $this->_helper->cleanPaymentsXml($api->getRequestBody()->serialize());
        $this->_logger->debug($logMessage, $this->_context->getMetaData(__CLASS__, ['request_body' => $cleanedRequestXml]));
        $this->_sendRequest($api);
        // Log the response instead of expecting the SDK to have logged it.
        // Allows the data to be properly scrubbed of any PII or other sensitive
        // data prior to writing the log files.
        $logMessage = 'Received credit card auth response.';
        $cleanedResponseXml = $this->_helper->cleanPaymentsXml($api->getResponseBody()->serialize());
        $this->_logger->debug($logMessage, $this->_context->getMetaData(__CLASS__, ['response_body' => $cleanedResponseXml]));
        $this->_handleAuthResponse($api, $payment);
        return $this;
    }
    /**
     * Fill out the request payload with payment data and update the API request
     * body with the complete request.
     * @param Api\IBidirectionalApi $api
     * @param Varien_Object         $payment Most likely a Mage_Sales_Model_Order_Payment
     * @return self
     */
    protected function _prepareAuthRequest(Api\IBidirectionalApi $api, Varien_Object $payment)
    {
        $request = $api->getRequestBody();
        $order = $payment->getOrder();
        $billingAddress = $order->getBillingAddress();
        $shippingAddress = $order->getIsVirtual() ? $billingAddress : $order->getShippingAddress();
        $request
            ->setIsEncrypted(true)
            ->setRequestId($this->_coreHelper->generateRequestId('CCA-'))
            ->setOrderId($order->getIncrementId())
            ->setPanIsToken(false)
            ->setCardNumber($payment->getCcNumber())
            ->setExpirationDate($this->_coreHelper->getNewDateTime(sprintf('%s-%s', $payment->getCcExpYear(), $payment->getCcExpMonth())))
            ->setCardSecurityCode($payment->getCcCid())
            ->setAmount($payment->getBaseAmountAuthorized())
            ->setCurrencyCode(Mage::app()->getStore()->getBaseCurrencyCode())
            ->setEmail($order->getCustomerEmail())
            ->setIp($this->_httpHelper->getRemoteAddr())
            ->setBillingFirstName($billingAddress->getFirstname())
            ->setBillingLastName($billingAddress->getLastname())
            ->setBillingPhone($billingAddress->getTelephone())
            ->setBillingLines($billingAddress->getStreet(-1)) // returns all lines, \n separated
            ->setBillingCity($billingAddress->getCity())
            ->setBillingMainDivision($billingAddress->getRegionCode())
            ->setBillingCountryCode($billingAddress->getCountry())
            ->setBillingPostalCode($billingAddress->getPostcode())
            ->setShipToFirstName($shippingAddress->getFirstname())
            ->setShipToLastName($shippingAddress->getLastname())
            ->setShipToPhone($shippingAddress->getTelephone())
            ->setShipToLines($shippingAddress->getStreet(-1)) // returns all lines, \n separated
            ->setShipToCity($shippingAddress->getCity())
            ->setShipToMainDivision($shippingAddress->getRegionCode())
            ->setShipToCountryCode($shippingAddress->getCountry())
            ->setShipToPostalCode($shippingAddress->getPostcode())
            ->setIsRequestToCorrectCVVOrAVSError($this->_getIsCorrectionNeededForPayment($payment))
	    ->setSchemaVersion(1.1);
        return $this;
    }
    /**
     * Check for the response to be valid.
     * @param Payload\Payment\ICreditCardAuthReply $response
     * @return self
     */
    protected function _validateAuthResponse(Payload\Payment\ICreditCardAuthReply $response)
    {
    	// if auth was a complete success or declined fraud, accept the response and move on
        if ( $response->getRiskResponseCode() === self::PAYMENT_RESPONSE_CODE_APPROVED || $response->getRiskResponseCode() === self::PAYMENT_RESPONSE_CODE_DECLR ) {
            Mage::getSingleton('core/session')->setAVSCount(0);
            Mage::getSingleton('core/session')->setDECLFCount(0);
            Mage::getSingleton('core/session')->setDECLCount(0);
            return $this;
        }
        if( $response->getRiskResponseCode() === self::PAYMENT_RESPONSE_CODE_AVSCSC )
        {
                $this->_failPaymentRequest(Mage::getStoreConfig('payment/radial_creditcard/paymentavscsc_error'), 'payment');
        }
        if ( $response->getRiskResponseCode() === self::PAYMENT_RESPONSE_CODE_AVS)
        {
	    // if AVS correction is needed, redirect to billing address step
            $avsLimit = Mage::getStoreConfig('payment/radial_creditcard/paymentavs');
            if( $avsLimit === 0 )
            {   
                Mage::getSingleton('core/session')->setAVSCount(0);
                Mage::getSingleton('core/session')->setDECLFCount(0);
                Mage::getSingleton('core/session')->setDECLCount(0);
                return $this;
            }   
            // Always fail if less than 0
            if( $avsLimit < 0 )
            {
                $this->_failPaymentRequest(Mage::getStoreConfig('payment/radial_creditcard/paymentavs_error'), 'billing');
            }
            $prevAVS = Mage::getSingleton('core/session')->getAVSCount();
            if( $prevAVS < $avsLimit )
            {
                $prevAVS++;
                Mage::getSingleton('core/session')->setAVSCount($prevAVS);
                $this->_failPaymentRequest(Mage::getStoreConfig('payment/radial_creditcard/paymentavs_error'), 'billing');
            } else {
                Mage::getSingleton('core/session')->setAVSCount(0);
                Mage::getSingleton('core/session')->setDECLFCount(0);
                Mage::getSingleton('core/session')->setDECLCount(0);
                return $this;
            }
        }
        // if CVV correction is needed, redirect to payment method step
        if ($response->getRiskResponseCode() === self::PAYMENT_RESPONSE_CODE_CSC) {
            $this->_failPaymentRequest(Mage::getStoreConfig('payment/radial_creditcard/paymentcsc_error'), 'payment');
        }
        // if AVS & CVV did not fail but was not a complete success, see if the
        // request is at least acceptable - timeout perhaps - and if so, take it
        // and allow order submit to continue
        if ( $response->getRiskResponseCode() === self::PAYMENT_RESPONSE_CODE_TIMEOUT ) {
            Mage::getSingleton('core/session')->setAVSCount(0);
            Mage::getSingleton('core/session')->setDECLFCount(0);
            Mage::getSingleton('core/session')->setDECLCount(0);
            return $this;
        }
        if ( $response->getRiskResponseCode() === self::PAYMENT_RESPONSE_CODE_DECL )
        {
	    // DECL from Payment Response
            $declLimit = Mage::getStoreConfig('payment/radial_creditcard/paymentdecl');
            
	    if( $declLimit === 0 )
            {
		Mage::getSingleton('core/session')->setAVSCount(0);
                Mage::getSingleton('core/session')->setDECLFCount(0);
                Mage::getSingleton('core/session')->setDECLCount(0);
                return $this;
	    }
	    if( $declLimit < 0 )
	    {
		// fail if less then 0
		$this->_failPaymentRequest(Mage::getStoreConfig('payment/radial_creditcard/paymentdecl_error'), 'billing');
	    }
	    $prevDECL = Mage::getSingleton('core/session')->getDECLCount();
            if( $prevDECL < $declLimit )
            {
            	$prevDECL++;
		Mage::getSingleton('core/session')->setDECLCount($prevDECL);
                $this->_failPaymentRequest(Mage::getStoreConfig('payment/radial_creditcard/paymentdecl_error'), 'billing');
            } else {
                Mage::getSingleton('core/session')->setAVSCount(0);
                Mage::getSingleton('core/session')->setDECLFCount(0);
                Mage::getSingleton('core/session')->setDECLCount(0);
                return $this;
            }
        }
        if ( $response->getRiskResponseCode() === self::PAYMENT_RESPONSE_CODE_DECLF )
        {
	    // DECLF from Payment Response
            $declfLimit = Mage::getStoreConfig('payment/radial_creditcard/paymentdeclf');
                
	    if( $declfLimit === 0 )
            {
                Mage::getSingleton('core/session')->setAVSCount(0);
                Mage::getSingleton('core/session')->setDECLFCount(0);
                Mage::getSingleton('core/session')->setDECLCount(0);
                return $this;
            }
            if( $declfLimit < 0 )
            {
                // fail if less then 0
                $this->_failPaymentRequest(Mage::getStoreConfig('payment/radial_creditcard/paymentdeclf_error'), 'billing');
            }
	    $prevDECLF = Mage::getSingleton('core/session')->getDECLFCount();
            if( $prevDECLF < $declfLimit )
            {
                $prevDECLF++;
                Mage::getSingleton('core/session')->setDECLFCount($prevDECLF);
                $this->_failPaymentRequest(Mage::getStoreConfig('payment/radial_creditcard/paymentdeclf_error'), 'billing');
            } else {
                Mage::getSingleton('core/session')->setAVSCount(0);
                Mage::getSingleton('core/session')->setDECLFCount(0);
                Mage::getSingleton('core/session')->setDECLCount(0);
                return $this;
            }
        }
        // auth failed for some other reason, possibly declined, making it unacceptable
        // send user to payment step of checkout with an error message
        $this->_failPaymentRequest(self::CREDITCARD_FAILED_MESSAGE, 'payment');
        return $this;
    }
    /**
     * Update the order payment and quote payment with details from the CC auth
     * request/response.
     * @param Varien_Data $payment
     * @param Payload\Payment\ICreditCardAuthRequest $request
     * @param Payload\Payment\ICreditCardAuthReply   $response
     * @return self
     */
    protected function _updatePaymentsWithAuthData(
        Varien_Object $payment,
        Payload\Payment\ICreditCardAuthRequest $request,
        Payload\Payment\ICreditCardAuthReply $response
    ) {
        return $this
            ->_updatePaymentAuth($payment, $request, $response)
            ->_updatePaymentAuth($payment->getOrder()->getQuote()->getPayment(), $request, $response);
    }
    /**
     * Update the payment with details from the CC Auth Request and Reply
     * @param Varien_Object                          $payment
     * @param Payload\Payment\ICreditCardAuthRequest $request
     * @param Payload\Payment\ICreditCardAuthReply   $response
     * @return self
     */
    public function _updatePaymentAuth(
        Varien_Object $payment,
        Payload\Payment\ICreditCardAuthRequest $request,
        Payload\Payment\ICreditCardAuthReply $response
    ) {
        $correctionRequired = $response->getIsAVSCorrectionRequired() || $response->getIsCVV2CorrectionRequired();
        $payment->setAdditionalInformation([
            'request_id' => $request->getRequestId(),
            'response_code' => $response->getResponseCode(),
            'pan_is_token' => $response->getPanIsToken(),
            'bank_authorization_code' => $response->getBankAuthorizationCode(),
            'cvv2_response_code' => $response->getCVV2ResponseCode(),
            'avs_response_code' => $response->getAVSResponseCode(),
            'phone_response_code' => $response->getPhoneResponseCode(),
            'name_response_code' => $response->getNameResponseCode(),
            'email_response_code' => $response->getEmailResponseCode(),
            'currency_code' => $response->getCurrencyCode(),
            'tender_type' => $this->_helper->getTenderTypeForCcType($payment->getCcType()),
            'is_correction_required' => $correctionRequired,
            'last4_to_correct' => $correctionRequired ? $payment->getCcLast4() : null,
	    'risk_response_code' => $response->getRiskResponseCode(),
        ])
            ->setAmountAuthorized($response->getAmountAuthorized())
            ->setBaseAmountAuthorized($response->getAmountAuthorized())
            ->setCcNumberEnc($payment->encrypt($response->getCardNumber()));
        return $this;
    }
    /**
     * Check if the payment needs to be corrected - payment additional information
     * would have the is_correction_required flag set to true and the cc last 4
     * for the current payment would match the last4_to_correct payment
     * additional information.
     * @param Varien_Object $payment
     * @return bool
     */
    protected function _getIsCorrectionNeededForPayment(Varien_Object $payment)
    {
        return $payment->getAdditionalInformation('is_correction_required')
        && $payment->getCcLast4() === $payment->getAdditionalInformation('last4_to_correct');
    }
    /**
     * Set the checkout session's goto section to the provided step.
     * One of: 'login', 'billing', 'shipping', 'shipping_method', 'payment', 'review'
     * @param string $step Step in checkout
     * @return self
     */
    public function _setCheckoutStep($step)
    {
        $this->_getCheckoutSession()->setGotoSection($step);
        return $this;
    }

    /**
     * Fail the auth request by setting a checkout step to return to and throwing
     * an exception.
     * @see self::_setCheckoutStep for available checkout steps to return to
     * @param Varien_Object $payment
     * @throws Mage_Core_Exception
     */
    protected function _failConfirmFundsRequest(Varien_Object $payment)
    {
        /** @var Mage_Sales_Model_Order $order */
        $order = $payment->getOrder();
        $errorMessage = $this->_helper->__(self::CREDITCARD_DENIED_MESSAGE);
        $this->_paymentsHelper->failConfirmFundsRequest($order, $errorMessage);
        throw Mage::exception('Radial_CreditCard', $errorMessage);
    }

    /**
     * Fail the auth request by setting a checkout step to return to and throwing
     * an exception.
     * @see self::_setCheckoutStep for available checkout steps to return to
     * @param Varien_Object $payment
     * @throws Mage_Core_Exception
     */
    protected function _confirmFundsRequestTimeout()
    {
        $errorMessage = $this->_helper->__(self::CREDITCARD_TIMEOUT_MESSAGE);
        throw Mage::exception('Radial_CreditCard', $errorMessage);
    }

    /**
     * Fail the auth request by setting a checkout step to return to and throwing
     * an exception.
     * @see self::_setCheckoutStep for available checkout steps to return to
     * @param string $errorMessage
     * @param string $returnStep Step of checkout to send the user to
     * @throws Mage_Core_Exception
     */
    protected function _failPaymentRequest($errorMessage, $returnStep = 'payment')
    {
        $this->_setCheckoutStep($returnStep);
        throw Mage::exception('Radial_CreditCard', $this->_helper->__($errorMessage));
    }
    /**
     * Get the API SDK for the payment auth request.
     * @param Varien_Object $payment
     * @return Api\IBidirectionalApi
     */
    protected function _getAuthApi(Varien_Object $payment)
    {
        $config = $this->_helper->getConfigModel();
        return $this->_getApi(
            $config->apiService,
            $config->apiAuthorize,
            [$this->_helper->getTenderTypeForCcType($payment->getCcType())]
        );
    }

    /**
     * Get the API SDK for the payment settlement request.
     * @param Mage_Sales_Model_Order_Invoice
     * @return Api\IBidirectionalApi
     * @throws Mage_Core_Exception
     */
    protected function _getSettlementApi(Mage_Sales_Model_Order_Invoice $invoice)
    {
        $order = $invoice->getOrder();
        $payment = $order->getPayment();
        $config = $this->_helper->getConfigModel();
        return $this->_getApi(
            $config->apiService,
            $config->apiSettlement,
            [$this->_helper->getTenderTypeForCcType($payment->getCcType())]
        );
    }

    /**
     * Get the API SDK for the payment settlement request.
     * @param Mage_Sales_Model_Order_Creditmemo
     * @param Mage_Sales_Model_Payment
     * @return Api\IBidirectionalApi
     * @throws Mage_Core_Exception
     */
    protected function _getCreditmemoApi(Mage_Sales_Model_Order_Creditmemo $creditmemo, Mage_Sales_Model_Order_Payment $payment)
    {
        $config = $this->_helper->getConfigModel();
        return $this->_getApi(
            $config->apiService,
            $config->apiSettlement,
            [$this->_helper->getTenderTypeForCcType($payment->getCcType())]
        );
    }

    /**
     * Get the API SDK for the payment auth cancel request.
     * @param Varien_Object $payment
     * @return Api\IBidirectionalApi
     */
    protected function _getAuthCancelApi(Varien_Object $payment)
    {
        $config = $this->_helper->getConfigModel();

        return $this->_getApi(
            $config->apiService,
            $config->apiAuthCancel,
            [$this->_helper->getTenderTypeForCcType($payment->getCcType())]
        );
    }
    /**
     * Get the API SDK.
     * @param Varien_Object $payment
     * @return Api\IBidirectionalApi
     */
    protected function _getApi($service, $operation, array $endpointParams = [])
    {
        return $this->_coreHelper->getSdkApi(
            $service,
            $operation,
            $endpointParams,
            // Provide a logger specifically for logging data within the SDK.
            // Logger provided should prevent the logging of any PII within
            // the SDK.
            $this->_apiLogger
        );
    }
    /**
     * Make the API request and handle any exceptions.
     * @param ApiIBidirectionalApi $api
     * @return self
     */
    protected function _sendRequest(Api\IBidirectionalApi $api)
    {
        $logger = $this->_logger;
        $logContext = $this->_context;
        try {
            $api->send();
        } catch (InvalidPayload $e) {
            // Invalid payloads cannot be valid - log the error and fail the auth
            $logMessage = 'Invalid payload for credit card service. See exception log for more details.';
            $logger->warning($logMessage, $logContext->getMetaData(__CLASS__, ['exception_message' => $e->getMessage()]));
            $logger->logException($e, $logContext->getMetaData(__CLASS__, [], $e));
            $this->_failPaymentRequest(self::CREDITCARD_FAILED_MESSAGE);
        } catch (NetworkError $e) {
            // Can't accept a request that could not be made successfully - log the error and fail the request.
            $logMessage = 'Caught a network error sending credit card service. See exception log for more details.';
            $logger->warning($logMessage, $logContext->getMetaData(__CLASS__, ['exception_message' => $e->getMessage()]));
            $logger->logException($e, $logContext->getMetaData(__CLASS__, [], $e));
            $this->_failPaymentRequest(self::CREDITCARD_FAILED_MESSAGE);
        } catch (UnsupportedOperation $e) {
            $logMessage = 'The credit card service operation is unsupported in the current configuration. See exception log for more details.';
            $logger->warning($logMessage, $logContext->getMetaData(__CLASS__, ['exception_message' => $e->getMessage()]));
            $logger->logException($e, $logContext->getMetaData(__CLASS__, [], $e));
            throw $e;
        } catch (UnsupportedHttpAction $e) {
            $logMessage = 'The credit card service operation is configured with an unsupported HTTP action. See exception log for more details.';
            $logger->warning($logMessage, $logContext->getMetaData(__CLASS__, ['exception_message' => $e->getMessage()]));
            $logger->logException($e, $logContext->getMetaData(__CLASS__, [], $e));
            throw $e;
        } catch (Exception $e) {
            $logMessage = 'Encountered unexpected exception from the credit card service operation. See exception log for more details.';
            $logger->warning($logMessage, $logContext->getMetaData(__CLASS__, ['exception_message' => $e->getMessage()]));
            $logger->logException($e, $logContext->getMetaData(__CLASS__, [], $e));
            throw $e;
        }
        return $this;
    }
    /**
     * Update payment objects with details of the auth request and response. Validate
     * that a successful response was received.
     * @param ApiIBidirectionalApi $api
     * @param Varien_Object        $payment
     * @return self
     */
    protected function _handleAuthResponse(Api\IBidirectionalApi $api, Varien_Object $payment)
    {
        $request = $api->getRequestBody();
        $response = $api->getResponseBody();
        return $this->_updatePaymentsWithAuthData($payment, $request, $response)
            ->_validateAuthResponse($response);
    }
    /**
     * Send confirm funds request
     *
     * @param Mage_Sales_Model_Order_Payment $payment
     * @param float $amount
     * @return self
     * @throws Mage_Core_Exception
     */
    public function capture(Varien_Object $payment, $amount)
    {
	$order = $payment->getOrder();
	$amount = $payment->getAmountAuthorized();
        $notCapturable = 1;

        if ($order->hasInvoices()) {
          $oInvoiceCollection = $order->getInvoiceCollection();
          foreach ($oInvoiceCollection as $oInvoice) {
                if( $oInvoice->getRequestedCaptureCase() != Mage_Sales_Model_Order_Invoice::NOT_CAPTURE && $oInvoice->getState() != Mage_Sales_Model_Order_Invoice::STATE_CANCELED && $oInvoice->getState() != Mage_Sales_Model_Order_Invoice::STATE_PAID )
		{
                        $notCapturable = 0;
                }
          }

          if( $notCapturable )
          {
                return $this;
          }
        }

        if ($amount <= 0) {
            Mage::throwException($this->_helper->__('Invalid amount to capture.'));
        }
        $api = $this->_getConfirmFundsApi($payment);
        $this->_prepareConfirmFundsRequest($api, $payment, $amount);
        Mage::dispatchEvent('radial_creditcard_confirm_funds_send_before', [
            'payload' => $api->getRequestBody(),
            'payment' => $payment,
        ]);
        // Log the request instead of expecting the SDK to have logged it.
        // Allows the data to be properly scrubbed of any PII or other sensitive
        // data prior to writing the log files.
        $logMessage = 'Sending credit card confirm funds request.';
        $cleanedRequestXml = $this->_helper->cleanPaymentsXml($api->getRequestBody()->serialize());
        $this->_logger->debug($logMessage, $this->_context->getMetaData(__CLASS__, ['request_body' => $cleanedRequestXml]));
        $this->_sendRequest($api);
        // Log the response instead of expecting the SDK to have logged it.
        // Allows the data to be properly scrubbed of any PII or other sensitive
        // data prior to writing the log files.
        $logMessage = 'Received credit card confirm funds response.';
        $cleanedResponseXml = $this->_helper->cleanPaymentsXml($api->getResponseBody()->serialize());
        $this->_logger->debug($logMessage, $this->_context->getMetaData(__CLASS__, ['response_body' => $cleanedResponseXml]));
        $this->_handleConfirmFundsResponse($api, $payment);
        return $this;
    }
    /**
     * Update payment objects with details of the confirm request and response. Validate
     * that a successful response was received.
     * @param Api\IBidirectionalApi $api
     * @param Varien_Object        $payment
     * @return self
     */
    protected function _handleConfirmFundsResponse(Api\IBidirectionalApi $api, Varien_Object $payment)
    {
        /** @var Payload\Payment\ConfirmFundsReply $response */
        $response = $api->getResponseBody();
        return $this->_validateConfirmFundsResponse($response, $payment);
    }
    /**
     * Check for the response to be valid.
     * @param Payload\Payment\IConfirmFundsReply $response
     * @return self
     */
    protected function _validateConfirmFundsResponse(Payload\Payment\IConfirmFundsReply $response, Varien_Object $payment)
    {
        // if auth was a complete success, accept the response and move on
        if ($response->isSuccess()) {
            return $this;
        }
        // if auth was a complete success, accept the response and move on
        if ($response->isTimeout()) {
            $this->_confirmFundsRequestTimeout();
            return $this;
        }
        // auth failed for some other reason, possibly declined, making it unacceptable
        // send user to payment step of checkout with an error message
        $this->_failConfirmFundsRequest($payment);
        return $this;
    }
    /**
     * Fill out the request payload with payment data and update the API request
     * body with the complete request.
     * @param Api\IBidirectionalApi $api
     * @param Varien_Object $payment Most likely a Mage_Sales_Model_Order_Payment
     * @param $amount
     * @return static
     */
    protected function _prepareConfirmFundsRequest(Api\IBidirectionalApi $api, Varien_Object $payment, $amount)
    {
        /** @var Payload\Payment\ConfirmFundsRequest $request */
        $request = $api->getRequestBody();
        /** @var Mage_Sales_Model_Order $order */
        $order = $payment->getOrder();
        $request
            ->setPanIsToken(true)
            ->setAmount((float)$amount)
            ->setCurrencyCode(Mage::app()->getStore()->getBaseCurrencyCode())
            ->setCardNumber($payment->getCcNumber())
            ->setRequestId($this->_coreHelper->generateRequestId('CCA-'))
            ->setOrderId($order->getIncrementId())
	    ->setPerformReauthorization(true);
        return $this;
    }
    /**
     * Get the API SDK for the payment confirm funds request.
     * @param Varien_Object $payment
     * @return Api\IBidirectionalApi
     */
    protected function _getConfirmFundsApi(Varien_Object $payment)
    {
        $config = $this->_helper->getConfigModel();
        return $this->_getApi(
            $config->apiService,
            $config->apiConfirmFunds,
            [$this->_helper->getTenderTypeForCcType($payment->getCcType())]
        );
    }
    /**
     * Invoice has been created.
     * @param Mage_Sales_Model_Order_Payment $payment
     * @param float $amount
     * @return self
     * @throws Mage_Core_Exception
     */
    public function processInvoice($invoice, $payment)
    {
        // The invoice must be saved before continuing.
        $invoice->save();
        parent::processInvoice($invoice, $payment);
        try {
            $api = $this->_getSettlementApi($invoice);
            $this->_prepareDebitRequest($api, $invoice);
            Mage::dispatchEvent('radial_creditcard_settlement_debit_request_send_before', [
                'payload' => $api->getRequestBody(),
                'invoice' => $invoice,
            ]);
            // Log the request instead of expecting the SDK to have logged it.
            // Allows the data to be properly scrubbed of any PII or other sensitive
            // data prior to writing the log files.
            $logMessage = 'Sending credit card settlement debit request.';
            $cleanedRequestXml = $this->_helper->cleanPaymentsXml($api->getRequestBody()->serialize());
            $this->_logger->debug($logMessage, $this->_context->getMetaData(__CLASS__, ['request_body' => $cleanedRequestXml]));
            $this->_sendRequest($api);
            // Log the response instead of expecting the SDK to have logged it.
            // Allows the data to be properly scrubbed of any PII or other sensitive
            // data prior to writing the log files.
            $logMessage = 'Received credit card settlement debit response.';
            $cleanedResponseXml = $this->_helper->cleanPaymentsXml($api->getResponseBody()->serialize());
            $this->_logger->debug($logMessage, $this->_context->getMetaData(__CLASS__, ['response_body' => $cleanedResponseXml]));
            $this->_handleDebitResponse($api, $invoice);
        } catch (Exception $e) {
            // settlement must be allowed to fail
            // set invoice status as OPEN to trigger a  retry and notify admin
            $invoice->setIsPaid(false);

	    $retry = $invoice->getDeliveryStatus();
	    $retryN = $retry + 1;
            $invoice->setDeliveryStatus($retryN);
	    $invoice->save();	   

            $errorMessage = $this->_helper->__(self::SETTLEMENT_FAILED_MESSAGE);
            $this->getSession()->addNotice($errorMessage);
            $this->_logger->logException($e, $this->_context->getMetaData(__CLASS__, [], $e));

	    $paymentsEmailA = explode(',', Mage::getStoreConfig('radial_core/payments/payments_email'));
            if( !empty($paymentsEmailA) )
            {
            	foreach( $paymentsEmailA as $paymentsEmail )
                { 
                	$paymentsName = Mage::app()->getStore()->getName() . ' - ' . 'Payments Admin';
                        $emailTemplate  = Mage::getModel('core/email_template')->loadDefault('custom_email_template2');

                        //Create an array of variables to assign to template
                        $emailTemplateVariables = array();
                        $emailTemplateVariables['myvar1'] = gmdate("Y-m-d\TH:i:s\Z");
                        $emailTemplateVariables['myvar2'] = $e->getMessage();
                        $emailTemplateVariables['myvar3'] = $e->getTraceAsString();
                        $emailTemplateVariables['myvar4'] = htmlspecialchars($cleanedResponseXml);

                        $processedTemplate = $emailTemplate->getProcessedTemplate($emailTemplateVariables);
                        //Sending E-Mail to Payments Admin Email.
                        $mail = Mage::getModel('core/email')
                        	->setToName($paymentsName)
                                ->setToEmail($paymentsEmail)
                                ->setBody($processedTemplate)
                                ->setSubject('Payments - Settlement - Exception Report From: '. __CLASS__ . ' on ' . gmdate("Y-m-d\TH:i:s\Z") . ' UTC')
                                ->setFromEmail(Mage::getStoreConfig('trans_email/ident_general/email'))
                                ->setFromName($paymentsName)
                                ->setType('html');
                        try {
                        	//Confimation E-Mail Send
                                $mail->send();
                        }
                        catch(Exception $error)
                        { 
                        	$logMessage = sprintf('[%s] Error Sending Email: %s', __CLASS__, $error->getMessage());
                                Mage::log($logMessage, Zend_Log::ERR);
                        }
                }
            }
        }
        return $this;
    }

    /**
     * Settlement debit can be made when payment method is
     * allowed to capture, invoice is marked as PAID to confirm
     * funds, and the capture type is CAPTURE_ONLINE.
     * @param Mage_Sales_Model_Order_Invoice
     * @return bool
     */
    protected function canProcessInvoice(Mage_Sales_Model_Order_Invoice $invoice)
    {
        return $invoice->getOrder()->getPayment()->canCapture() &&
        $invoice->getState() == Mage_Sales_Model_Order_Invoice::STATE_PAID &&
        $invoice->getRequestedCaptureCase() == Mage_Sales_Model_Order_Invoice::CAPTURE_ONLINE;
    }
    /**
     * Fill out the request payload with payment data and update the API request
     * body with the complete request.
     * @param Api\IBidirectionalApi $api
     * @param Mage_Sales_Model_Order_Invoice $invoice
     * @param string $type
     * @return static
     */
    protected function _prepareDebitRequest(Api\IBidirectionalApi $api, Mage_Sales_Model_Order_Invoice $invoice)
    {
        /** @var Payload\Payment\PaymentSettlementRequest $request */
        $request = $api->getRequestBody();
        $order = $invoice->getOrder();
        $payment = $order->getPayment();
        $request
            ->setPanIsToken(true)
            ->setAmount((float)$invoice->getBaseGrandTotal())
            ->setCurrencyCode(Mage::app()->getStore()->getBaseCurrencyCode())
            ->setTaxAmount((float)$invoice->getTaxAmount())
            ->setClientContext($invoice->getTransactionId())
            ->setCardNumber($payment->getCcNumber())
            ->setRequestId($this->_coreHelper->generateRequestId('CCA-'))
            ->setSettlementType(self::SETTLEMENT_TYPE_CAPTURE)
            ->setFinalDebit($this->_paymentsHelper->isFinalDebit($order) ? 1 : 0)
            ->setInvoiceId($invoice->getIncrementId())
            ->setOrderId($order->getIncrementId());
        return $this;
    }
    /**
     * Fill out the request payload with payment data and update the API request
     * body with the complete request.
     * @param Api\IBidirectionalApi $api
     * @param Varien_Object $payment Most likely a Mage_Sales_Model_Order_Payment
     * @param string $type
     * @return static
     */
    protected function _prepareAuthCancelRequest(Api\IBidirectionalApi $api, Varien_Object $payment)
    {
        /** @var Payload\Payment\PaymentSettlementRequest $request */
        $request = $api->getRequestBody();
        /** @var Mage_Sales_Model_Order $order */
        $order = $payment->getOrder();
 	$amountAuthorized = $payment->getAmountAuthorized();

        $request
            ->setPanIsToken(true)
            ->setCardNumber($payment->getCcNumber())
            ->setRequestId($this->_coreHelper->generateRequestId('CCA-'))
            ->setAmount((float)$amountAuthorized)
            ->setCurrencyCode(Mage::app()->getStore()->getBaseCurrencyCode())
            ->setOrderId($order->getIncrementId());
        return $this;
    }
    /**
     * @param Api\IBidirectionalApi $api
     * @param Mage_Sales_Model_Order_Invoice
     * @return self
     */
    protected function _handleDebitResponse(Api\IBidirectionalApi $api, Mage_Sales_Model_Order_Invoice $invoice)
    {
        $invoice->setState(Mage_Sales_Model_Order_Invoice::STATE_PAID);
	$invoice->pay();
        return $this;
    }
    /**
     * @param Api\IBidirectionalApi $api
     * @param Varien_Object        $payment
     * @return self
     */
    protected function _handleAuthCancelResponse(Api\IBidirectionalApi $api, Varien_Object $payment)
    {
        return $this;
    }

    /**
     * @param Api\IBidirectionalApi $api
     * @param Mage_Sales_Model_Order_Creditmemo
     * @param Mage_Sales_Model_Order_Payment
     * @return static
     */
    protected function _handleCreditResponse(Api\IBidirectionalApi $api, Mage_Sales_Model_Order_Creditmemo $creditmemo, Mage_Sales_Model_Order_Payment $payment)
    {
        $creditmemo->setState(Mage_Sales_Model_Order_Creditmemo::STATE_REFUNDED);
        return $this;
    }
    /**
     * Void the payment
     *
     * @param Varien_Object $payment
     * @return self
     * @throws Mage_Core_Exception
     */
    public function void(Varien_Object $payment)
    {
	if (!$payment->getParentTransactionId()) {
            Mage::throwException($this->_helper->__('Invalid transaction ID.'));
        }
        $api = $this->_getAuthCancelApi($payment);
        $this->_prepareAuthCancelRequest($api, $payment);
        Mage::dispatchEvent('radial_creditcard_auth_cancel_request_send_before', [
            'payload' => $api->getRequestBody(),
            'payment' => $payment,
        ]);
        // Log the request instead of expecting the SDK to have logged it.
        // Allows the data to be properly scrubbed of any PII or other sensitive
        // data prior to writing the log files.
        $logMessage = 'Sending credit card auth cancel request.';
        $cleanedRequestXml = $this->_helper->cleanPaymentsXml($api->getRequestBody()->serialize());
        $this->_logger->debug($logMessage, $this->_context->getMetaData(__CLASS__, ['request_body' => $cleanedRequestXml]));
        $this->_sendRequest($api);
        // Log the response instead of expecting the SDK to have logged it.
        // Allows the data to be properly scrubbed of any PII or other sensitive
        // data prior to writing the log files.
        $logMessage = 'Received credit card auth cancel response.';
        $cleanedResponseXml = $this->_helper->cleanPaymentsXml($api->getResponseBody()->serialize());
        $this->_logger->debug($logMessage, $this->_context->getMetaData(__CLASS__, ['response_body' => $cleanedResponseXml]));
        $this->_handleAuthCancelResponse($api, $payment);
        return $this;
    }
    /**
     * Refund the amount
     *
     * @param Varien_Object $payment
     * @param float $amount
     * @return self
     */
    public function refund(Varien_Object $payment, $amount)
    {
        if ($amount <= 0) {
            Mage::throwException($this->_helper->__('Invalid amount for refund.'));
        }
        if (!$payment->getParentTransactionId()) {
            Mage::throwException($this->_helper->__('Invalid transaction ID.'));
        }
        // no need to confirm valid auth before refunding
        // but wait until processCreditmemo is called
        return $this;
    }
    /**
     * Create a settlement to credit the given amount
     *
     * @param Mage_Sales_Model_Order_Creditmemo
     * @param Mage_Sales_Model_Order_Payment
     * @param float $amount
     * @return self
     */
    public function processCreditmemo($creditmemo, $payment)
    {
        // The creditmemo must be saved before continuing.
        $creditmemo->save();
        parent::processCreditmemo($creditmemo, $payment);
        try {
            $api = $this->_getCreditmemoApi($creditmemo, $payment);
            $this->_prepareCreditRequest($api, $creditmemo, $payment);
            Mage::dispatchEvent('radial_creditcard_settlement_credit_request_send_before', [
                'payload' => $api->getRequestBody(),
                'creditmemo' => $creditmemo,
                'payment' => $payment,
            ]);
            // Log the request instead of expecting the SDK to have logged it.
            // Allows the data to be properly scrubbed of any PII or other sensitive
            // data prior to writing the log files.
            $logMessage = 'Sending credit card settlement credit request.';
            $cleanedRequestXml = $this->_helper->cleanPaymentsXml($api->getRequestBody()->serialize());
            $this->_logger->debug($logMessage, $this->_context->getMetaData(__CLASS__, ['request_body' => $cleanedRequestXml]));
            $this->_sendRequest($api);
            // Log the response instead of expecting the SDK to have logged it.
            // Allows the data to be properly scrubbed of any PII or other sensitive
            // data prior to writing the log files.
            $logMessage = 'Received credit card settlement credit response.';
            $cleanedResponseXml = $this->_helper->cleanPaymentsXml($api->getResponseBody()->serialize());
            $this->_logger->debug($logMessage, $this->_context->getMetaData(__CLASS__, ['response_body' => $cleanedResponseXml]));
            $this->_handleCreditResponse($api, $creditmemo, $payment);
        } catch (Exception $e) {
       	    // settlement must be allowed to fail
       	    // set creditmemo status as OPEN to trigger a retry and notify admin
       	    $creditmemo->setState(Mage_Sales_Model_Order_Creditmemo::STATE_OPEN);
	
	    $retry = $creditmemo->getDeliveryStatus();
      	    $retryN = $retry + 1;
            $creditmemo->setDeliveryStatus($retryN);
            $creditmemo->save();

            $errorMessage = $this->_helper->__(self::SETTLEMENT_FAILED_MESSAGE);
            $this->getSession()->addNotice($errorMessage);
            $this->_logger->logException($e, $this->_context->getMetaData(__CLASS__, [], $e));

	    $paymentsEmailA = explode(',', Mage::getStoreConfig('radial_core/payments/payments_email'));
            if( !empty($paymentsEmailA) )
            {
                foreach( $paymentsEmailA as $paymentsEmail )
                {
                        $paymentsName = Mage::app()->getStore()->getName() . ' - ' . 'Payments Admin';
                        $emailTemplate  = Mage::getModel('core/email_template')->loadDefault('custom_email_template2');

                        //Create an array of variables to assign to template
                        $emailTemplateVariables = array();
                        $emailTemplateVariables['myvar1'] = gmdate("Y-m-d\TH:i:s\Z");
                        $emailTemplateVariables['myvar2'] = $e->getMessage();
                        $emailTemplateVariables['myvar3'] = $e->getTraceAsString();
                        $emailTemplateVariables['myvar4'] = htmlspecialchars($cleanedResponseXml);

                        $processedTemplate = $emailTemplate->getProcessedTemplate($emailTemplateVariables);
                        //Sending E-Mail to Payments Admin Email.
                        $mail = Mage::getModel('core/email')
                                ->setToName($paymentsName)
                                ->setToEmail($paymentsEmail)
                                ->setBody($processedTemplate)
                                ->setSubject('Payments - Credit Memo - Exception Report From: '. __CLASS__ . ' on ' . gmdate("Y-m-d\TH:i:s\Z") . ' UTC')
                                ->setFromEmail(Mage::getStoreConfig('trans_email/ident_general/email'))
                                ->setFromName($paymentsName)
                                ->setType('html');
                        try {
                                //Confimation E-Mail Send
                                $mail->send();
                        }
                        catch(Exception $error)
                        {
                                $logMessage = sprintf('[%s] Error Sending Email: %s', __CLASS__, $error->getMessage());
                                Mage::log($logMessage, Zend_Log::ERR);
                        }
                }
            }
        }
        return $this;
    }
    /**
     * Fill out the request payload with payment data and update the API request
     * body with the complete request.
     * @param Api\IBidirectionalApi $api
     * @param Mage_Sales_Model_Order_Creditmemo
     * @param Mage_Sales_Model_Order_Payment
     * @param string $type
     * @return static
     */
    protected function _prepareCreditRequest(Api\IBidirectionalApi $api, $creditmemo, $payment)
    {
        /** @var Payload\Payment\PaymentSettlementRequest $request */
        $request = $api->getRequestBody();
        /** @var Mage_Sales_Model_Order $order */
        $order = $payment->getOrder();
        $request
            ->setPanIsToken(true)
            ->setAmount((float)$creditmemo->getBaseGrandTotal())
            ->setCurrencyCode(Mage::app()->getStore()->getBaseCurrencyCode())
            ->setTaxAmount((float)$creditmemo->getTaxAmount())
            ->setClientContext($creditmemo->getTransactionId())
            ->setCardNumber($payment->getCcNumber())
            ->setRequestId($this->_coreHelper->generateRequestId('CCA-'))
            ->setSettlementType(self::SETTLEMENT_TYPE_REFUND)
            ->setFinalDebit(0)
            ->setInvoiceId($creditmemo->getIncrementId())
            ->setOrderId($order->getIncrementId());
        return $this;
    }

    /**
     * Retrieve adminhtml session model object
     * @return Mage_Adminhtml_Model_Session
     */
    protected function getSession()
    {
        return Mage::getSingleton('adminhtml/session');
    }
    /**
     * Cancel the payment
     *
     * @param Varien_Object $payment
     * @return self
     * @throws Mage_Core_Exception
     */
    public function cancel(Varien_Object $payment)
    {
	parent::cancel($payment);
        $api = $this->_getAuthCancelApi($payment);
        $this->_prepareAuthCancelRequest($api, $payment);
        Mage::dispatchEvent('radial_creditcard_auth_cancel_request_send_before', [
            'payload' => $api->getRequestBody(),
            'payment' => $payment,
        ]);
        // Log the request instead of expecting the SDK to have logged it.
        // Allows the data to be properly scrubbed of any PII or other sensitive
        // data prior to writing the log files.
        $logMessage = 'Sending credit card auth cancel request.';
        $cleanedRequestXml = $this->_helper->cleanPaymentsXml($api->getRequestBody()->serialize());
        $this->_logger->debug($logMessage, $this->_context->getMetaData(__CLASS__, ['request_body' => $cleanedRequestXml]));
        $this->_sendRequest($api);
        // Log the response instead of expecting the SDK to have logged it.
        // Allows the data to be properly scrubbed of any PII or other sensitive
        // data prior to writing the log files.
        $logMessage = 'Received credit card auth cancel response.';
        $cleanedResponseXml = $this->_helper->cleanPaymentsXml($api->getResponseBody()->serialize());
        $this->_logger->debug($logMessage, $this->_context->getMetaData(__CLASS__, ['response_body' => $cleanedResponseXml]));
        $this->_handleAuthCancelResponse($api, $payment);
        return $this;
    }
}
