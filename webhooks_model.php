<?php
/**
 * Webhooks Parent Model
 *
 * @package blesta
 * @subpackage plugins.webhooks
 * @copyright Copyright (c) 2023, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class WebhooksModel extends AppModel
{
    public function __construct()
    {
        parent::__construct();

        Loader::loadHelpers($this, ['Form']);

        // Auto load language for these models
        Language::loadLang([Loader::fromCamelCase(get_class($this))], null, dirname(__FILE__) . DS . 'language' . DS);
    }
}
