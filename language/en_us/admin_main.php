<?php
$lang['AdminMain.!tooltip.callback'] = 'The callback represents where the request is going to be sent or received, for outgoing webhooks must be a URL and for incoming webhooks must be the name for the URL where the request would be received. e.g. http://blesta.com/plugin/webhooks/trigger/index/[Callback Name].';

$lang['AdminMain.!success.webhook_added'] = 'The webhook was added successfully!';
$lang['AdminMain.!success.webhook_updated'] = 'The webhook was updated successfully!';
$lang['AdminMain.!success.webhook_deleted'] = 'The webhook was deleted successfully!';

$lang['AdminMain.modal.delete_text'] = 'Are you sure you want to delete this webhook?';


// Index
$lang['AdminMain.index.page_title_index'] = 'Webhooks';
$lang['AdminMain.index.page_title_add'] = 'Add Webhook';
$lang['AdminMain.index.page_title_edit'] = 'Edit Webhook';

$lang['AdminMain.index.category_incoming'] = 'Incoming';
$lang['AdminMain.index.category_outgoing'] = 'Outgoing';
$lang['AdminMain.index.categorylink_addwebhook'] = 'Add Webhook';
$lang['AdminMain.index.boxtitle_webhooks'] = 'Webhooks';

$lang['AdminMain.index.heading_callback'] = 'Callback';
$lang['AdminMain.index.heading_event'] = 'Event';
$lang['AdminMain.index.heading_method'] = 'Method';
$lang['AdminMain.index.heading_options'] = 'Options';

$lang['AdminMain.index.option_edit'] = 'Edit';
$lang['AdminMain.index.option_delete'] = 'Delete';

$lang['AdminMain.index.text_description_outgoing'] = 'Sends an HTTP request to a URL when an event is triggered. The request can be sent using GET, POST, PUT or JSON.';
$lang['AdminMain.index.text_description_incoming'] = 'Receives an HTTP request and triggers an event on the system using the parameters received in the request. The request can be received using GET, POST or JSON.';

$lang['AdminMain.index.no_results'] = 'There are no webhooks available.';


// Add webhook
$lang['AdminMain.add.boxtitle_addwebhook'] = 'Add Webhook';
$lang['AdminMain.add.heading_event'] = 'Event';
$lang['AdminMain.add.heading_fields_map'] = 'Fields Map';
$lang['AdminMain.add.heading_field'] = 'Original Field';
$lang['AdminMain.add.heading_parameter'] = 'New Field';
$lang['AdminMain.add.heading_options'] = 'Options';
$lang['AdminMain.add.option_delete'] = 'Delete';
$lang['AdminMain.add.field_callback'] = 'Callback';
$lang['AdminMain.add.field_event'] = 'Event';
$lang['AdminMain.add.field_type'] = 'Callback';
$lang['AdminMain.add.field_method'] = 'Method';
$lang['AdminMain.add.field_add_field'] = 'Add Field';
$lang['AdminMain.add.field_addsubmit'] = 'Add Webhook';
$lang['AdminMain.add.text_fields_map'] = 'This section allows you to rename the name of the fields of the event being triggered to a custom name before they are sent to the callback. Subfields should be separated by a period (e.g. vars.status). To see a list of all the fields supported by each one of the events, you can check the following <a href="https://docs.blesta.com/display/dev/Event+Handlers" target="_blank">link</a>.';


// Edit webhook
$lang['AdminMain.edit.boxtitle_editwebhook'] = 'Edit Webhook';
$lang['AdminMain.edit.heading_event'] = 'Event';
$lang['AdminMain.edit.heading_fields_map'] = 'Fields Map';
$lang['AdminMain.edit.heading_field'] = 'Original Field';
$lang['AdminMain.edit.heading_parameter'] = 'New Field';
$lang['AdminMain.edit.heading_options'] = 'Options';
$lang['AdminMain.edit.option_delete'] = 'Delete';
$lang['AdminMain.edit.field_callback'] = 'Callback';
$lang['AdminMain.edit.field_event'] = 'Event';
$lang['AdminMain.edit.field_type'] = 'Callback';
$lang['AdminMain.edit.field_method'] = 'Method';
$lang['AdminMain.edit.field_add_field'] = 'Add Field';
$lang['AdminMain.edit.field_editsubmit'] = 'Edit Webhook';
$lang['AdminMain.edit.text_fields_map'] = 'This section allows you to rename the name of the fields of the event being triggered to a custom name before they are sent to the callback. Subfields should be separated by a period (e.g. vars.status). To see a list of all the fields supported by each one of the events, you can check the following <a href="https://docs.blesta.com/display/dev/Event+Handlers" target="_blank">link</a>.';
