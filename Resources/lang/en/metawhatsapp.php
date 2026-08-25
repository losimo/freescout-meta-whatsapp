<?php

return [
    'title'                        => 'WhatsApp Business Accounts',
    'menu_label'                   => 'WhatsApp',
    'add_account'                  => 'Add account',
    'edit_account'                 => 'Edit account',
    'no_accounts'                  => 'No WhatsApp accounts configured yet.',

    'section_identification'       => 'Channel identification',
    'section_credentials'          => 'API credentials',
    'section_webhook'              => 'Webhook',
    'section_template_recovery'    => 'Expired window recovery',
    'section_mailbox'              => 'Associated mailbox',

    'channel_name'                 => 'Channel name',
    'channel_name_placeholder'     => 'e.g. WhatsApp Support',
    'phone_number'                 => 'Phone number',
    'phone_number_format'          => 'The phone number must be in international format, e.g. +34600000000.',
    'phone_number_id'              => 'Phone Number ID',
    'phone_number_id_help'         => 'Found in Meta Business Manager → WhatsApp → API Setup.',
    'waba_id'                      => 'WhatsApp Business Account ID',
    'waba_id_help'                 => 'WhatsApp Business Account ID from Meta Business Manager.',

    'access_token'                 => 'Access token',
    'app_secret'                   => 'App secret',
    'app_secret_help'              => 'Found in Meta Business Manager → App Settings → Basic.',
    'leave_blank_to_keep'          => 'Leave blank to keep current value',
    'verify_token'                 => 'Verify token',
    'verify_token_help'            => 'Auto-generated. Copy this value to your Meta App webhook configuration.',
    'verify_token_change_warning'  => 'Changing this token requires updating the webhook configuration in Meta App Dashboard. Continue?',
    'regenerate_token'             => 'Regenerate token',

    'webhook_url'                  => 'Webhook URL',
    'webhook_url_help'             => 'Copy this URL to the webhook configuration of your Meta App.',
    'copy'                         => 'Copy',

    'template_name'                => 'Recovery template name',
    'template_name_help'           => 'Exact name of a template already approved in WhatsApp Manager. No variables are supported.',
    'template_cost_warning'        => 'Meta bills template messages per message delivered.',
    'template_lang'                => 'Template language code',
    'template_threshold'           => 'Window expiry threshold (minutes)',
    'template_threshold_help'      => 'Meta\'s official customer service window is 24 hours from the customer\'s last message. This setting only defines when the module starts treating the window as expired, as an internal operational safety margin - it does not change Meta\'s rule. <a href="https://developers.facebook.com/documentation/business-messaging/whatsapp/messages/send-messages" target="_blank" rel="noopener">See Meta docs</a>.',

    'templates_multi_help'         => 'Optional: configure up to 5 templates (e.g. one per language) instead of the single one above. Leave a row\'s name/language blank to skip it. When at least one row is filled in, agents see one button per template.',
    'template_row_id'              => 'Template name',
    'template_row_display_name'    => 'Button label',
    'template_row_recovery_text'   => 'Recovery text shown in the conversation',

    'mailbox'                      => 'Mailbox',
    'mailbox_mode_new'             => 'Create a new mailbox for this channel',
    'mailbox_mode_existing'        => 'Use an existing mailbox',
    'mailbox_name'                 => 'Mailbox name',
    'mailbox_name_help'            => 'Pre-filled with the channel name. Conversations of this channel will appear under this mailbox.',
    'mailbox_existing_help'        => 'Only mailboxes without email servers configured and not linked to another WhatsApp account are shown.',
    'select_mailbox'               => 'Select a mailbox',
    'no_mailboxes_short'           => 'none available',
    'mailbox_unlinked'             => 'Mailbox unlinked',
    'mailbox_already_linked'       => 'This mailbox is already linked to another WhatsApp account.',

    'status'                       => 'Status',
    'active'                       => 'Active',
    'inactive'                     => 'Inactive',

    'save'                         => 'Save',
    'cancel'                       => 'Cancel',
    'edit'                         => 'Edit',
    'delete'                       => 'Delete',
    'delete_confirm'               => 'Delete this WhatsApp account? The webhook will stop working immediately.',

    'conversation_subject'         => 'WhatsApp :phone',
    'conversation_subject_template' => 'New conversation name',
    'conversation_subject_template_help' => 'Optional. Leave empty to use "WhatsApp :phone". Placeholders: %YEAR% (current year), :phone (customer phone number).',
    'phone_number_id_change_warning' => 'You changed the Phone Number ID: the webhook will stop recognizing this account until you update the Meta configuration. Continue?',

    'account_created'              => 'WhatsApp account created. Copy the webhook URL and verify token to Meta App Dashboard.',
    'account_updated'              => 'WhatsApp account updated.',
    'account_deleted'              => 'WhatsApp account deleted.',
    'account_deleted_mailbox_kept' => 'WhatsApp account deleted. The mailbox was kept because it contains conversations.',

    // Test connection button + account health snapshot.
    'test_connection_button'       => 'Test connection',
    'test_connection_success'      => 'Connection OK - verified as ":name".',
    'test_connection_failed'       => 'Connection test failed: :error',
    'test_connection_unknown_error' => 'Unknown error.',
    'health_snapshot_title'        => 'Account health',
    'health_last_inbound'          => 'Last inbound message',
    'health_last_outbound'         => 'Last outbound attempt',
    'health_last_status'           => 'Last delivery status',
    'health_last_error'            => 'Last error',
    'health_last_reactivation'     => 'Last reactivation',
    'health_inactive_help'         => 'Nothing can be sent while the channel is inactive. Use Test connection below: a successful test reactivates it automatically.',
    'health_never'                 => 'Never',
    'account_reactivated'          => 'Connection restored - account reactivated automatically (verified as ":name").',

    // Banner de finestra expirada (conversa).
    'window_expired_notice'        => 'The 24-hour customer service window appears to be expired. Free-form replies will likely be rejected by Meta.',
    'send_template_button'         => 'Send template ":name"',
    'template_sent'                => 'Template message queued for sending.',
    'template_not_configured'      => 'No recovery template is configured for this WhatsApp account.',
    'template_no_phone'            => 'No phone number could be resolved for this conversation.',
    'template_no_phone_notice'     => 'This contact has no phone number on file (WhatsApp ID-only contacts are not yet supported for template sending - planned for phase 2b).',
    'template_window_open'         => 'The customer window is open again - send a normal reply instead of a paid template.',
    'template_already_sent'        => 'A template was already sent moments ago for this conversation.',

    // Picker dinàmic de plantilles (issue #2, punt 2 complet).
    'account_inactive_notice'          => 'This WhatsApp channel is currently inactive, so nothing can be sent. Open the channel settings to check the connection and reactivate it.',
    'browse_templates_link'        => 'Browse all approved templates…',
    'templates_picker_title'       => 'Send a WhatsApp template',
    'templates_picker_back'        => '← Back to conversation',
    'templates_picker_fetch_error' => 'Could not fetch templates from Meta: :error',
    'templates_picker_empty'       => 'No approved templates found for this WhatsApp Business Account.',
    'template_variable_label'      => 'Variable :n',
    'template_send_button'         => 'Send',

    // Reconciliació d'esdeveniments outbound.
    'async_delivery_failed'        => 'WhatsApp reported this message as failed after it was initially accepted for sending. Error: :error',

    // Registre automàtic de webhook.
    'webhook_subscribe_button'     => 'Subscribe webhook',
    'webhook_subscribed_success'   => 'Webhook subscription confirmed with Meta.',
    'webhook_subscribe_failed'     => 'Could not subscribe to Meta webhooks: :error',

    'media_attachment_unavailable' => 'The attached file could not be downloaded from WhatsApp.',
    'media_preview_no_caption'     => 'Attachment (:type)',
    'reaction_text'                => 'Reacted: :emoji',
    'reaction_removed'             => 'Removed a reaction',
    'reaction_text_quoted'         => 'Reacted :emoji to: ":excerpt"',
    'reaction_removed_quoted'      => 'Removed a reaction from: ":excerpt"',
    'contacts_shared'              => 'Shared contact(s):',
    'contacts_shared_empty'        => 'Shared a contact card (no name or phone number in it).',
];
