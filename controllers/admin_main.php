<?php

use Blesta\Core\Util\Input\Fields\InputFields;

/**
 * Webhooks main controller
 *
 * @package blesta
 * @subpackage plugins.webhooks
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
            'Webhooks.WebhooksEvents',
            'Webhooks.WebhooksLogs'
        ]);
        $this->helpers(['Form']);
        $this->components(['SettingsCollection']);

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
     * Shows a centralized list of all webhook logs
     */
    public function logs()
    {
        $page = (isset($this->get[0]) ? (int)$this->get[0] : 1);
        $sort = (isset($this->get['sort']) ? $this->get['sort'] : 'id');
        $order = (isset($this->get['order']) ? $this->get['order'] : 'desc');

        // Build filters from POST or GET parameters
        $post_filters = [];
        if (!empty($this->post['filters'])) {
            $post_filters = $this->post['filters'];
            unset($this->post['filters']);

            foreach ($post_filters as $filter => $value) {
                if (empty($value)) {
                    unset($post_filters[$filter]);
                }
            }
        } elseif (!empty($this->get['filters'])) {
            // Support GET parameters for direct linking
            $post_filters = $this->get['filters'];

            foreach ($post_filters as $filter => $value) {
                if (empty($value)) {
                    unset($post_filters[$filter]);
                }
            }
        }

        // Get company settings
        $company_settings = $this->SettingsCollection->fetchSettings($this->Companies, $this->company_id);

        // Get all webhooks for filter dropdown
        $webhooks = $this->WebhooksWebhooks->getAll();

        // Get logs
        $logs = $this->WebhooksLogs->getAllLogs($post_filters, $page, [$sort => $order]);
        $total_results = $this->WebhooksLogs->getAllLogsCount($post_filters);

        // Set pagination parameters
        $params = ['sort' => $sort, 'order' => $order];

        // Overwrite default pagination settings
        $settings = array_merge(
            Configure::get('Blesta.pagination'),
            [
                'total_results' => $total_results,
                'uri' => $this->base_uri . 'plugin/webhooks/admin_main/logs/[p]/',
                'params' => $params
            ]
        );
        $this->setPagination($this->get, $settings);

        // Load date picker
        $this->Javascript->setFile('date.min.js');
        $this->Javascript->setFile('jquery.datePicker.min.js');
        $this->Javascript->setInline(
            'Date.firstDayOfWeek=' . ($company_settings['calendar_begins'] == 'sunday' ? 0 : 1) . ';'
        );

        // Set the input field filters for the widget
        $filters = $this->getLogsFilters($post_filters, $webhooks);
        $this->set('filters', $filters);
        $this->set('filter_vars', $post_filters);

        $this->set('logs', $logs);
        $this->set('webhooks', $webhooks);
        $this->set('sort', $sort);
        $this->set('order', $order);
        $this->set('negate_order', ($order == 'asc' ? 'desc' : 'asc'));

        return $this->renderAjaxWidgetIfAsync(isset($this->get[0]) || isset($this->get['sort']));
    }

    /**
     * Gets a list of input fields for filtering logs
     *
     * @param array $vars A list of submitted inputs that act as defaults for filter fields
     * @param array $webhooks List of all webhooks
     * @return InputFields An object representing the list of filter input fields
     */
    private function getLogsFilters(array $vars, array $webhooks)
    {
        $filters = new InputFields();

        // Set webhook filter
        $webhook_options = ['' => Language::_('AdminMain.logs.field_filterwebhook_all', true)];
        foreach ($webhooks as $webhook) {
            $webhook_options[$webhook->id] = $webhook->callback;
        }
        $webhook = $filters->label(
            Language::_('AdminMain.logs.field_filterwebhook', true),
            'webhook_id'
        );
        $webhook->attach(
            $filters->fieldSelect(
                'filters[webhook_id]',
                $webhook_options,
                isset($vars['webhook_id']) ? $vars['webhook_id'] : null,
                ['class' => 'w-100', 'id' => 'webhook_id']
            )
        );
        $filters->setField($webhook);

        // Set event filter
        $events = ['' => Language::_('AdminMain.logs.field_filterwebhook_all', true)]
            + $this->WebhooksWebhooks->getEvents();
        $event = $filters->label(
            Language::_('AdminMain.logs.field_filterevent', true),
            'event'
        );
        $event->attach(
            $filters->fieldSelect(
                'filters[event]',
                $events,
                isset($vars['event']) ? $vars['event'] : null,
                ['class' => 'w-100', 'id' => 'webhook_id']
            )
        );
        $filters->setField($event);

        // Set HTTP status filter
        $http_response = $filters->label(
            Language::_('AdminMain.logs.field_filterhttpstatus', true),
            'http_response'
        );
        $http_response->attach(
            $filters->fieldText(
                'filters[http_response]',
                isset($vars['http_response']) ? $vars['http_response'] : null,
                [
                    'class' => 'stretch',
                    'id' => 'http_response',
                    'placeholder' => Language::_('AdminMain.logs.field_filterhttpstatus', true)
                ]
            )
        );
        $filters->setField($http_response);

        // Set date start filter
        $date_start = $filters->label(
            Language::_('AdminMain.logs.field_filterdatestart', true),
            'date_start'
        );
        $date_start->attach(
            $filters->fieldText(
                'filters[date_start]',
                isset($vars['date_start']) ? $vars['date_start'] : null,
                [
                    'id' => 'date_start',
                    'class' => 'date',
                    'placeholder' => 'YYYY-MM-DD'
                ]
            )
        );
        $filters->setField($date_start);

        // Set date end filter
        $date_end = $filters->label(
            Language::_('AdminMain.logs.field_filterdateend', true),
            'date_end'
        );
        $date_end->attach(
            $filters->fieldText(
                'filters[date_end]',
                isset($vars['date_end']) ? $vars['date_end'] : null,
                [
                    'id' => 'date_end',
                    'class' => 'date',
                    'placeholder' => 'YYYY-MM-DD'
                ]
            )
        );
        $filters->setField($date_end);

        return $filters;
    }

    /**
     * Retries to execute a webhook from its log
     */
    public function retry()
    {
        if (!isset($this->post['id']) || !($log = $this->WebhooksLogs->getLog($this->post['id']))) {
            $this->redirect($this->base_uri . 'plugin/webhooks/admin_main/');
        }

        if (!($webhook = $this->WebhooksWebhooks->get($log->webhook_id)) ||
            $this->company_id != $webhook->company_id) {
            $this->redirect($this->base_uri . 'plugin/webhooks/admin_main/');
        }

        // Attempt to retry the webhook
        $this->WebhooksLogs->retryLog($log->id);

        // Set message
        if (($errors = $this->WebhooksLogs->errors())) {
            $this->flashMessage('error', $errors, null, false);
        } else {
            $this->flashMessage(
                'message',
                Language::_('AdminMain.!success.webhook_retried', true),
                null,
                false
            );
        }

        $this->redirect($this->base_uri . 'plugin/webhooks/admin_main/logs/?filters[webhook_id]=' . $webhook->id);
    }
}
