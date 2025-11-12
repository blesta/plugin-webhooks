<?php

use Blesta\Core\Util\Events\EventFactory;

/**
 * Webhook Logs
 *
 * @package blesta
 * @subpackage blesta.plugins.webhooks
 * @copyright Copyright (c) 2023, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class WebhooksLogs extends WebhooksModel
{
    /**
     * Retry an outgoing webhook
     *
     * @param int $log_id The ID of the webhook to be retried
     * @return mixed An object representing a logged request
     */
    public function retryLog(int $log_id)
    {
        $log = $this->getLog($log_id);

        if ($log) {
            $params = (array) json_decode($log->fields, true);
            $params['log_id'] = $log->id;

            // Get webhook
            Loader::loadModels($this, ['Webhooks.WebhooksWebhooks', 'Webhooks.WebhooksEvents']);
            $webhook = $this->WebhooksWebhooks->get($log->webhook_id);

            // Retry event
            if ($log->type == 'outgoing') {
                $eventFactory = new EventFactory();
                $this->WebhooksEvents->listen(
                    $eventFactory->event(
                        $log->event,
                        $params
                    ),
                    $webhook->id
                );
            } else if ($log->type == 'incoming') {
                $this->WebhooksEvents->trigger($webhook->id, $params, [$log->event]);
            }
        }
    }

    /**
     * Fetch a webhook log
     *
     * @param int $id The ID of the webhook to fetch the logs
     * @return mixed An object representing a logged request
     */
    public function getLog(int $id)
    {
        return $this->Record->select()->from('log_webhooks')
            ->where('id', '=', $id)
            ->fetch();
    }

    /**
     * Fetch the logs for a webhook
     *
     * @param int $webhook_id The ID of the webhook to fetch the logs
     * @param int $page The page to return results for (optional, default 1)
     * @param array $order_by The order by clause (optional, default ['id' => 'DESC'])
     * @return mixed An array of objects, each one representing a logged request
     */
    public function getLogs(int $webhook_id, $page = 1, $order_by = ['id' => 'DESC'])
    {
        return $this->Record->select()->from('log_webhooks')
            ->where('webhook_id', '=', $webhook_id)
            ->order($order_by)
            ->limit($this->getPerPage(), (max(1, $page) - 1) * $this->getPerPage())
            ->fetchAll();
    }

    /**
     * Fetch the number of logs available for a webhook
     *
     * @param int $webhook_id The ID of the webhook to fetch the logs
     * @return int The number representing the total number of logs for this webhook
     */
    public function getLogsCount(int $webhook_id)
    {
        return $this->Record->select()->from('log_webhooks')
            ->where('webhook_id', '=', $webhook_id)
            ->numResults();
    }

    /**
     * Fetch all logs across all webhooks
     *
     * @param array $filters An array of filters including:
     *
     *  - webhook_id The ID of the webhook to filter by (optional)
     *  - event The event name to filter by (optional)
     *  - http_response The HTTP response code to filter by (optional)
     *  - date_start The start date to filter by (optional)
     *  - date_end The end date to filter by (optional)
     * @param int $page The page to return results for (optional, default 1)
     * @param array $order_by The order by clause (optional, default ['id' => 'DESC'])
     * @return mixed An array of objects, each one representing a logged request
     */
    public function getAllLogs($filters = [], $page = 1, $order_by = ['id' => 'DESC'])
    {
        $this->Record->select(['log_webhooks.*', 'webhooks.callback', 'webhooks.method'])
            ->from('log_webhooks')
            ->innerJoin('webhooks', 'webhooks.id', '=', 'log_webhooks.webhook_id', false);

        // Apply filters
        if (!empty($filters['webhook_id'])) {
            $this->Record->where('log_webhooks.webhook_id', '=', $filters['webhook_id']);
        }
        if (!empty($filters['event'])) {
            $this->Record->where('log_webhooks.event', '=', $filters['event']);
        }
        if (!empty($filters['http_response'])) {
            $this->Record->where('log_webhooks.http_response', '=', $filters['http_response']);
        }
        if (!empty($filters['date_start'])) {
            $this->Record->where('log_webhooks.date_triggered', '>=', $this->dateToUtc($filters['date_start']));
        }
        if (!empty($filters['date_end'])) {
            $this->Record->where('log_webhooks.date_triggered', '<=', $this->dateToUtc($filters['date_end'] . ' 23:59:59'));
        }

        return $this->Record->order($order_by)
            ->limit($this->getPerPage(), (max(1, $page) - 1) * $this->getPerPage())
            ->fetchAll();
    }

    /**
     * Fetch the number of all logs available across all webhooks
     *
     * @param array $filters An array of filters including:
     *
     *  - webhook_id The ID of the webhook to filter by (optional)
     *  - event The event name to filter by (optional)
     *  - http_response The HTTP response code to filter by (optional)
     *  - date_start The start date to filter by (optional)
     *  - date_end The end date to filter by (optional)
     * @return int The number representing the total number of logs
     */
    public function getAllLogsCount($filters = [])
    {
        $this->Record->select('log_webhooks.*')
            ->from('log_webhooks')
            ->innerJoin('webhooks', 'webhooks.id', '=', 'log_webhooks.webhook_id', false);

        // Apply filters
        if (!empty($filters['webhook_id'])) {
            $this->Record->where('log_webhooks.webhook_id', '=', $filters['webhook_id']);
        }
        if (!empty($filters['event'])) {
            $this->Record->where('log_webhooks.event', '=', $filters['event']);
        }
        if (!empty($filters['http_response'])) {
            $this->Record->where('log_webhooks.http_response', '=', $filters['http_response']);
        }
        if (!empty($filters['date_start'])) {
            $this->Record->where('log_webhooks.date_triggered', '>=', $this->dateToUtc($filters['date_start']));
        }
        if (!empty($filters['date_end'])) {
            $this->Record->where('log_webhooks.date_triggered', '<=', $this->dateToUtc($filters['date_end'] . ' 23:59:59'));
        }

        return $this->Record->numResults();
    }

    /**
     * Deletes webhook logs older than the given date
     *
     * @param string $date The date before which to delete logs
     * @return int The number of logs deleted
     */
    public function deleteLogs($date)
    {
        $this->Record->from('log_webhooks')
            ->where('date_triggered', '<', $date)
            ->delete();

        return $this->Record->affectedRows();
    }

    /**
     * Logs a webhook event
     *
     * @param array $vars An array containing the webhook information to log:
     *
     *  - staff_id The ID of the staff member who manually triggered the webhook (optional)
     *  - webhook_id The ID of the webhook associated to this log
     *  - event The event triggered by the webhook
     *  - fields An array of fields sent by the webhook
     *  - response The raw response returned by the callback
     *  - http_response The HTTP response from the callback
     */
    public function log(array $vars)
    {
        // Set default values
        if (!is_scalar($vars['fields'])) {
            $vars['fields'] = json_encode($vars['fields']);
        }
        if (!is_scalar($vars['response'])) {
            $vars['response'] = json_encode($vars['response'], JSON_PRETTY_PRINT);
        }
        if (!isset($vars['http_response'])) {
            $vars['http_response'] = 500;
        }
        if (!isset($vars['staff_id'])) {
            $vars['staff_id'] = null;
        }
        if (!isset($vars['response'])) {
            $vars['response'] = '';
        }
        if (!isset($vars['type'])) {
            $vars['type'] = 'outgoing';
        }

        $vars['date_triggered'] = $this->dateToUtc(date('c'));
        $vars['date_last_retry'] = null;

        $fields = [
            'staff_id', 'webhook_id', 'type', 'event',
            'fields', 'response', 'http_response',
            'date_triggered', 'date_last_retry'
        ];

        // Check if we are updating an existing log
        if (!empty($vars['id'])) {
            $log = $this->Record->select()->from('log_webhooks')
                ->where('id', '=', $vars['id'])
                ->fetch();

            if ($log) {
                $vars['date_triggered'] = $log->date_triggered;
                $vars['date_last_retry'] = $this->dateToUtc(date('c'));
                unset($vars['id']);

                $this->Record->where('log_webhooks.id', '=', $log->id)->
                    update('log_webhooks', $vars, $fields);
            }
        } else {
            $this->Record->insert('log_webhooks', $vars, $fields);
        }
    }
}
