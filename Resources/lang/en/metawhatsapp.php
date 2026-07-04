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
    'phone_number_id_change_warning' => 'You changed the Phone Number ID: the webhook will stop recognizing this account until you update the Meta configuration. Continue?',

    'account_created'              => 'WhatsApp account created. Copy the webhook URL and verify token to Meta App Dashboard.',
    'account_updated'              => 'WhatsApp account updated.',
    'account_deleted'              => 'WhatsApp account deleted.',
    'account_deleted_mailbox_kept' => 'WhatsApp account deleted. The mailbox was kept because it contains conversations.',
];
