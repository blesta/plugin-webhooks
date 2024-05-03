<?php
/**
 * Webhooks trigger controller
 *
 * @package blesta
 * @subpackage blesta.plugins.webhooks
 * @copyright Copyright (c) 2023, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class Trigger extends AppController
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
    }

    /**
     * Triggers an event when invoked
     */
    public function index()
    {
        $callback = (isset($this->get[0]) ? $this->get[0] : null);
        if (!($webhook = $this->WebhooksWebhooks->getByCallback($callback))) {
            return false;
        }

        // Get the parameters
        switch ($webhook->method) {
            case 'get':
                $params = $this->get;
                break;
            case 'post':
                $params = $this->post;
                break;
            case 'put':
                $params = [];
                parse_str(file_get_contents('php://input'), $params);
                break;
            case 'json':
            case 'post_json':
            case 'put_json':
                $post = file_get_contents('php://input');
                $params = (array) json_decode($post, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    $params = $this->post;
                }
                break;
        }

        // Trigger the event
        $output = $this->WebhooksEvents->trigger($webhook->id, $params);

        $this->outputAsJson($output);

        return false;
    }
}
