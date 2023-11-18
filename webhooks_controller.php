<?php
/**
 * Webhooks parent controller
 *
 * @package blesta
 * @subpackage blesta.plugins.webhooks
 * @copyright Copyright (c) 2023, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class WebhooksController extends AppController
{
    /**
     * Require admin to be login and setup the view
     */
    public function preAction()
    {
        $this->structure->setDefaultView(APPDIR);
        parent::preAction();

        $this->view->view = "default";

        // Require login
        $this->requireLogin();

        // Auto load language for the controller
        Language::loadLang([Loader::fromCamelCase(get_class($this))], null, dirname(__FILE__) . DS . 'language' . DS);
    }
}
