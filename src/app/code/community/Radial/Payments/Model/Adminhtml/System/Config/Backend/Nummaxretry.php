<?php
/**
 * Copyright (c) 2013-2016 Radial, Inc.
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * @copyright   Copyright (c) 2013-2016 Radial, Inc. (http://www.radial.com/)
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

class Radial_Payments_Model_Adminhtml_System_Config_Backend_Nummaxretry extends Mage_Core_Model_Config_Data
{
    public function _afterLoad()
    {
	$maxretries = Mage::getStoreConfig('radial_core/payments/maxretries');

	$pendingCreditMemoSize = Mage::getModel('sales/order_creditmemo')->getCollection()
        		->addFieldToFilter('state', Mage_Sales_Model_Order_Creditmemo::STATE_OPEN)
			->addFieldToFilter('delivery_status', $maxretries)->getSize();

	$pendingInvoicesSize = Mage::getModel('sales/order_invoice')->getCollection()
        				->addFieldToFilter('state', Mage_Sales_Model_Order_Invoice::STATE_OPEN)
					->addFieldToFilter('delivery_status', $maxretries)->getSize();

	$objectCollectionSize = $pendingCreditMemoSize + $pendingInvoicesSize;

        $publicDisplay = '# of Payments Messages At Max Transmission Retries: '. $objectCollectionSize;

        $this->setValue($publicDisplay);
        return $this;
    }

    /**
     * Simply turn of data saving. This is a display-only field in admin dependent upon presence of
     *  files in the filesystem, not any configuration data.
     * @return self
     * @codeCoverageIgnore Magento promises to not save anything if _dataSaveAllowed is false.
     */
    public function _beforeSave()
    {
        $this->_dataSaveAllowed = false;
        return $this;
    }
}
