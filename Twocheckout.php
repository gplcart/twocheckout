<?php

/**
 * @package 2 Checkout
 * @author Iurii Makukh <gplcart.software@gmail.com> 
 * @copyright Copyright (c) 2017, Iurii Makukh <gplcart.software@gmail.com> 
 * @license https://www.gnu.org/licenses/gpl-3.0.en.html GNU General Public License 3.0 
 */

namespace gplcart\modules\twocheckout;

use gplcart\core\Config;
use gplcart\core\models\Order as OrderModel,
    gplcart\core\models\Language as LanguageModel,
    gplcart\core\models\Transaction as TransactionModel;
use gplcart\modules\omnipay_library\OmnipayLibrary as OmnipayLibraryModule;

/**
 * Main class for 2 Checkout module
 */
class Twocheckout
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
     * 2 Checkout Omnipay instance
     * @var \Omnipay\TwoCheckoutPlus\Gateway $gateway
     */
    protected $gateway;

    /**
     * Order model instance
     * @var \gplcart\core\models\Order $order
     */
    protected $order;

    /**
     * Transaction model instance
     * @var \gplcart\core\models\Transaction $transaction
     */
    protected $transaction;

    /**
     * Language model instance
     * @var \gplcart\core\models\Language $language
     */
    protected $language;

    /**
     * Config class instance
     * @var \gplcart\core\Config $config
     */
    protected $config;

    /**
     * Omnipay library module instance
     * @var \gplcart\modules\omnipay_library\OmnipayLibrary
     */
    protected $omnipay_library_module;

    /**
     * Constructor
     * @param Config $config
     * @param LanguageModel $language
     * @param OrderModel $order
     * @param TransactionModel $transaction
     * @param OmnipayLibraryModule $omnipay_library_module
     */
    public function __construct(Config $config, LanguageModel $language,
            OrderModel $order, TransactionModel $transaction,
            OmnipayLibraryModule $omnipay_library_module)
    {
        $this->order = $order;
        $this->config = $config;
        $this->language = $language;
        $this->transaction = $transaction;

        $this->omnipay_library_module = $omnipay_library_module;
        $this->gateway = $this->omnipay_library_module->getGatewayInstance('TwoCheckoutPlus');
    }

    /**
     * Module info
     * @return array
     */
    public function info()
    {
        return array(
            'core' => '1.x',
            'name' => '2 Checkout',
            'version' => '1.0.0-alfa.1',
            'description' => 'Provides 2 Checkout payment method',
            'author' => 'Iurii Makukh <gplcart.software@gmail.com>',
            'license' => 'GNU General Public License 3.0',
            'dependencies' => array('omnipay_library' => '>= 1.0'),
            'configure' => 'admin/module/settings/twocheckout',
            'settings' => $this->getDefaultSettings()
        );
    }

    /**
     * Returns an array of default module settings
     * @return array
     */
    protected function getDefaultSettings()
    {
        return array(
            'test' => true,
            'status' => true,
            'order_status_success' => $this->order->getStatusProcessing(),
            // Gateway specific params
            'accountNumber' => '',
            'secretWord' => ''
        );
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
        $this->validateGateway($result);
    }

    /**
     * Implements hook "module.install.before"
     * @param mixed $result
     */
    public function hookModuleInstallBefore(&$result)
    {
        $this->validateGateway($result);
    }

    /**
     * Checks the gateway object is loaded
     * @param mixed $result
     */
    protected function validateGateway(&$result)
    {
        if (!$this->gateway instanceof \Omnipay\TwoCheckoutPlus\Gateway) {
            $result = $this->language->text('Unable to load @name gateway', array('@name' => '2 Checkout'));
        }
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
            'title' => $this->language->text('2 Checkout'),
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
        return $this->config->module('twocheckout', $name, $default);
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
     */
    public function hookOrderAddBefore(array &$order)
    {
        // Adjust order status before creation
        // We want to get payment in advance, so assign "awaiting payment" status
        if ($order['payment'] === 'twocheckout') {
            $order['status'] = $this->order->getStatusAwaitingPayment();
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
     * @param \gplcart\core\controllers\frontend\Controller $controller
     * @return null
     */
    public function hookOrderCompletePage(array $order, $controller)
    {
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
            $this->response = $this->gateway->completePurchase($this->getPurchaseParams())->send();
            $this->processResponse();
        }
    }

    /**
     * Handles submitted payment
     */
    protected function submit()
    {
        if ($this->controller->isPosted('pay')) {

            $this->gateway->setDemoMode((bool) $this->setting('test'));
            $this->gateway->setCurrency($this->data_order['currency']);
            $this->gateway->setSecretWord($this->setting('secretWord'));
            $this->gateway->setAccountNumber($this->setting('accountNumber'));

            $this->gateway->setCart(array(array(
                    'quantity' => 1,
                    'price' => $this->data_order['total_formatted_number'],
                    'name' => $this->language->text('Order #@num', array('@num' => $this->data_order['order_id']))
            )));

            $this->response = $this->gateway->purchase($this->getPurchaseParams())->send();

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
        $data = array(
            'status' => $this->setting('order_status_success'));
        $this->order->update($this->data_order['order_id'], $data);

        // Load fresh data
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

        return $this->transaction->add($transaction);
    }

}
