<?php
/**
 * Webhooks main controller
 *
 * @package blesta
 * @subpackage blesta.plugins.webhooks
 * @copyright Copyright (c) 2023, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class AdminMain extends WebhooksController
{
    /**
     * Setup
     */
    public function preAction()
    {
        parent::preAction();

        // Load required models
        $this->uses([
            'Webhooks.WebhooksWebhooks',
            'Webhooks.WebhooksEvents'
        ]);
        $this->helpers(['Form']);

        $this->structure->set(
            'page_title',
            Language::_('AdminMain.index.page_title_' . ($this->action ?? 'index'), true)
        );
    }

    /**
     * Shows a list of all webhooks
     */
    public function index()
    {
        $type = (isset($this->get[0]) ? $this->get[0] : 'outgoing');
        $page = (isset($this->get[1]) ? (int)$this->get[1] : 1);
        $sort = (isset($this->get['sort']) ? $this->get['sort'] : 'method');
        $order = (isset($this->get['order']) ? $this->get['order'] : 'desc');

        $this->set('type', $type);
        $this->set('sort', $sort);
        $this->set('order', $order);
        $this->set('negate_order', ($order == 'asc' ? 'desc' : 'asc'));

        // Set the number of webhooks of each type
        $type_count = [
            'outgoing' => $this->WebhooksWebhooks->getTypeCount('outgoing'),
            'incoming' => $this->WebhooksWebhooks->getTypeCount('incoming')
        ];

        // Get webhooks
        $webhooks = $this->WebhooksWebhooks->getList($type, $page, [$sort => $order]);
        $total_results = $this->WebhooksWebhooks->getTypeCount($type);

        // Set pagination parameters, set group if available
        $params = ['sort' => $sort, 'order' => $order];

        // Overwrite default pagination settings
        $settings = array_merge(
            Configure::get('Blesta.pagination'),
            [
                'total_results' => $total_results,
                'uri' => $this->base_uri . 'plugin/webhooks/admin_main/index/' . $type . '/[p]/',
                'params' => $params
            ]
        );
        $this->setPagination($this->get, $settings);

        $this->set('webhooks', $webhooks);
        $this->set('page', $page);
        $this->set('type_count', $type_count);

        // Render the request if ajax
        return $this->renderAjaxWidgetIfAsync(isset($this->get[1]) || isset($this->get['sort']));
    }

    /**
     * Adds a new webhook
     */
    public function add()
    {
        // Get all the available events
        $events = $this->WebhooksEvents->getAll();

        // Get all the available types
        $types = $this->WebhooksWebhooks->getTypes();

        // Get all the available methods
        $methods = $this->WebhooksWebhooks->getMethods();

        // Add webhook
        if (!empty($this->post)) {
            $this->WebhooksWebhooks->add($this->post);

            if (($errors = $this->WebhooksWebhooks->errors())) {
                // Error, reset vars
                $this->setMessage('error', $errors, false, null, false);
                $vars = (object) $this->post;
            } else {
                // Success
                $this->flashMessage('message', Language::_('AdminMain.!success.webhook_added', true), null, false);
                $this->redirect($this->base_uri . 'plugin/webhooks/admin_main/');
            }
        }

        $this->set('events', $events);
        $this->set('types', $types);
        $this->set('methods', $methods);
        $this->set('vars', $vars ?? (object) []);
    }

    /**
     * Updates an existing webhook
     */
    public function edit()
    {
        $webhook_id = (isset($this->get[0]) ? $this->get[0] : null);
        if (!($webhook = $this->WebhooksWebhooks->get($webhook_id))) {
            $this->redirect($this->base_uri . 'plugin/webhooks/admin_main/');
        }

        // Get all the available events
        $events = $this->WebhooksEvents->getAll();

        // Get all the available types
        $types = $this->WebhooksWebhooks->getTypes();

        // Get all the available methods
        $methods = $this->WebhooksWebhooks->getMethods();

        // Update webhook
        $vars = (object) $webhook;
        if (!empty($this->post)) {
            $this->WebhooksWebhooks->edit($webhook_id, $this->post);

            if (($errors = $this->WebhooksWebhooks->errors())) {
                // Error, reset vars
                $this->setMessage('error', $errors, false, null, false);
                $vars = (object) $this->post;
            } else {
                // Success
                $this->flashMessage('message', Language::_('AdminMain.!success.webhook_updated', true), null, false);
                $this->redirect($this->base_uri . 'plugin/webhooks/admin_main/');
            }
        }

        $this->set('events', $events);
        $this->set('types', $types);
        $this->set('methods', $methods);
        $this->set('webhook', $webhook);
        $this->set('vars', $vars ?? (object) []);
    }

    /**
     * Shows the logs of an existing webhook
     */
    public function view()
    {
        $page = (isset($this->get[1]) ? (int)$this->get[1] : 1);
        $sort = (isset($this->get['sort']) ? $this->get['sort'] : 'id');
        $order = (isset($this->get['order']) ? $this->get['order'] : 'desc');

        $webhook_id = (isset($this->get[0]) ? $this->get[0] : null);
        if (!($webhook = $this->WebhooksWebhooks->get($webhook_id))) {
            $this->redirect($this->base_uri . 'plugin/webhooks/admin_main/');
        }

        // Get all the available events
        $events = $this->WebhooksEvents->getAll();

        // Get all the available types
        $types = $this->WebhooksWebhooks->getTypes();

        // Get all the available methods
        $methods = $this->WebhooksWebhooks->getMethods();

        // Get logs
        $logs = $this->WebhooksEvents->getLogs($webhook->id, $page, [$sort => $order]);
        $total_results = $this->WebhooksEvents->getLogsCount($webhook->id);

        // Set pagination parameters, set group if available
        $params = ['sort' => $sort, 'order' => $order];

        // Overwrite default pagination settings
        $settings = array_merge(
            Configure::get('Blesta.pagination'),
            [
                'total_results' => $total_results,
                'uri' => $this->base_uri . 'plugin/webhooks/admin_main/view/' . $webhook->id . '/[p]/',
                'params' => $params
            ]
        );
        $this->setPagination($this->get, $settings);

        $this->set('events', $events);
        $this->set('types', $types);
        $this->set('methods', $methods);
        $this->set('webhook', $webhook);
        $this->set('logs', $logs);

        return $this->renderAjaxWidgetIfAsync(isset($this->get[1]) || isset($this->get['sort']));
    }

    /**
     * Delete a webhook
     */
    public function delete()
    {
        if (!isset($this->post['id']) || !($webhook = $this->WebhooksWebhooks->get($this->post['id'])) ||
            $this->company_id != $webhook->company_id) {
            $this->redirect($this->base_uri . 'plugin/webhooks/admin_main/');
        }

        // Attempt to delete the webhook
        $this->WebhooksWebhooks->delete($webhook->id);

        // Set message
        if (($errors = $this->WebhooksWebhooks->errors())) {
            $this->flashMessage('error', $errors, null, false);
        } else {
            $this->flashMessage(
                'message',
                Language::_('AdminMain.!success.webhook_deleted', true),
                null,
                false
            );
        }

        $this->redirect($this->base_uri . 'plugin/webhooks/admin_main/');
    }

    /**
     * Retries to execute a webhook from its log
     */
    public function retry()
    {
        if (!isset($this->post['id']) || !($log = $this->WebhooksEvents->getLog($this->post['id']))) {
            $this->redirect($this->base_uri . 'plugin/webhooks/admin_main/');
        }

        if (!($webhook = $this->WebhooksWebhooks->get($log->webhook_id)) ||
            $this->company_id != $webhook->company_id) {
            $this->redirect($this->base_uri . 'plugin/webhooks/admin_main/');
        }

        // Attempt to retry the webhook
        $this->WebhooksEvents->retryLog($log->id);

        // Set message
        if (($errors = $this->WebhooksEvents->errors())) {
            $this->flashMessage('error', $errors, null, false);
        } else {
            $this->flashMessage(
                'message',
                Language::_('AdminMain.!success.webhook_retried', true),
                null,
                false
            );
        }

        $this->redirect($this->base_uri . 'plugin/webhooks/admin_main/view/' . $webhook->id . '/');
    }
}
