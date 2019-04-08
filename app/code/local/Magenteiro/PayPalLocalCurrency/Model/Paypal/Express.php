<?php
class Magenteiro_PayPalLocalCurrency_Model_Paypal_Express extends Mage_Paypal_Model_Express
{
    /** @inheritDoc */
    protected function _placeOrder(Mage_Sales_Model_Order_Payment $payment, $amount)
    {
        $order = $payment->getOrder();

        #customization starts
        $config = Mage::getModel('paypal/config');
        $isCurrencySupported = $config->isCurrencyCodeSupported($order->getOrderCurrencyCode());
        $currencyCode = $isCurrencySupported ? $order->getOrderCurrencyCode() : $order->getBaseCurrencyCode();
        $amount = $isCurrencySupported ? $order->getGrandTotal() : $amount;
        #customization ends

        // prepare api call
        $token = $payment->getAdditionalInformation(Mage_Paypal_Model_Express_Checkout::PAYMENT_INFO_TRANSPORT_TOKEN);
        $api = $this->_pro->getApi()
            ->setToken($token)
            ->setPayerId($payment->
            getAdditionalInformation(Mage_Paypal_Model_Express_Checkout::PAYMENT_INFO_TRANSPORT_PAYER_ID))
            ->setAmount($amount)
            ->setPaymentAction($this->_pro->getConfig()->paymentAction)
            ->setNotifyUrl(Mage::getUrl('paypal/ipn/'))
            ->setInvNum($order->getIncrementId())
            ->setCurrencyCode($currencyCode)
            ->setPaypalCart(Mage::getModel('paypal/cart', array($order)))
            ->setIsLineItemsEnabled($this->_pro->getConfig()->lineItemsEnabled);

        // call api and get details from it
        $api->callDoExpressCheckoutPayment();

        $this->_importToPayment($api, $payment);
        return $this;
    }
}