<?php
 
class Magenteiro_PayPalLocalCurrency_Model_Paypal_Standard extends Mage_Paypal_Model_Standard
{
    /**
     * Return form field array
     *
     * @return array
     */
    public function getStandardCheckoutFormFields()
    {
        $orderIncrementId = $this->getCheckout()->getLastRealOrderId();
        $order = Mage::getModel('sales/order')->loadByIncrementId($orderIncrementId);
        /* @var $api Mage_Paypal_Model_Api_Standard */
        $api = Mage::getModel('paypal/api_standard')->setConfigObject($this->getConfig());


        #customization starts
        $config = Mage::getModel('paypal/config');
        $isCurrencySupported = $config->isCurrencyCodeSupported($order->getOrderCurrencyCode());
        $currencyCode = $isCurrencySupported ? $order->getOrderCurrencyCode() : $order->getBaseCurrencyCode();
        #customization ends

        $api->setOrderId($orderIncrementId)
            ->setCurrencyCode($currencyCode)
            //->setPaymentAction()
            ->setOrder($order)
            ->setNotifyUrl(Mage::getUrl('paypal/ipn/'))
            ->setReturnUrl(Mage::getUrl('paypal/standard/success'))
            ->setCancelUrl(Mage::getUrl('paypal/standard/cancel'));

        // export address
        $isOrderVirtual = $order->getIsVirtual();
        $address = $isOrderVirtual ? $order->getBillingAddress() : $order->getShippingAddress();
        if ($isOrderVirtual) {
            $api->setNoShipping(true);
        } elseif ($address->validate()) {
            $api->setAddress($address);
        }

        // add cart totals and line items
        $api->setPaypalCart(Mage::getModel('paypal/cart', array($order)))
            ->setIsLineItemsEnabled($this->_config->lineItemsEnabled)
        ;
        $api->setCartSummary($this->_getAggregatedCartSummary());
        $api->setLocale($api->getLocaleCode());
        $result = $api->getStandardCheckoutRequest();
        return $result;
    }
}