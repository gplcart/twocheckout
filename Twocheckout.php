<?php

/**
 * @package 2 Checkout
 * @author Iurii Makukh <gplcart.software@gmail.com>
 * @copyright Copyright (c) 2017, Iurii Makukh <gplcart.software@gmail.com>
 * @license https://www.gnu.org/licenses/gpl-3.0.en.html GNU General Public License 3.0
 */

namespace gplcart\modules\twocheckout;

use gplcart\core\Module,
    gplcart\core\Config;

/**
 * Main class for 2 Checkout module
 */
class Twocheckout extends Module
{

    /**
     * The current order
     * @var array
     */
    protected $data_order;

    /**
     * Omnipay response instance
     * @var object
     */
    protected $response;

    /**
     * Frontend controller instance
     * @var \gplcart\core\controllers\frontend\Controller $controller
     */
    protected $controller;

    /**
     * Order model instance
     * @var \gplcart\core\models\Order $order
     */
    protected $order;

    /**
     * @param Config $config
     */
    public function __construct(Config $config)
    {
        parent::__construct($config);
    }

    /**
     * Implements hook "route.list"
     * @param array $routes
     */
    public function hookRouteList(array &$routes)
    {
        $routes['admin/module/settings/twocheckout'] = array(
            'access' => 'module_edit',
            'handlers' => array(
                'controller' => array('gplcart\\modules\\twocheckout\\controllers\\Settings', 'editSettings')
            )
        );
    }

    /**
     * Implements hook "module.enable.before"
     * @param mixed $result
     */
    public function hookModuleEnableBefore(&$result)
    {
        try {
            $this->getGatewayInstance();
        } catch (\InvalidArgumentException $ex) {
            $result = $ex->getMessage();
        }
    }

    /**
     * Implements hook "module.install.before"
     * @param mixed $result
     */
    public function hookModuleInstallBefore(&$result)
    {
        try {
            $this->getGatewayInstance();
        } catch (\InvalidArgumentException $ex) {
            $result = $ex->getMessage();
        }
    }

    /**
     * Get gateway instance
     * @return object
     * @throws \InvalidArgumentException
     */
    protected function getGatewayInstance()
    {
        /* @var $model \gplcart\modules\omnipay_library\OmnipayLibrary */
        $model = $this->getInstance('omnipay_library');

        $instance = $model->getGatewayInstance('TwoCheckoutPlus');

        if (!$instance instanceof \Omnipay\TwoCheckoutPlus\Gateway) {
            throw new \InvalidArgumentException('Object is not instance of Omnipay\TwoCheckoutPlus\Gateway');
        }

        return $instance;
    }

    /**
     * Implements hook "payment.methods"
     * @param array $methods
     */
    public function hookPaymentMethods(array &$methods)
    {
        $methods['twocheckout'] = array(
            'module' => 'twocheckout',
            'image' => 'image/icon.png',
            'status' => $this->getStatus(),
            'title' => $this->getLanguage()->text('2 Checkout'),
            'template' => array('complete' => 'pay')
        );
    }

    /**
     * Returns a module setting
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    protected function setting($name, $default = null)
    {
        return $this->config->getFromModule('twocheckout', $name, $default);
    }

    /**
     * Returns the current status of the payment method
     */
    protected function getStatus()
    {
        return $this->setting('status') && $this->setting('accountNumber') && $this->setting('secretWord');
    }

    /**
     * Implements hook "order.add.before"
     * @param array $order
     * @param \gplcart\core\models\Order $object
     */
    public function hookOrderAddBefore(array &$order, $object)
    {
        // Adjust order status before creation
        // We want to get payment in advance, so assign "awaiting payment" status
        if ($order['payment'] === 'twocheckout') {
            $order['status'] = $object->getStatusAwaitingPayment();
        }
    }

    /**
     * Implements hook "order.checkout.complete"
     * @param string $message
     * @param array $order
     */
    public function hookOrderCompleteMessage(&$message, $order)
    {
        if ($order['payment'] === 'twocheckout') {
            $message = ''; // Hide default message
        }
    }

    /**
     * Implements hook "order.complete.page"
     * @param array $order
     * @param \gplcart\core\models\Order $model
     * @param \gplcart\core\controllers\frontend\Controller $controller
     */
    public function hookOrderCompletePage(array $order, $model, $controller)
    {
        $this->order = $model;
        $this->data_order = $order;
        $this->controller = $controller;

        if ($order['payment'] === 'twocheckout') {
            $this->submit();
            $this->complete();
        }
    }

    /**
     * Performs actions when purchase is completed
     */
    protected function complete()
    {
        if ($this->controller->isQuery('paid')) {
            $gateway = $this->getGatewayInstance();
            $this->response = $gateway->completePurchase($this->getPurchaseParams())->send();
            $this->processResponse();
        }
    }

    /**
     * Handles submitted payment
     */
    protected function submit()
    {
        if ($this->controller->isPosted('pay')) {

            $gateway = $this->getGatewayInstance();
            $gateway->setDemoMode((bool) $this->setting('test'));
            $gateway->setCurrency($this->data_order['currency']);
            $gateway->setSecretWord($this->setting('secretWord'));
            $gateway->setAccountNumber($this->setting('accountNumber'));

            $gateway->setCart(array(array(
                    'quantity' => 1,
                    'type' => 'product',
                    'price' => $this->data_order['total_formatted_number'],
                    'name' => $this->controller->text('Order #@num', array('@num' => $this->data_order['order_id']))
            )));

            $this->response = $gateway->purchase($this->getPurchaseParams())->send();

            if ($this->response->isRedirect()) {
                $this->response->redirect();
            } else if (!$this->response->isSuccessful()) {
                $this->redirectError();
            }
        }
    }

    /**
     * Returns an array of purchase parameters
     * @return array
     */
    protected function getPurchaseParams()
    {
        return array(
            'currency' => $this->data_order['currency'],
            'total' => $this->data_order['total_formatted_number'],
            'cancelUrl' => $this->controller->url("checkout/complete/{$this->data_order['order_id']}", array('cancel' => true), true),
            'returnUrl' => $this->controller->url("checkout/complete/{$this->data_order['order_id']}", array('paid' => true), true)
        );
    }

    /**
     * Processes gateway response
     */
    protected function processResponse()
    {
        if ($this->response->isSuccessful()) {
            $this->updateOrderStatus();
            $this->addTransaction();
            $this->redirectSuccess();
        } else if ($this->response->isRedirect()) {
            $this->response->redirect();
        } else {
            $this->redirectError();
        }
    }

    /**
     * Redirect on error transaction
     */
    protected function redirectError()
    {
        $this->controller->redirect('', $this->response->getMessage(), 'warning', true);
    }

    /**
     * Redirect on successful transaction
     */
    protected function redirectSuccess()
    {
        $vars = array(
            '@num' => $this->data_order['order_id'],
            '@status' => $this->order->getStatusName($this->data_order['status'])
        );

        $message = $this->controller->text('Thank you! Payment has been made. Order #@num, status: @status', $vars);
        $this->controller->redirect('/', $message, 'success', true);
    }

    /**
     * Update order status after successful transaction
     */
    protected function updateOrderStatus()
    {
        $data = array('status' => $this->setting('order_status_success'));
        $this->order->update($this->data_order['order_id'], $data);
        $this->data_order = $this->order->get($this->data_order['order_id']);
    }

    /**
     * Adds a transaction
     * @return integer
     */
    protected function addTransaction()
    {
        $transaction = array(
            'total' => $this->data_order['total'],
            'order_id' => $this->data_order['order_id'],
            'currency' => $this->data_order['currency'],
            'payment_method' => $this->data_order['payment'],
            'gateway_transaction_id' => $this->response->getTransactionReference()
        );

        /* @var $object \gplcart\core\models\Transaction */
        $object = $this->getModel('Transaction');
        return $object->add($transaction);
    }

}
