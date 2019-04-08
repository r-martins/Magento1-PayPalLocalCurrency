<?php
 
class Magenteiro_PayPalLocalCurrency_Model_Paypal_Cart extends Mage_Paypal_Model_Cart
{
    /** @inheritDoc */
    protected function _render()
    {
        if (!$this->_shouldRender) {
            return;
        }

        // regular items from the sales entity
        $this->_items = array();
        foreach ($this->_salesEntity->getAllItems() as $item) {
            if (!$item->getParentItem()) {
                $this->_addRegularItem($item);
            }
        }
        end($this->_items);
        $lastRegularItemKey = key($this->_items);

        #customization starts
        $config = Mage::getModel('paypal/config');
        $isCurrencySupported = $config->isCurrencyCodeSupported($this->_salesEntity->getQuoteCurrencyCode());
        #customization ends


        // regular totals
        $shippingDescription = '';
        if ($this->_salesEntity instanceof Mage_Sales_Model_Order) {
            $shippingDescription = $this->_salesEntity->getShippingDescription();
            $this->_totals = array(
                self::TOTAL_SUBTOTAL => ($isCurrencySupported) ? $this->_salesEntity->getSubtotal() :  $this->_salesEntity->getBaseSubtotal(),
                self::TOTAL_TAX      => ($isCurrencySupported) ? $this->_salesEntity->getTaxAmount() : $this->_salesEntity->getBaseTaxAmount(),
                self::TOTAL_SHIPPING => ($isCurrencySupported) ? $this->_salesEntity->getShippingAmount() : $this->_salesEntity->getBaseShippingAmount(),
                self::TOTAL_DISCOUNT => ($isCurrencySupported) ? abs($this->_salesEntity->getDiscountAmount()) : abs($this->_salesEntity->getBaseDiscountAmount()),
            );
            $this->_applyHiddenTaxWorkaround($this->_salesEntity);
        } else {
            $address = $this->_salesEntity->getIsVirtual() ?
                $this->_salesEntity->getBillingAddress() : $this->_salesEntity->getShippingAddress();
            $shippingDescription = $address->getShippingDescription();
            $this->_totals = array (
                self::TOTAL_SUBTOTAL => ($isCurrencySupported) ? $this->_salesEntity->getSubtotal() : $this->_salesEntity->getBaseSubtotal(),
                self::TOTAL_TAX      => ($isCurrencySupported) ? $address->getTaxAmount() : $address->getBaseTaxAmount(),
                self::TOTAL_SHIPPING => ($isCurrencySupported) ? $address->getShippingAmount() : $address->getBaseShippingAmount(),
                self::TOTAL_DISCOUNT => ($isCurrencySupported) ? abs($address->getDiscountAmount()) : abs($address->getBaseDiscountAmount()),
            );
            $this->_applyHiddenTaxWorkaround($address);
        }
        $originalDiscount = $this->_totals[self::TOTAL_DISCOUNT];

        // arbitrary items, total modifications
        Mage::dispatchEvent('paypal_prepare_line_items', array('paypal_cart' => $this));

        // distinguish original discount among the others
        if ($originalDiscount > 0.0001 && isset($this->_totalLineItemDescriptions[self::TOTAL_DISCOUNT])) {
            $this->_totalLineItemDescriptions[self::TOTAL_DISCOUNT][] = Mage::helper('sales')->__('Discount (%s)', Mage::app()->getStore()->convertPrice($originalDiscount, true, false));
        }

        // discount, shipping as items
        if ($this->_isDiscountAsItem && $this->_totals[self::TOTAL_DISCOUNT]) {
            $this->addItem(Mage::helper('paypal')->__('Discount'), 1, -1.00 * $this->_totals[self::TOTAL_DISCOUNT],
                $this->_renderTotalLineItemDescriptions(self::TOTAL_DISCOUNT)
            );
        }
        $shippingItemId = $this->_renderTotalLineItemDescriptions(self::TOTAL_SHIPPING, $shippingDescription);
        if ($this->_isShippingAsItem && (float)$this->_totals[self::TOTAL_SHIPPING]) {
            $this->addItem(Mage::helper('paypal')->__('Shipping'), 1, (float)$this->_totals[self::TOTAL_SHIPPING],
                $shippingItemId
            );
        }

        // compound non-regular items into subtotal
        foreach ($this->_items as $key => $item) {
            if ($key > $lastRegularItemKey && $item->getAmount() != 0) {
                $this->_totals[self::TOTAL_SUBTOTAL] += $item->getAmount();
            }
        }

        $this->_validate();
        // if cart items are invalid, prepare cart for transfer without line items
        if (!$this->_areItemsValid) {
            $this->removeItem($shippingItemId);
        }

        $this->_shouldRender = false;
    }

    /** @inheritDoc */
    protected function _addRegularItem(Varien_Object $salesItem)
    {
        #customization starts
        $config = Mage::getModel('paypal/config');
        $isCurrencySupported = $config->isCurrencyCodeSupported($this->_salesEntity->getQuoteCurrencyCode());
        #customization ends

        if ($this->_salesEntity instanceof Mage_Sales_Model_Order) {
            $qty = (int) $salesItem->getQtyOrdered();
            $amount = ($isCurrencySupported) ? (float) $salesItem->getPrice() : (float) $salesItem->getBasePrice();
            // TODO: nominal item for order
        } else {
            $qty = (int) $salesItem->getTotalQty();
            $amount = $salesItem->isNominal() ? 0 : (float) $salesItem->getBaseCalculationPrice();
            if($isCurrencySupported){
                $amount = $salesItem->isNominal() ? 0 : (float) $salesItem->getBaseCalculationPriceOriginal();
            }
        }
        // workaround in case if item subtotal precision is not compatible with PayPal (.2)
        $subAggregatedLabel = '';
        if ($amount - round($amount, 2)) {
            $amount = $amount * $qty;
            $subAggregatedLabel = ' x' . $qty;
            $qty = 1;
        }

        // aggregate item price if item qty * price does not match row total
        if($isCurrencySupported){
            if (($amount * $qty) != $salesItem->getRowTotal()) {
                $amount = (float) $salesItem->getRowTotal();
                $subAggregatedLabel = ' x' . $qty;
                $qty = 1;
            }
        }else{
            if (($amount * $qty) != $salesItem->getBaseRowTotal()) {
                $amount = (float) $salesItem->getBaseRowTotal();
                $subAggregatedLabel = ' x' . $qty;
                $qty = 1;
            }
        }


        return $this->addItem($salesItem->getName() . $subAggregatedLabel, $qty, $amount, $salesItem->getSku());
    }


    /** @inheritDoc */
    protected function _validate()
    {
        $this->_areItemsValid = true;
        $this->_areTotalsValid = false;

        #customization starts
        $config = Mage::getModel('paypal/config');
        $isCurrencySupported = $config->isCurrencyCodeSupported($this->_salesEntity->getQuoteCurrencyCode());
        #customization ends

        $referenceAmount = ($isCurrencySupported) ? $this->_salesEntity->getGrandTotal() : $this->_salesEntity->getBaseGrandTotal();

        $itemsSubtotal = 0;
        foreach ($this->_items as $i) {
            $itemsSubtotal = $itemsSubtotal + $i['qty'] * $i['amount'];
        }
        $sum = $itemsSubtotal + $this->_totals[self::TOTAL_TAX];
        if (!$this->_isShippingAsItem) {
            $sum += $this->_totals[self::TOTAL_SHIPPING];
        }
        if (!$this->_isDiscountAsItem) {
            $sum -= $this->_totals[self::TOTAL_DISCOUNT];
        }
        /**
         * numbers are intentionally converted to strings because of possible comparison error
         * see http://php.net/float
         */
        // match sum of all the items and totals to the reference amount
        if (sprintf('%.4F', $sum) != sprintf('%.4F', $referenceAmount)) {
            $adjustment = $sum - $referenceAmount;
            $this->_totals[self::TOTAL_SUBTOTAL] = $this->_totals[self::TOTAL_SUBTOTAL] - $adjustment;
        }

        // PayPal requires to have discount less than items subtotal
        if (!$this->_isDiscountAsItem) {
            $this->_areTotalsValid = round($this->_totals[self::TOTAL_DISCOUNT], 4) < round($itemsSubtotal, 4);
        } else {
            $this->_areTotalsValid = $itemsSubtotal > 0.00001;
        }

        $this->_areItemsValid = $this->_areItemsValid && $this->_areTotalsValid;
    }

    private function _applyHiddenTaxWorkaround($salesEntity)
    {
        $this->_totals[self::TOTAL_TAX] += (float)$salesEntity->getBaseHiddenTaxAmount();
        $this->_totals[self::TOTAL_TAX] += (float)$salesEntity->getBaseShippingHiddenTaxAmount();
    }
}