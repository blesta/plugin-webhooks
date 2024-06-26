<?php

use Blesta\Core\Util\Events\Common\EventInterface;
use Blesta\Core\Util\Events\EventFactory;

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
     * @return array The list of parameters that were submitted along with any modifications made to them
     *  by the event handlers. In addition a __return__ item is included with the return array from the event.
     */
    public function trigger(int $webhook_id, array $params = [])
    {
        // Get webhook
        Loader::loadModels($this, ['Webhooks.WebhooksWebhooks']);
        $webhook = $this->WebhooksWebhooks->get($webhook_id);

        // Fetch all supported events
        $events = $this->getAll();

        // Process webhook events
        $return = [];
        foreach ($webhook->events as $event) {
            // Check the provided event is valid
            if (!in_array($event, $events)) {
                continue;
            }

            // Format parameters
            $params = $this->getFields($webhook->id, $params);

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
        }

        return $return;
    }

    /**
     * Listens to all events and triggers outgoing webhooks
     *
     * @param EventInterface $event The triggered event
     */
    public function listen(EventInterface $event)
    {
        try {
            // Get the outgoing webhook for this event
            $webhooks = $this->Record->select()->from('webhooks')
                ->innerJoin('webhook_events', 'webhook_events.webhook_id', '=', 'webhooks.id', false)
                ->where('webhooks.company_id', '=', Configure::get('Blesta.company_id'))
                ->where('webhooks.type', '=', 'outgoing')
                ->where('webhook_events.event', '=', $event->getName())
                ->fetchAll();

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
                    $fields = $this->getFields($webhook->id, (array) $event->getParams());

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
                    curl_exec($request);
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
}
