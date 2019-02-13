<?php
/**
 * Mageplaza
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Mageplaza.com license that is
 * available through the world-wide-web at this URL:
 * https://www.mageplaza.com/LICENSE.txt
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this extension to newer
 * version in the future.
 *
 * @category  Mageplaza
 * @package   Mageplaza_CustomerApproval
 * @copyright Copyright (c) Mageplaza (https://www.mageplaza.com/)
 * @license   https://www.mageplaza.com/LICENSE.txt
 */

namespace Mageplaza\CustomerApproval\Plugin;

use Magento\Customer\Model\AccountManagement;
use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory as CusCollectFactory;
use Magento\Customer\Model\Session;
use Magento\Framework\App\ActionFlag;
use Magento\Framework\App\Response\RedirectInterface;
use Magento\Framework\App\ResponseFactory;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Message\ManagerInterface;
use Mageplaza\CustomerApproval\Helper\Data as HelperData;
use Mageplaza\CustomerApproval\Model\Config\Source\AttributeOptions;
use Mageplaza\CustomerApproval\Model\Config\Source\TypeNotApprove;

/**
 * Class CustomerAuthenticated
 *
 * @package Mageplaza\CustomerApproval\Plugin
 */
class CustomerAuthenticated
{
    /**
     * @var HelperData
     */
    protected $helperData;

    /**
     * @var ManagerInterface
     */
    protected $messageManager;

    /**
     * @var ActionFlag
     */
    protected $_actionFlag;

    /**
     * @var ResponseInterface
     */
    protected $_response;

    /**
     * @var CusCollectFactory
     */
    protected $_cusCollectFactory;

    /**
     * @var Session
     */
    protected $_customerSession;

    /**
     * @var RedirectInterface
     */
    protected $_redirect;

    /**
     * CustomerAuthenticated constructor.
     *
     * @param HelperData $helperData
     * @param ManagerInterface $messageManager
     * @param ActionFlag $actionFlag
     * @param ResponseFactory $response
     * @param CusCollectFactory $cusCollectFactory
     * @param Session $customerSession
     * @param RedirectInterface $redirect
     */
    public function __construct(
        HelperData $helperData,
        ManagerInterface $messageManager,
        ActionFlag $actionFlag,
        ResponseFactory $response,
        CusCollectFactory $cusCollectFactory,
        Session $customerSession,
        RedirectInterface $redirect
    ) {
        $this->helperData         = $helperData;
        $this->messageManager     = $messageManager;
        $this->_actionFlag        = $actionFlag;
        $this->_response          = $response;
        $this->_cusCollectFactory = $cusCollectFactory;
        $this->_customerSession   = $customerSession;
        $this->_redirect          = $redirect;
    }

    /**
     * @param AccountManagement $subject
     * @param \Closure $proceed
     * @param $username
     * @param $password
     *
     * @return mixed
     * @throws \Magento\Framework\Exception\InputException
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Framework\Stdlib\Cookie\FailureToSendException
     * @SuppressWarnings(Unused)
     */
    public function aroundAuthenticate(
        AccountManagement $subject,
        \Closure $proceed,
        $username,
        $password
    ) {
        $result = $proceed($username, $password);
        if (!$this->helperData->isEnabled()) {
            return $result;
        }

        $customerFilter = $this->_cusCollectFactory->create()->addFieldToFilter('email', $username)->getFirstItem();
        $customerId     = $customerFilter->getId();
        // check old customer and set approved
        $getIsApproved = null;
        /** @var \Magento\Customer\Model\Customer $customerFilter */
        if ($customerId) {
            $this->isOldCustomerHasCheck($customerId);
            // check new customer logedin
            $getIsApproved = $this->helperData->getIsApproved($customerId);
        }
        if ($customerId && $getIsApproved != AttributeOptions::APPROVED && $getIsApproved != null) {
            // case redirect
            $urlRedirect = $this->helperData->getUrl($this->helperData->getCmsRedirectPage(), ['_secure' => true]);
            if ($this->helperData->getTypeNotApprove() == TypeNotApprove::SHOW_ERROR ||
                $this->helperData->getTypeNotApprove() == null
            ) {
                // case show error
                $urlRedirect = $this->helperData->getUrl('customer/account/login', ['_secure' => true]);
                $this->messageManager->addErrorMessage(__($this->helperData->getErrorMessage()));
            }

            // force logout customer
            $this->_customerSession->logout()->setBeforeAuthUrl($this->_redirect->getRefererUrl())
                ->setLastCustomerId($customerId);
            if ($this->helperData->getCookieManager()->getCookie('mage-cache-sessid')) {
                $metadata = $this->helperData->getCookieMetadataFactory()->createCookieMetadata();
                $metadata->setPath('/');
                $this->helperData->getCookieManager()->deleteCookie('mage-cache-sessid', $metadata);
            }
            // force redirect
            /** @var \Magento\Framework\HTTP\PhpEnvironment\Response $response */
            $response = $this->_response->create();
            $response->setRedirect($urlRedirect)->sendResponse();
        }

        return $result;
    }

    /**
     * @param $customerId
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    private function isOldCustomerHasCheck($customerId)
    {
        $getApproved = $this->helperData->getIsApproved($customerId);
        if ($getApproved == null) {
            $this->helperData->autoApprovedOldCustomerById($customerId);
        }
    }
}
