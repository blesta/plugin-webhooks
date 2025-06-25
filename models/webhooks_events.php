<?php

use Blesta\Core\Util\Events\Common\EventInterface;
use Blesta\Core\Util\Events\EventFactory;
use Blesta\Core\Util\Events\Event;

/**
 * Webhook Events
 *
 * @package blesta
 * @subpackage blesta.plugins.webhooks
 * @copyright Copyright (c) 2023, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class WebhooksEvents extends WebhooksModel
{
    /**
     * @var string The parent class all observers must inherit
     */
    private $parent_class = 'Blesta\\Core\\Util\\Events\\Observer';

    /**
     * @var array A list of directories where the event observers are located
     */
    private $observer_dirs = [
        COREDIR . 'Util' . DS . 'Events' . DS . 'Observers',
        PLUGINDIR
    ];

    /**
     * @var array Stores a list of all the loaded observers for the current instance
     */
    private $observers = [];

    /**
     * Caches the event observers
     */
    public function __construct()
    {
        parent::__construct();

        Loader::loadModels($this, ['PluginManager']);

        $cache = Cache::fetchCache(
            'event_observers',
            Configure::get('Blesta.company_id') . DS . 'plugins' . DS . 'webhooks' . DS
        );
        if ($cache) {
            $this->observers = unserialize(base64_decode($cache));
        }
    }

    /**
     * Returns a list of all observers available in the system.
     *
     * @return array Returns an array of arrays, each one containing the class and file of the system observers
     */
    public function getObservers()
    {
        if (!$this->pluginsHaveChanged() && !empty($this->observers) && is_array($this->observers)) {
            // Check if the current instance of the class, has the observers stored in memory
            return $this->observers;
        }
        
        // Get all observers
        $observers = [];
        foreach ($this->observer_dirs as $observer_dir) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($observer_dir, FilesystemIterator::FOLLOW_SYMLINKS)
            );
            $files->setMaxDepth(1);
            foreach ($files as $file) {
                if ($file->getExtension() == 'php' && $file->isFile()){
                    try {
                        // If the observer belongs to a Plugin, check if the plugin
                        // is installed for the current company
                        if (str_contains($observer_dir, PLUGINDIR)) {
                            if (!str_contains($file->getFilename(), '_observer')) {
                                continue;
                            }

                            $plugin_file = str_replace(PLUGINDIR, '', $file->getRealPath());
                            $plugin = explode(DS, $plugin_file);
                            $plugin = $plugin[0] ?? null;

                            if (!$this->PluginManager->isInstalled($plugin, Configure::get('Blesta.company_id'))) {
                                continue;
                            }
                        }

                        @include_once $file->getRealPath();
                    } catch (Throwable $e) {
                        // Nothing to do
                    }
                }
            }
        }

        foreach (get_declared_classes() as $class) {
            if (is_subclass_of($class, $this->parent_class)) {
                if (str_contains($class, '\\')) {
                    $class_parts = explode('\\', $class);
                    $event = end($class_parts);
                } else {
                    $event = str_replace('Observer', '', $class);
                }

                $observers[$event] = [
                    'event' => $event,
                    'methods' => $this->getMethods(new $class()),
                    'file' => (new ReflectionClass($class))->getFileName(),
                    'class' => $class
                ];
            }
        }

        // Cache the observers
        $this->observers = $observers;
        if (Configure::get('Caching.on') && is_writable(CACHEDIR)) {
            try {
                Cache::writeCache(
                    'event_observers',
                    base64_encode(serialize($this->observers)),
                    strtotime(Configure::get('Blesta.cache_length')) - time(),
                    Configure::get('Blesta.company_id') . DS . 'plugins' . DS . 'webhooks' . DS
                );
            } catch (Exception $e) {
                // Write to cache failed, so disable caching
                Configure::set('Caching.on', false);
            }
        }

        return $observers;
    }
    
    /**
     * Check if the list of installed plugins has changed since the last time we fetched observers
     * 
     * @return bool True if the list of plugins have changed
     */
    private function pluginsHaveChanged()
    {
        // Check if the list of plugins has changed since last time
        $installed_plugins = Cache::fetchCache(
            'installed_plugins',
            Configure::get('Blesta.company_id') . DS . 'plugins' . DS . 'webhooks' . DS
        );
        $current_plugins = $this->Form->collapseObjectArray(
            $this->PluginManager->getAll(Configure::get('Blesta.company_id')),
            'name',
            'dir'
        );

        if ($installed_plugins) {
            $installed_plugins = (array) unserialize(base64_decode($installed_plugins));
        } else {
            $installed_plugins = $current_plugins;
        }
        
        if (Configure::get('Caching.on') && is_writable(CACHEDIR)) {
            Cache::writeCache(
                'installed_plugins',
                base64_encode(serialize($current_plugins)),
                strtotime(Configure::get('Blesta.cache_length')) - time(),
                Configure::get('Blesta.company_id') . DS . 'plugins' . DS . 'webhooks' . DS
            );
        }

        return $current_plugins != $installed_plugins;
    }

    /**
     * Returns a list of all methods available for a specific observer
     *
     * @param mixed $observer The instance of the Observable object or the name of the event
     * @return array Returns a list of methods supported by the observer
     */
    public function getMethods($observer)
    {
        // Initialize observer class
        if (is_object($observer)) {
            $class = new ReflectionClass($observer);
        } else {
            $observers = $this->getObservers();
            if (!isset($observers[$observer])) {
                return [];
            } else {
                @include_once $observers[$observer]['file'];
            }

            try {
                $class = new ReflectionClass($observer);
            } catch (Throwable $e) {
                // Nothing to do
            }
        }

        if (!isset($class) || is_subclass_of($class, $this->parent_class)) {
            return [];
        }

        // Get observer methods
        $methods = [];
        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getName() == 'triggerEvent') {
                continue;
            }

            $methods[] = $method->getName();
        }

        return $methods;
    }

    /**
     * Returns a list of all available events
     *
     * @return array A list of all events available in the system
     */
    public function getAll()
    {
        $events = [];
        $observers = $this->getObservers();

        foreach ($observers as $observer) {
            foreach ($observer['methods'] as $method) {
                $events[$observer['event'] . '.' . $method] = $observer['event'] . '.' . $method;
            }
        }

        return $events;
    }

    /**
     * Triggers an event
     *
     * @param int $webhook_id The ID of the webhook to trigger the event
     * @param array $params An array of parameters to be held by this event (optional)
     * @param array $events The events to be triggered by the incoming webhook (optional)
     * @return array The list of parameters that were submitted along with any modifications made to them
     *  by the event handlers. In addition a __return__ item is included with the return array from the event.
     */
    public function trigger(int $webhook_id, array $params = [], array $events = null)
    {
        // Get webhook
        Loader::loadModels($this, ['Webhooks.WebhooksWebhooks']);
        $webhook = $this->WebhooksWebhooks->get($webhook_id);

        // Fetch all supported events
        $all_events = $this->getAll();
        if (is_array($events)) {
            $webhook->events = $events;
        }

        // Process webhook events
        $return = [];
        foreach ($webhook->events as $event) {
            // Check the provided event is valid
            if (!in_array($event, $all_events)) {
                continue;
            }

            // Format parameters
            $params = $this->getFields($webhook->id, $params);
            $log_id = $params['log_id'] ?? null;
            unset($params['log_id']);

            // Get the observer of the event
            $observers = $this->getObservers();
            $callback = explode('.', $event);
            $observer = $observers[$callback[0]];

            // Register event
            $eventFactory = new EventFactory();
            $eventListener = $eventFactory->listener();
            $eventListener->register($event, [$observer['class'], $callback[1]], $observer['file']);

            // Trigger event
            $response = $eventListener->trigger($eventFactory->event($event, $params));

            // Get the event return value
            $returnValue = $response->getReturnValue();

            // Put return in a special index
            if (!empty($returnValue)) {
                $return[$event]['__return__'] = $returnValue;
            }

            // Any return values that match the submitted params should be put in their own index to support extract() calls
            if (is_array($returnValue)) {
                foreach ($returnValue as $key => $data) {
                    if (array_key_exists($key, $params)) {
                        $return[$event][$key] = $data;
                    }
                }
            }

            $response->setReturnValue($return[$event] ?? $return);

            // Log webhook
            $this->log([
                'id' => $log_id,
                'webhook_id' => $webhook->id,
                'type' => 'incoming',
                'event' => $event,
                'fields' => (array) $params,
                'response' => serialize($return),
                'http_response' => 200
            ]);
        }

        return $return;
    }

    /**
     * Listens to all events and triggers outgoing webhooks
     *
     * @param EventInterface $event The triggered event
     * @param int $webhook_id The ID of the webhook used to trigger the event (optional)
     */
    public function listen(EventInterface $event, int $webhook_id = null)
    {
        try {
            // Get the outgoing webhook for this event
            if ($webhook_id) {
                $webhooks = [
                    $this->Record->select()->from('webhooks')
                        ->where('webhooks.id', '=', $webhook_id)
                        ->fetch()
                ];
            } else {
                $webhooks = $this->Record->select()->from('webhooks')
                    ->innerJoin('webhook_events', 'webhook_events.webhook_id', '=', 'webhooks.id', false)
                    ->where('webhooks.company_id', '=', Configure::get('Blesta.company_id'))
                    ->where('webhooks.type', '=', 'outgoing')
                    ->where('webhook_events.event', '=', $event->getName())
                    ->fetchAll();
            }

            if ($webhooks) {
                // Set time limit to 15 minutes
                set_time_limit(60*60*15);

                // Run each outgoing webhook
                foreach ($webhooks as $webhook) {
                    $request = curl_init();
                    $headers = [
                        'X-Blesta-Event: ' . $event->getName(),
                        'X-Webhook-Id: ' . $webhook->id,
                        'User-Agent: Blesta-Webhook'
                    ];

                    // Set request fields
                    $params = (array) $event->getParams();
                    $log_id = $params['log_id'] ?? null;
                    unset($params['log_id']);

                    $fields = $this->getFields($webhook->id, $params);

                    if ($webhook->method == 'get') {
                        $webhook->callback .= empty($fields) ? '' : '?' . http_build_query($fields);
                    }

                    curl_setopt($request, CURLOPT_URL, $webhook->callback);
                    curl_setopt($request, CURLOPT_RETURNTRANSFER, true);

                    // Set POST fields
                    if ($webhook->method == 'post' || $webhook->method == 'post_json') {
                        curl_setopt($request, CURLOPT_POST, true);
                    }

                    if ($webhook->method == 'post' || $webhook->method == 'put') {
                        curl_setopt($request, CURLOPT_POSTFIELDS, http_build_query($fields));
                    } else if ($webhook->method == 'post_json' || $webhook->method == 'put_json') {
                        $headers[] = 'Content-Type: application/json';
                        curl_setopt($request, CURLOPT_POSTFIELDS, json_encode($fields));
                    }

                    if ($webhook->method == 'post_json') {
                        $webhook->method = 'post';
                    }
                    if ($webhook->method == 'put_json') {
                        $webhook->method = 'put';
                    }

                    curl_setopt($request, CURLOPT_HTTPHEADER, $headers);
                    curl_setopt($request, CURLOPT_CUSTOMREQUEST, strtoupper($webhook->method));

                    // Set SSL verification
                    if (Configure::get('Blesta.curl_verify_ssl')) {
                        curl_setopt($request, CURLOPT_SSL_VERIFYPEER, true);
                        curl_setopt($request, CURLOPT_SSL_VERIFYHOST, 2);
                    } else {
                        curl_setopt($request, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($request, CURLOPT_SSL_VERIFYHOST, false);
                    }

                    // Send request
                    $response = curl_exec($request);

                    // Fetch HTTP code
                    $http_response = curl_getinfo($request, CURLINFO_HTTP_CODE);

                    // Log webhook
                    $this->log([
                        'id' => $log_id,
                        'webhook_id' => $webhook->id,
                        'type' => 'outgoing',
                        'event' => $event->getName(),
                        'fields' => (array) $fields,
                        'response' => $response,
                        'http_response' => $http_response
                    ]);

                    curl_close($request);
                }
            }
        } catch (Throwable $exception) {
            $this->logger->error(
                'Outgoing Webhook Exception',
                [$exception]
            );
        }
    }

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
            $params = (array) unserialize($log->fields);
            $params['log_id'] = $log->id;

            // Get webhook
            Loader::loadModels($this, ['Webhooks.WebhooksWebhooks']);
            $webhook = $this->WebhooksWebhooks->get($log->webhook_id);

            // Retry event
            if ($log->type == 'outgoing') {
                $eventFactory = new EventFactory();
                $this->listen(
                    $eventFactory->event(
                        $log->event,
                        $params
                    ),
                    $webhook->id
                );
            } else if ($log->type == 'incoming') {
                $this->trigger($webhook->id, $params, [$log->event]);
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
     * Returns the mapped fields from an event
     *
     * @param int $webhook_id The ID of the webhook
     * @param array $data The unmapped fields for the event
     * @return array An array containing the fields for the event
     */
    private function getFields(int $webhook_id, array $data)
    {
        Loader::loadHelpers($this, ['DataStructure']);

        $this->Array = $this->DataStructure->create('Array');

        // Get webhook mapping
        $map = $this->Form->collapseObjectArray(
            $this->Record->select()->from('webhook_fields')
                ->where('webhook_id', '=', $webhook_id)
                ->fetchAll(),
            'parameter',
            'field'
        );

        // Map fields
        $data = $this->Array->flatten($data, '.', '');
        foreach ($data as $key => $value) {
            if (array_key_exists($key, $map)) {
                $data[$map[$key]] = $value;
                unset($data[$key]);
            }
        }
        $data = $this->Array->unflatten($data, '.', '');

        return $data;
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
    private function log(array $vars)
    {
        // Set default values
        if (!is_scalar($vars['fields'])) {
            $vars['fields'] = serialize($vars['fields']);
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
