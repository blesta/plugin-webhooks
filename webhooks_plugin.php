<?php
use Blesta\Core\Util\Events\Common\EventInterface;

/**
 * Webhooks plugin handler
 *
 * @package blesta
 * @subpackage blesta.plugins.webhooks
 * @copyright Copyright (c) 2023, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class WebhooksPlugin extends Plugin
{
    public function __construct()
    {
        // Load components required by this plugin
        Loader::loadComponents($this, ['Input', 'Record']);

        Language::loadLang('webhooks_plugin', null, dirname(__FILE__) . DS . 'language' . DS);
        $this->loadConfig(dirname(__FILE__) . DS . 'config.json');

        // Initialize a single instance of WebhookEvents
        if (!isset($this->WebhooksEvents)) {
            Loader::loadModels($this, ['Webhooks.WebhooksEvents']);
        }
    }

    /**
     * Performs any necessary bootstraping actions
     *
     * @param int $plugin_id The ID of the plugin being installed
     */
    public function install($plugin_id)
    {
        if (!isset($this->Record)) {
            Loader::loadComponents($this, ['Record']);
        }
        if (!isset($this->CronTasks)) {
            Loader::loadModels($this, ['CronTasks']);
        }

        // Add all webhook tables, *IFF* not already added
        try {
            // webhooks
            $this->Record->
                setField('id', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'auto_increment' => true])->
                setField('company_id', ['type' => 'int', 'size' => 10, 'unsigned' => true])->
                setField('callback', ['type' => 'varchar', 'size' => 255])->
                setField('type', ['type' => 'enum', 'size' => "'incoming','outgoing'", 'default' => 'incoming'])->
                setField('method', ['type' => 'enum', 'size' => "'get','post','put','put_json','post_json'", 'default' => 'post'])->
                setKey(['id'], 'primary')->
                create('webhooks', true);

            // webhook_events
            $this->Record->
                setField('webhook_id', ['type' => 'int', 'size' => 10, 'unsigned' => true])->
                setField('event', ['type' => 'varchar', 'size' => 255])->
                setKey(['webhook_id', 'event'], 'primary')->
                create('webhook_events', true);

            // webhook_fields
            $this->Record->
                setField('webhook_id', ['type' => 'int', 'size' => 10, 'unsigned' => true])->
                setField('field', ['type' => 'varchar', 'size' => 255])->
                setField('parameter', ['type' => 'varchar', 'size' => 255])->
                setKey(['webhook_id', 'field'], 'primary')->
                create('webhook_fields', true);
        } catch (Exception $e) {
            // Error adding... no permission?
            $this->Input->setErrors(['db'=> ['create'=>$e->getMessage()]]);
            return;
        }

        // Add cron tasks
        $this->addCronTasks($this->getCronTasks());
    }

    /**
     * Performs any necessary cleanup actions
     *
     * @param int $plugin_id The ID of the plugin being uninstalled
     * @param bool $last_instance True if $plugin_id is the last instance across
     *  all companies for this plugin, false otherwise
     */
    public function uninstall($plugin_id, $last_instance)
    {
        if (!isset($this->CronTasks)) {
            Loader::loadModels($this, ['CronTasks']);
        }
        $cron_tasks = $this->getCronTasks();

        // Remove the tables created by this plugin
        if ($last_instance) {
            try {
                $this->Record->drop('webhooks');
                $this->Record->drop('webhook_fields');
            } catch (Exception $e) {
                // Error dropping... no permission?
                $this->Input->setErrors(['db'=> ['create'=>$e->getMessage()]]);
                return;
            }

            // Remove the cron tasks
            foreach ($cron_tasks as $task) {
                $cron_task = $this->CronTasks
                    ->getByKey($task['key'], $task['dir'], $task['task_type']);
                if ($cron_task) {
                    $this->CronTasks->deleteTask($cron_task->id, $task['task_type'], $task['dir']);
                }
            }
        }

        // Remove individual cron task runs
        foreach ($cron_tasks as $task) {
            $cron_task_run = $this->CronTasks
                ->getTaskRunByKey($task['key'], $task['dir'], false, $task['task_type']);
            if ($cron_task_run) {
                $this->CronTasks->deleteTaskRun($cron_task_run->task_run_id);
            }
        }
    }

    /**
     * Performs migration of data from $current_version (the current installed version)
     * to the given file set version
     *
     * @param string $current_version The current installed version of this plugin
     * @param int $plugin_id The ID of the plugin being upgraded
     */
    public function upgrade($current_version, $plugin_id)
    {
        // Upgrade if possible
        if (version_compare($this->getVersion(), $current_version, '>')) {
            // Handle the upgrade, set errors using $this->Input->setErrors() if any errors encountered

            // Upgrade to 1.1.0
            if (version_compare($current_version, '1.1.0', '<')) {
                $this->upgrade1_1_0();
            }

            // Upgrade to 1.2.0
            if (version_compare($current_version, '1.2.0', '<')) {
                $this->upgrade1_2_0();
            }
        }
    }

    /**
     * Update to v1.1.0
     */
    private function upgrade1_1_0()
    {
        $this->Record->query(
            "ALTER TABLE `webhooks` CHANGE `method` `method` ENUM('get','post','put','put_json','post_json','json') CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL;"
        );

        $vars = ['method' => 'post_json'];
        $this->Record->where('method', '=', 'json')->
            update('webhooks', $vars, array_keys($vars));

        $this->Record->query(
            "ALTER TABLE `webhooks` CHANGE `method` `method` ENUM('get','post','put','put_json','post_json') CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL;"
        );

        // Drop the index on 'company_id'
        $this->Record->query('DROP INDEX `company_id` ON `webhooks`');

        // Add cron tasks
        $this->addCronTasks($this->getCronTasks());
    }

    /**
     * Update to v1.2.0
     */
    private function upgrade1_2_0()
    {
        // Add new webhook_events table
        try {
            $this->Record->
                setField('webhook_id', ['type' => 'int', 'size' => 10, 'unsigned' => true])->
                setField('event', ['type' => 'varchar', 'size' => 255])->
                setKey(['webhook_id', 'event'], 'primary')->
                create('webhook_events', true);
        } catch (Exception $e) {
            // Error adding... no permission?
            $this->Input->setErrors(['db'=> ['create'=>$e->getMessage()]]);
        }

        // Move events to the new table
        $webhooks = $this->Record->select()->from('webhooks')
            ->fetchAll();
        foreach ($webhooks as $webhook) {
            $this->Record->insert('webhook_events', ['webhook_id' => $webhook->id, 'event' => $webhook->event]);
        }

        // Remove webhooks.callback column
        $this->Record->query('ALTER TABLE `webhooks` DROP COLUMN `event`;');
    }

    /**
     * Returns all events to be registered for this plugin (invoked after install() or upgrade(),
     * overwrites all existing events)
     *
     * @return array A numerically indexed array containing:
     *
     *  - event The event to register for
     *  - callback A string or array representing a callback function or class/method. If a user (e.g.
     *      non-native PHP) function or class/method, the plugin must automatically define it when the plugin is loaded.
     *      To invoke an instance methods pass "this" instead of the class name as the 1st callback element.
     */
    public function getEvents()
    {
        // Get all the available events on the system
        $events = $this->WebhooksEvents->getAll();

        // Build a list of events
        $callbacks = [];
        foreach ($events as $event) {
            $callbacks[] = [
                'event' => $event,
                'callback' => ['this', 'listen']
            ];
        }

        return $callbacks;
    }

    /**
     * Listens to all events and triggers outgoing webhooks
     *
     * @param EventInterface $event The event to process
     */
    public function listen(EventInterface $event)
    {
        $this->WebhooksEvents->listen($event);
    }

    /**
     * Returns all permissions to be configured for this plugin (invoked after install(), upgrade(),
     *  and uninstall(), overwrites all existing permissions)
     *
     * @return array A numerically indexed array containing:
     *
     *  - group_alias The alias of the permission group this permission belongs to
     *  - name The name of this permission
     *  - alias The ACO alias for this permission (i.e. the Class name to apply to)
     *  - action The action this ACO may control (i.e. the Method name of the alias to control access for)
     */
    public function getPermissions()
    {
        return [
            [
                'group_alias' => 'admin_tools',
                'name' => Language::_('WebhooksPlugin.name', true),
                'alias' => 'webhooks.admin_main',
                'action' => '*'
            ]
        ];
    }

    /**
     * Returns all actions to be configured for this widget
     * (invoked after install() or upgrade(), overwrites all existing actions)
     *
     * @return array A numerically indexed array containing:
     *  - action The action to register for
     *  - uri The URI to be invoked for the given action
     *  - name The name to represent the action (can be language definition)
     */
    public function getActions()
    {
        return [
            [
                'action' => 'nav_secondary_staff',
                'uri' => 'plugin/webhooks/admin_main/',
                'name' => 'WebhooksPlugin.name',
                'options' => ['parent' => 'tools/']
            ]
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function cron($key)
    {
        if ($key === 'clear_cache') {
            $this->clearCache(Configure::get('Blesta.company_id'));
        }
    }

    /**
     * Clears the plugin cache
     *
     * @param int $company_id
     */
    private function clearCache($company_id)
    {
        if (Configure::get('Caching.on') && is_writable(CACHEDIR)) {
            Cache::clearCache(
                'event_observers',
                $company_id . DS . 'plugins' . DS . 'webhooks' . DS
            );
        }
    }

    /**
     * Retrieves cron tasks available to this plugin along with their default values
     *
     * @return array A list of cron tasks
     */
    private function getCronTasks()
    {
        return [
            // Cron task to check for incoming email tickets
            [
                'key' => 'clear_cache',
                'dir' => 'webhooks',
                'task_type' => 'plugin',
                'name' => Language::_(
                    'WebhooksPlugin.getCronTasks.clear_cache_name',
                    true
                ),
                'description' => Language::_(
                    'WebhooksPlugin.getCronTasks.clear_cache_desc',
                    true
                ),
                'type' => 'time',
                'type_value' => '12:00:00',
                'enabled' => 1
            ]
        ];
    }

    /**
     * Attempts to add new cron tasks for this plugin
     *
     * @param array $tasks A list of cron tasks to add
     */
    private function addCronTasks(array $tasks)
    {
        foreach ($tasks as $task) {
            $task_id = $this->CronTasks->add($task);

            if (!$task_id) {
                $cron_task = $this->CronTasks->getByKey(
                    $task['key'],
                    $task['dir'],
                    $task['task_type']
                );
                if ($cron_task) {
                    $task_id = $cron_task->id;
                }
            }

            if ($task_id) {
                $task_vars = ['enabled' => $task['enabled']];
                if ($task['type'] === 'interval') {
                    $task_vars['interval'] = $task['type_value'];
                } else {
                    $task_vars['time'] = $task['type_value'];
                }

                $this->CronTasks->addTaskRun($task_id, $task_vars);
            }
        }
    }
}
