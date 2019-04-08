<?php
 
class Magenteiro_PayPalLocalCurrency_Model_Paypal_Express_Checkout extends Mage_Paypal_Model_Express_Checkout
{
    /** @inheritDoc */
    public function start($returnUrl, $cancelUrl, $button = null)
    {
        $this->_quote->collectTotals();

        if (!$this->_quote->getGrandTotal() && !$this->_quote->hasNominalItems()) {
            Mage::throwException(Mage::helper('paypal')->__('PayPal does not support processing orders with zero amount. To complete your purchase, proceed to the standard checkout process.'));
        }

        $this->_quote->reserveOrderId()->save();
        // prepare API
        $this->_getApi();
        $solutionType = $this->_config->getMerchantCountry() == 'DE'
            ? Mage_Paypal_Model_Config::EC_SOLUTION_TYPE_MARK : $this->_config->solutionType;

        #customization starts
        $config = Mage::getModel('paypal/config');
        $isCurrencySupported = $config->isCurrencyCodeSupported($this->_quote->getQuoteCurrencyCode());
        $currencyCode = $isCurrencySupported ? $this->_quote->getQuoteCurrencyCode() : $this->_quote->getBaseCurrencyCode();
        $amount = $isCurrencySupported ? $this->_quote->getGrandTotal() : $this->_quote->getBaseGrandTotal();
        #customization ends

        $this->_api->setAmount($amount)
            ->setCurrencyCode($currencyCode)
            ->setInvNum($this->_quote->getReservedOrderId())
            ->setReturnUrl($returnUrl)
            ->setCancelUrl($cancelUrl)
            ->setSolutionType($solutionType)
            ->setPaymentAction($this->_config->paymentAction);

        if ($this->_giropayUrls) {
            list($successUrl, $cancelUrl, $pendingUrl) = $this->_giropayUrls;
            $this->_api->addData(array(
                'giropay_cancel_url' => $cancelUrl,
                'giropay_success_url' => $successUrl,
                'giropay_bank_txn_pending_url' => $pendingUrl,
            ));
        }

        if ($this->_isBml) {
            $this->_api->setFundingSource('BML');
        }

        $this->_setBillingAgreementRequest();

        if ($this->_config->requireBillingAddress == Mage_Paypal_Model_Config::REQUIRE_BILLING_ADDRESS_ALL) {
            $this->_api->setRequireBillingAddress(1);
        }

        // supress or export shipping address
        if ($this->_quote->getIsVirtual()) {
            if ($this->_config->requireBillingAddress == Mage_Paypal_Model_Config::REQUIRE_BILLING_ADDRESS_VIRTUAL) {
                $this->_api->setRequireBillingAddress(1);
            }
            $this->_api->setSuppressShipping(true);
        } else {
            $address = $this->_quote->getShippingAddress();
            $isOverriden = 0;
            if (true === $address->validate()) {
                $isOverriden = 1;
                $this->_api->setAddress($address);
            }
            $this->_quote->getPayment()->setAdditionalInformation(
                self::PAYMENT_INFO_TRANSPORT_SHIPPING_OVERRIDEN, $isOverriden
            );
            $this->_quote->getPayment()->save();
        }

        // add line items
        $paypalCart = Mage::getModel('paypal/cart', array($this->_quote));
        $this->_api->setPaypalCart($paypalCart)
            ->setIsLineItemsEnabled($this->_config->lineItemsEnabled)
        ;

        // add shipping options if needed and line items are available
        if ($this->_config->lineItemsEnabled && $this->_config->transferShippingOptions && $paypalCart->getItems()) {
            if (!$this->_quote->getIsVirtual() && !$this->_quote->hasNominalItems()) {
                if ($options = $this->_prepareShippingOptions($address, true)) {
                    $this->_api->setShippingOptionsCallbackUrl(
                        Mage::getUrl('*/*/shippingOptionsCallback', array('quote_id' => $this->_quote->getId()))
                    )->setShippingOptions($options);
                }
            }
        }

        // add recurring payment profiles information
        if ($profiles = $this->_quote->prepareRecurringPaymentProfiles()) {
            foreach ($profiles as $profile) {
                $profile->setMethodCode(Mage_Paypal_Model_Config::METHOD_WPP_EXPRESS);
                if (!$profile->isValid()) {
                    Mage::throwException($profile->getValidationErrors(true, true));
                }
            }
            $this->_api->addRecurringPaymentProfiles($profiles);
        }

        $this->_config->exportExpressCheckoutStyleSettings($this->_api);

        // call API and redirect with token
        $this->_api->callSetExpressCheckout();
        $token = $this->_api->getToken();
        $this->_redirectUrl = $button ? $this->_config->getExpressCheckoutStartUrl($token)
            : $this->_config->getPayPalBasicStartUrl($token);

        $this->_quote->getPayment()->unsAdditionalInformation(self::PAYMENT_INFO_TRANSPORT_BILLING_AGREEMENT);

        // Set flag that we came from Express Checkout button
        if (!empty($button)) {
            $this->_quote->getPayment()->setAdditionalInformation(self::PAYMENT_INFO_BUTTON, 1);
        } elseif ($this->_quote->getPayment()->hasAdditionalInformation(self::PAYMENT_INFO_BUTTON)) {
            $this->_quote->getPayment()->unsAdditionalInformation(self::PAYMENT_INFO_BUTTON);
        }

        $this->_quote->getPayment()->save();
        return $token;
    }
}