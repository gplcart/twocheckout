<?php

/**
 * @package 2 Checkout
 * @author Iurii Makukh <gplcart.software@gmail.com>
 * @copyright Copyright (c) 2017, Iurii Makukh <gplcart.software@gmail.com>
 * @license https://www.gnu.org/licenses/gpl-3.0.en.html GNU General Public License 3.0
 */

namespace gplcart\modules\twocheckout\controllers;

use gplcart\core\controllers\backend\Controller;
use gplcart\core\models\Order;

/**
 * Handles incoming requests and outputs data related to 2 Checkout module
 */
class Settings extends Controller
{

    /**
     * Order model instance
     * @var \gplcart\core\models\Order $order
     */
    protected $order;

    /**
     * Settings constructor.
     * @param Order $order
     */
    public function __construct(Order $order)
    {
        parent::__construct();

        $this->order = $order;
    }

    /**
     * Route page callback to display module settings form
     */
    public function editSettings()
    {
        $this->setTitleEditSettings();
        $this->setBreadcrumbEditSettings();

        $this->setData('statuses', $this->order->getStatuses());
        $this->setData('settings', $this->module->getSettings('twocheckout'));

        $this->submitSettings();
        $this->outputEditSettings();
    }

    /**
     * Saves the submitted settings
     */
    protected function submitSettings()
    {
        if ($this->isPosted('save') && $this->validateSettings()) {
            $this->updateSettings();
        }
    }

    /**
     * Updates module settings
     */
    protected function updateSettings()
    {
        $this->controlAccess('module_edit');
        $this->module->setSettings('twocheckout', $this->getSubmitted());
        $this->redirect('admin/module/list', $this->text('Settings have been updated'), 'success');
    }

    /**
     * Validates module settings
     * @return bool
     */
    protected function validateSettings()
    {
        $this->setSubmitted('settings');
        $this->setSubmittedBool('status');

        return !$this->hasErrors();
    }

    /**
     * Set title on the edit module settings page
     */
    protected function setTitleEditSettings()
    {
        $title = $this->text('Edit %name settings', array('%name' => $this->text('2 Checkout')));
        $this->setTitle($title);
    }

    /**
     * Set breadcrumbs on the edit module settings page
     */
    protected function setBreadcrumbEditSettings()
    {
        $breadcrumbs = array();

        $breadcrumbs[] = array(
            'text' => $this->text('Dashboard'),
            'url' => $this->url('admin')
        );

        $breadcrumbs[] = array(
            'text' => $this->text('Modules'),
            'url' => $this->url('admin/module/list')
        );

        $this->setBreadcrumbs($breadcrumbs);
    }

    /**
     * Render and output the edit module settings page
     */
    protected function outputEditSettings()
    {
        $this->output('twocheckout|settings');
    }

}
