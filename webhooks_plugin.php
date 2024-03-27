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

        // Add all webhook tables, *IFF* not already added
        try {
            // webhooks
            $this->Record->
                setField('id', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'auto_increment' => true])->
                setField('company_id', ['type' => 'int', 'size' => 10, 'unsigned' => true])->
                setField('callback', ['type' => 'varchar', 'size' => 255])->
                setField('event', ['type' => 'varchar', 'size' => 255])->
                setField('type', ['type' => 'enum', 'size' => "'incoming','outgoing'", 'default' => 'incoming'])->
                setField('method', ['type' => 'enum', 'size' => "'get','post','put','put_json','post_json'", 'default' => 'post'])->
                setKey(['id'], 'primary')->
                create('webhooks', true);

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
}
