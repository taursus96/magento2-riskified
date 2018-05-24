<?php

namespace Riskified\Decider\Controller\Deco;

use Magento\Framework\App\Action\Action;
use Riskified\Decider\Api\Deco;

class OptIn extends Action
{
    /**
     * @var \Magento\Framework\Controller\Result\JsonFactory
     */
    protected $resultJsonFactory;

    /**
     * @var \Riskified\Decider\Api\Log
     */
    private $logger;

    /**
     * @var Deco
     */
    private $deco;
    /**
     * @var \Magento\Framework\Json\Helper\Data
     */
    private $helper;

    private $sessionManager;

    private $api;

    /**
     * IsEligible constructor.
     *
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Magento\Framework\Controller\Result\JsonFactory $resultJsonFactory
     * @param Deco $deco
     * @param \Riskified\Decider\Api\Log $logger
     * @param \Magento\Framework\Json\Helper\Data $helper
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Framework\Controller\Result\JsonFactory $resultJsonFactory,
        Deco $deco,
        \Riskified\Decider\Api\Log $logger,
        \Magento\Framework\Json\Helper\Data $helper,
        \Magento\Framework\Session\SessionManager $sessionManager,
        \Riskified\Decider\Api\Order $api
    ) {
        parent::__construct($context);

        $this->resultJsonFactory = $resultJsonFactory;
        $this->deco = $deco;
        $this->logger = $logger;
        $this->helper = $helper;
        $this->sessionManager = $sessionManager;
        $this->api = $api;
    }

    /**
     * OptIn Api call.
     */
    public function execute()
    {
        $params = $this->helper->jsonDecode($this->getRequest()->getContent());
        $resultJson = $this->resultJsonFactory->create();

        try {
            $this->logger->log('Deco OptIn request, quote_id: ' . $params['quote_id']);
            $response = $this->deco->post(
                $params['quote_id'],
                Deco::ACTION_OPT_IN
            );

            if ($response->order->status == 'opt_in') {
                $this->processOrder($params['payment_method']);
            }

            return $resultJson->setData([
                'success' => true,
                'status' => $response->order->status,
                'message' => $response->order->description
            ]);
        } catch (\Exception $e) {
            $this->logger->logException($e);

            return $resultJson->setData(
                [
                    'success' => false,
                    'status' => 'not_eligible',
                    'message' => $e->getMessage()
                ]
            );
        }
    }

    /**
     * Return customer quote
     *
     * @param string $paymentMethod
     *
     * @return void
     */
    protected function processOrder($paymentMethod)
    {
        switch ($paymentMethod) {
            case 'authorizenet_directpost':
                $directPostSession = $this->_objectManager->get(\Magento\Authorizenet\Model\Directpost\Session::class);
                $incrementId = $directPostSession->getLastOrderIncrementId();
                if ($incrementId) {
                    /* @var $order \Magento\Sales\Model\Order */
                    $order = $this->_objectManager->create(\Magento\Sales\Model\Order::class)->loadByIncrementId($incrementId);
                    if ($order->getId()) {
                        try {
                            /** @var \Magento\Quote\Api\CartRepositoryInterface $quoteRepository */
                            $quoteRepository = $this->_objectManager->create(\Magento\Quote\Api\CartRepositoryInterface::class);
                            /** @var \Magento\Quote\Model\Quote $quote */
                            $quote = $quoteRepository->get($order->getQuoteId());
                            $quote->setIsActive(0);
                            $quoteRepository->save($quote);
                            $this->api->unCancelOrder($order, __('Order processed by Deco Payments'));
                            $order->getPayment()->setMethod('deco')->save();
                            $order->save();
                        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                            $this->logger->logException($e);
                        }
                    }
                }
        }
    }
}