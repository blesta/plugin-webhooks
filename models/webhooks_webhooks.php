<?php
/**
 * Webhooks
 *
 * @package blesta
 * @subpackage blesta.plugins.webhooks
 * @copyright Copyright (c) 2023, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class WebhooksWebhooks extends WebhooksModel
{
    /**
     * Adds a new webhook
     *
     * @param array $vars An array of webhook info including:
     *
     *  - company_id The ID of company for this webhook
     *  - callback The callback for the webhook, must be a URL for outgoing webhooks or a string for incoming webhooks
     *  - event The name of the event to listen or trigger
     *  - type The type of the webhook, it could be "incoming" or "outgoing"
     *  - method The method of th webhook, it could be "get", "post" or "json"
     *  - fields A numerically indexed array of arrays, each one containing
     *      - field The name of the field from the event
     *      - parameter The new name for the field
     * @return mixed Returns the ID of the webhook or void on error
     */
    public function add(array $vars)
    {
        if (!isset($vars['company_id'])) {
            $vars['company_id'] = Configure::get('Blesta.company_id');
        }
        if (!isset($vars['type'])) {
            $vars['type'] = 'incoming';
        }
        if (!isset($vars['method'])) {
            $vars['method'] = 'post';
        }

        // Validate fields
        $this->Input->setRules($this->getRules($vars));
        if (!$this->Input->validates($vars)) {
            return;
        }

        // Add the webhook
        $fields = [
            'company_id', 'callback', 'event', 'type', 'method'
        ];
        $this->Record->insert('webhooks', $vars, $fields);
        $webhook_id = $this->Record->lastInsertId();

        // Add webhook fields
        if (isset($vars['fields']) && is_array($vars['fields'])) {
            foreach ($vars['fields'] as $field) {
                $field['webhook_id'] = $webhook_id;
                $this->Record->insert('webhook_fields', $field, ['webhook_id', 'field', 'parameter']);
            }
        }

        // Register event for this webhook
        if ($vars['type'] = 'outgoing') {
            Loader::loadModels($this, ['PluginManager']);

            $plugin = $this->PluginManager->getByDir('webhooks', $vars['company_id']);
            $plugin = reset($plugin);

            try {
                $this->PluginManager->addEvent($plugin->id, [
                    'event' => $vars['event'],
                    'callback' => ['this', 'listen']
                ]);
            } catch (Throwable $e) {
                // Nothing to do
            }
        }

        return $webhook_id;
    }

    /**
     * Updates an existing webhook
     *
     * @param int $webhook_id The ID of the webhook to update
     * @param array $vars An array of webhook info including:
     *
     *  - company_id The ID of company for this webhook
     *  - callback The callback for the webhook, must be a URL for outgoing webhooks or a string for incoming webhooks
     *  - event The name of the event to listen or trigger
     *  - type The type of the webhook, it could be "incoming" or "outgoing"
     *  - method The method of th webhook, it could be "get", "post" or "json"
     *  - fields A numerically indexed array of arrays, each one containing
     *      - field The name of the field from the event
     *      - parameter The new name for the field
     * @return mixed Returns the ID of the webhook or void on error
     */
    public function edit(int $webhook_id, array $vars)
    {
        // Validate fields
        $this->Input->setRules($this->getRules($vars, true));
        if (!$this->Input->validates($vars)) {
            return;
        }

        if (!isset($vars['company_id'])) {
            $vars['company_id'] = Configure::get('Blesta.company_id');
        }

        // Update the webhook
        $fields = [
            'company_id', 'callback', 'event', 'type', 'method'
        ];
        $this->Record->where('webhooks.id', '=', $webhook_id)->update('webhooks', $vars, $fields);

        // Remove the existing webhook fields and add the new ones
        $this->Record->from('webhook_fields')
            ->where('webhook_fields.webhook_id', '=', $webhook_id)
            ->delete();

        if (isset($vars['fields']) && is_array($vars['fields'])) {
            foreach ($vars['fields'] as $field) {
                $field['webhook_id'] = $webhook_id;
                $this->Record->insert('webhook_fields', $field, ['webhook_id', 'field', 'parameter']);
            }
        }

        // Register event for this webhook
        if ($vars['type'] = 'outgoing') {
            Loader::loadModels($this, ['PluginManager']);

            $plugin = $this->PluginManager->getByDir('webhooks', $vars['company_id']);
            $plugin = reset($plugin);

            try {
                $this->PluginManager->addEvent($plugin->id, [
                    'event' => $vars['event'],
                    'callback' => ['this', 'listen']
                ]);
            } catch (Throwable $e) {
                // Nothing to do
            }
        }

        return $webhook_id;
    }

    /**
     * Deletes an existing webhook
     *
     * @param int $webhook_id The ID of the webhook to remove
     */
    public function delete(int $webhook_id)
    {
        // Delete the webhook
        return $this->Record->from('webhooks')
            ->leftJoin('webhook_fields', 'webhook_fields.webhook_id', '=', 'webhooks.id', false)
            ->where('webhooks.id', '=', $webhook_id)
            ->delete(['webhooks.*', 'webhook_fields.*']);
    }

    /**
     * Retrieves the rule set for adding/editing webhooks
     *
     * @param array $vars An array of input fields
     * @param bool $edit Whether or not this is an edit request
     * @return array The rules
     */
    private function getRules(array &$vars, bool $edit = false)
    {
        $rules = [
            'company_id' => [
                'exists' => [
                    'rule' => [[$this, 'validateExists'], 'id', 'companies'],
                    'message' => $this->_('WebhooksWebhooks.!error.company_id.exists')
                ]
            ],
            'callback' => [
                'exists' => [
                    'rule' => [
                        function ($callback, $type) use ($vars) {
                            // Return false if it is an outgoing webhook
                            if ($vars['type'] == 'outgoing') {
                                return false;
                            }

                            // Check if another webhook exists with this callback
                            $webhook = $this->Record->select()
                                ->from('webhooks')
                                ->where('company_id', '=', $vars['company_id'] ?? null)
                                ->where('callback', '=', $callback)
                                ->where('type', '=', $type)
                                ->fetchAll();

                            return count($webhook) > 0;
                        },
                        ['_linked' => 'type'],
                    ],
                    'negate' => true,
                    'message' => $this->_('WebhooksWebhooks.!error.callback.exists')
                ],
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => $this->_('WebhooksWebhooks.!error.callback.empty')
                ],
                'length' => [
                    'rule' => ['maxLength', 255],
                    'message' => $this->_('WebhooksWebhooks.!error.callback.length')
                ]
            ],
            'event' => [
                'exists' => [
                    'rule' => ['in_array', array_keys($this->getEvents())],
                    'message' => $this->_('WebhooksWebhooks.!error.event.exists')
                ]
            ],
            'type' => [
                'valid' => [
                    'rule' => ['in_array', array_keys($this->getTypes())],
                    'message' => $this->_('WebhooksWebhooks.!error.type.valid')
                ]
            ],
            'method' => [
                'valid' => [
                    'rule' => ['in_array', array_keys($this->getMethods())],
                    'message' => $this->_('WebhooksWebhooks.!error.method.valid')
                ]
            ],
            'fields[][field]' => [
                'empty' => [
                    'if_set' => true,
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => $this->_('WebhooksWebhooks.!error.fields[][field].empty')
                ],
                'length' => [
                    'if_set' => true,
                    'rule' => ['maxLength', 255],
                    'message' => $this->_('WebhooksWebhooks.!error.fields[][field].length')
                ]
            ],
            'fields[][parameter]' => [
                'empty' => [
                    'if_set' => true,
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => $this->_('WebhooksWebhooks.!error.fields[][parameter].empty')
                ],
                'length' => [
                    'if_set' => true,
                    'rule' => ['maxLength', 255],
                    'message' => $this->_('WebhooksWebhooks.!error.fields[][parameter].length')
                ]
            ]
        ];

        // Format fields
        if (isset($vars['fields'])) {
            foreach ($vars['fields'] as $key => $field) {
                if (empty($field['field']) && empty($field['parameter'])) {
                    unset($vars['fields'][$key]);
                }
            }

            $vars['fields'] = array_values($vars['fields']);
        }

        // Update rules if editing
        if ($edit) {
            $rules['company_id']['exists']['if_set'] = true;
            $rules['callback']['empty']['if_set'] = true;
            $rules['callback']['length']['if_set'] = true;
            $rules['event']['exists']['if_set'] = true;
            $rules['type']['valid']['if_set'] = true;
            $rules['method']['valid']['if_set'] = true;

            foreach ($vars as &$var) {
                if (empty($var)) {
                    unset($var);
                }
            }
        }

        return $rules;
    }

    /**
     * Returns a list of all available events
     *
     * @return array A list of webhook types
     */
    public function getEvents()
    {
        Loader::loadModels($this, ['Webhooks.WebhooksEvents']);

        return $this->WebhooksEvents->getAll();
    }

    /**
     * Returns a list of all available webhook types
     *
     * @return array A list of webhook types
     */
    public function getTypes()
    {
        return [
            'outgoing' => $this->_('WebhooksWebhooks.getTypes.type_outgoing'),
            'incoming' => $this->_('WebhooksWebhooks.getTypes.type_incoming')
        ];
    }

    /**
     * Returns a list of all available webhook methods
     *
     * @return array A list of webhook methods
     */
    public function getMethods()
    {
        return [
            'get' => $this->_('WebhooksWebhooks.getMethods.get'),
            'post' => $this->_('WebhooksWebhooks.getMethods.post'),
            'put' => $this->_('WebhooksWebhooks.getMethods.put'),
            'post_json' => $this->_('WebhooksWebhooks.getMethods.post_json'),
            'put_json' => $this->_('WebhooksWebhooks.getMethods.put_json')
        ];
    }

    /**
     * Fetches a single webhook
     *
     * @param int $webhook_id The ID of the webhook to fetch
     * @return array An array of stdClass objects representing webhooks
     */
    public function get(int $webhook_id)
    {
        $webhook = $this->Record->select()->from('webhooks')
            ->where('id', '=', $webhook_id)
            ->fetch();

        if ($webhook) {
            $webhook->fields = $this->Record->select(['webhook_fields.field', 'webhook_fields.parameter'])
                ->from('webhook_fields')
                ->where('webhook_id', '=', $webhook_id)
                ->fetchAll();
        }

        return $webhook;
    }

    /**
     * Fetches a single webhook by the callback and type
     *
     * @param string $callback The callback of the webhook
     * @param string $type The type of webhook (optional)
     * @return array An array of stdClass objects representing webhooks
     */
    public function getByCallback(?string $callback, string $type = 'incoming')
    {
        $webhook = $this->Record->select()->from('webhooks')
            ->where('company_id', '=', Configure::get('Blesta.company_id'))
            ->where('callback', '=', $callback)
            ->where('type', '=', $type)
            ->fetch();

        if ($webhook) {
            $webhook->fields = $this->Record->select(['webhook_fields.field', 'webhook_fields.parameter'])
                ->from('webhook_fields')
                ->where('webhook_id', '=', $webhook->id)
                ->fetchAll();
        }

        return $webhook;
    }

    /**
     * Returns the number of results available for the given type
     *
     * @param string $type The type of the webhook to filter by (optional, default "incoming"), one of:
     *
     *    - incoming All incoming webhooks
     *    - outgoing All outgoing webhooks
     * @return int The number representing the total number of services for this client with that status
     */
    public function getTypeCount($type = 'incoming')
    {
        return $this->Record->select()->from('webhooks')
            ->where('company_id', '=', Configure::get('Blesta.company_id'))
            ->where('type', '=', $type)
            ->numResults();
    }

    /**
     * Returns a list with all the webhooks for the given type
     *
     * @param string $type The type of the webhook to filter by (optional, default "incoming"), one of:
     *
     *   - incoming All incoming webhooks
     *   - outgoing All outgoing webhooks
     * @param array $order_by The sort and order conditions (e.g. array('sort_field'=>"ASC"), optional)
     * @return array An array of stdClass objects representing webhooks
     */
    public function getAll(
        $type = 'incoming',
        $order_by = ['method' => 'DESC']
    ) {
        return $this->Record->select()->from('webhooks')
            ->where('company_id', '=', Configure::get('Blesta.company_id'))
            ->where('type', '=', $type)
            ->order($order_by)
            ->fetchAll();
    }

    /**
     * Returns a list of webhooks for the given type
     *
     * @param string $type The type of the webhook to filter by (optional, default "incoming"), one of:
     *
     *  - incoming All incoming webhooks
     *  - outgoing All outgoing webhooks
     * @param int $page The page to return results for (optional, default 1)
     * @param array $order_by The sort and order conditions (e.g. array('sort_field'=>"ASC"), optional)
     * @return array An array of stdClass objects representing webhooks
     */
    public function getList(
        $type = 'incoming',
        $page = 1,
        $order_by = ['method' => 'DESC']
    ) {
        return $this->Record->select()->from('webhooks')
            ->where('company_id', '=', Configure::get('Blesta.company_id'))
            ->where('type', '=', $type)
            ->order($order_by)
            ->limit($this->getPerPage(), (max(1, $page) - 1) * $this->getPerPage())
            ->fetchAll();
    }
}
