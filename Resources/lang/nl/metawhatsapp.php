<?php

/*
 | Dutch translation.
 |
 | The field names Meta itself uses -- Phone Number ID, WhatsApp Business
 | Account ID, Access token, App secret, Verify token -- are left untranslated
 | on purpose. You copy them across from an English-only screen in Meta
 | Business Manager, so a translated label makes them harder to find, not
 | easier. The help text below each field is translated.
 */

return [
    'title'                        => 'WhatsApp Business-accounts',
    'menu_label'                   => 'WhatsApp',
    'add_account'                  => 'Account toevoegen',
    'edit_account'                 => 'Account bewerken',
    'no_accounts'                  => 'Er is nog geen WhatsApp-account ingesteld.',

    'section_identification'       => 'Kanaalgegevens',
    'section_credentials'          => 'API-gegevens',
    'section_webhook'              => 'Webhook',
    'section_template_recovery'    => 'Verlopen venster heropenen',
    'section_mailbox'              => 'Gekoppelde mailbox',

    'channel_name'                 => 'Naam van het kanaal',
    'channel_name_placeholder'     => 'bijv. WhatsApp Support',
    'phone_number'                 => 'Telefoonnummer',
    'phone_number_format'          => 'Het telefoonnummer moet in internationale notatie staan, bijvoorbeeld +31612345678.',
    'phone_number_id'              => 'Phone Number ID',
    'phone_number_id_help'         => 'Te vinden in Meta Business Manager → WhatsApp → API Setup.',
    'waba_id'                      => 'WhatsApp Business Account ID',
    'waba_id_help'                 => 'De WhatsApp Business Account ID uit Meta Business Manager.',

    'access_token'                 => 'Access token',
    'app_secret'                   => 'App secret',
    'app_secret_help'              => 'Te vinden in Meta Business Manager → App Settings → Basic.',
    'leave_blank_to_keep'          => 'Laat leeg om de huidige waarde te behouden',
    'verify_token'                 => 'Verify token',
    'verify_token_help'            => 'Automatisch gegenereerd. Neem deze waarde over in de webhook-instellingen van je Meta-app.',
    'verify_token_change_warning'  => 'Als je dit token wijzigt, moet je de webhook-instellingen in het Meta App Dashboard bijwerken. Doorgaan?',
    'regenerate_token'             => 'Nieuw token maken',

    'webhook_url'                  => 'Webhook-URL',
    'webhook_url_help'             => 'Neem deze URL over in de webhook-instellingen van je Meta-app.',
    'copy'                         => 'Kopiëren',

    'template_cost_warning'        => 'Meta rekent per afgeleverd templatebericht kosten.',
    'template_lang'                => 'Taalcode van het template',
    'template_threshold'           => 'Drempel voor een verlopen venster (minuten)',
    'template_threshold_help'      => 'Meta hanteert een venster van 24 uur na het laatste bericht van de klant. Deze instelling bepaalt alleen wanneer de module het venster als verlopen gaat behandelen, als eigen veiligheidsmarge - de regel van Meta verandert er niet door. <a href="https://developers.facebook.com/documentation/business-messaging/whatsapp/messages/send-messages" target="_blank" rel="noopener">Bekijk de documentatie van Meta</a>.',

    'templates_multi_help'         => 'Maximaal vijf templates, bijvoorbeeld één per taal. Een regel zonder naam of zonder taalcode wordt bij het opslaan weggelaten.',
    'template_row_id'              => 'Naam van het template',
    'template_row_display_name'    => 'Tekst op de knop',
    'template_row_recovery_text'   => 'Tekst die in het gesprek verschijnt',

    'mailbox'                      => 'Mailbox',
    'mailbox_mode_new'             => 'Een nieuwe mailbox maken voor dit kanaal',
    'mailbox_mode_existing'        => 'Een bestaande mailbox gebruiken',
    'mailbox_name'                 => 'Naam van de mailbox',
    'mailbox_name_help'            => 'Vooraf ingevuld met de naam van het kanaal. De gesprekken van dit kanaal komen onder deze mailbox te staan.',
    'mailbox_existing_help'        => 'Alleen mailboxen zonder ingestelde mailservers die nog niet aan een ander WhatsApp-account hangen, worden getoond.',
    'select_mailbox'               => 'Kies een mailbox',
    'no_mailboxes_short'           => 'geen beschikbaar',
    'mailbox_unlinked'             => 'Mailbox ontkoppeld',
    'mailbox_already_linked'       => 'Deze mailbox hangt al aan een ander WhatsApp-account.',

    'status'                       => 'Status',
    'active'                       => 'Actief',
    'inactive'                     => 'Inactief',

    'save'                         => 'Opslaan',
    'cancel'                       => 'Annuleren',
    'edit'                         => 'Bewerken',
    'delete'                       => 'Verwijderen',
    'delete_confirm'               => 'Dit WhatsApp-account verwijderen? De webhook stopt onmiddellijk met werken.',

    'conversation_subject'         => 'WhatsApp :phone',
    'conversation_subject_template' => 'Naam van een nieuw gesprek',
    'conversation_subject_template_help' => 'Optioneel. Laat leeg om "WhatsApp :phone" te gebruiken. Plaatshouders: %YEAR% (het huidige jaar), :phone (het telefoonnummer van de klant).',
    'phone_number_id_change_warning' => 'Je hebt de Phone Number ID gewijzigd: de webhook herkent dit account niet meer totdat je de instellingen bij Meta bijwerkt. Doorgaan?',

    'account_created'              => 'WhatsApp-account aangemaakt. Neem de webhook-URL en het verify token over in het Meta App Dashboard.',
    'account_updated'              => 'WhatsApp-account bijgewerkt.',
    'account_deleted'              => 'WhatsApp-account verwijderd.',
    'account_deleted_mailbox_kept' => 'WhatsApp-account verwijderd. De mailbox is blijven staan omdat er gesprekken in zitten.',

    // Knop "verbinding testen" en het gezondheidsoverzicht van het account.
    'test_connection_button'       => 'Verbinding testen',
    'test_connection_success'      => 'Verbinding in orde - herkend als ":name".',
    'test_connection_failed'       => 'De verbindingstest is mislukt: :error',
    'test_connection_unknown_error' => 'Onbekende fout.',
    'health_snapshot_title'        => 'Gezondheid van het account',
    'health_last_inbound'          => 'Laatste binnengekomen bericht',
    'health_last_outbound'         => 'Laatste verzendpoging',
    'health_last_status'           => 'Laatste afleverstatus',
    'health_last_error'            => 'Laatste fout',
    'health_last_reactivation'     => 'Laatst heractiveerd',
    'health_inactive_help'         => 'Zolang het kanaal inactief is, kan er niets verzonden worden. Gebruik hieronder "Verbinding testen": lukt die, dan wordt het kanaal automatisch weer actief.',
    'health_never'                 => 'Nooit',
    'account_reactivated'          => 'Verbinding hersteld - account automatisch weer actief (herkend als ":name").',

    // Melding in het gesprek als het venster verlopen is.
    'window_expired_notice'        => 'Het venster van 24 uur lijkt verlopen. Een gewoon antwoord wordt waarschijnlijk door Meta geweigerd.',
    'send_template_button'         => 'Template ":name" versturen',
    'template_sent'                => 'Het templatebericht staat klaar om verzonden te worden.',
    'template_not_configured'      => 'Voor dit WhatsApp-account is geen template ingesteld om het gesprek te heropenen.',
    'template_no_phone'            => 'Bij dit gesprek is geen telefoonnummer te vinden.',
    'template_no_phone_notice'     => 'Van deze contactpersoon is geen telefoonnummer bekend (contacten met alleen een WhatsApp-ID kunnen nog geen template ontvangen - staat gepland voor fase 2b).',
    'template_window_open'         => 'Het venster is weer open - stuur een gewoon antwoord in plaats van een betaald template.',
    'template_already_sent'        => 'Er is zojuist al een template verstuurd voor dit gesprek.',

    // Keuzelijst met templates die Meta heeft goedgekeurd.
    'account_inactive_notice'          => 'Dit WhatsApp-kanaal is op dit moment inactief, er kan dus niets verzonden worden. Open de kanaalinstellingen om de verbinding te controleren en het weer aan te zetten.',
    'not_sent_channel_inactive'   => 'Er is niets naar WhatsApp verzonden: dit kanaal is inactief. Zodra een beheerder de verbinding herstelt, moet het bericht opnieuw verstuurd worden.',
    'account_inactive_banner'      => 'Dit WhatsApp-kanaal is inactief. Antwoorden die je hier schrijft, bereiken de klant niet totdat een beheerder de verbinding herstelt.',
    'templates_section_help' => 'Stel hieronder maximaal vijf goedgekeurde templates in. Zodra er één is ingevuld, zien medewerkers één knop per template en verder niets. Is er geen enkele ingevuld, dan krijgen ze een link naar alle templates die Meta voor dit account heeft goedgekeurd. Beheerders houden die link altijd, want het is een snellere manier om namen en talen na te kijken dan WhatsApp Manager.',
    'browse_templates_admin_only' => 'Alleen zichtbaar voor beheerders.',
    'account_inactive_notice_agent' => 'Vraag een beheerder om de verbinding van het WhatsApp-kanaal te controleren.',
    'template_not_configured_agent' => 'Voor dit WhatsApp-kanaal is geen template ingesteld. Een beheerder moet er eerst een toevoegen voordat je hem kunt versturen.',
    'browse_templates_link'        => 'Alle goedgekeurde templates bekijken…',
    'templates_picker_title'       => 'Een WhatsApp-template versturen',
    'templates_picker_back'        => '← Terug naar het gesprek',
    'templates_picker_fetch_error' => 'De templates konden niet bij Meta worden opgehaald: :error',
    'templates_picker_empty'       => 'Voor dit WhatsApp Business-account zijn geen goedgekeurde templates gevonden.',
    'template_variable_label'      => 'Variabele :n',
    'template_send_button'         => 'Versturen',

    // Afleverfouten die pas later binnenkomen.
    'async_delivery_failed'        => 'WhatsApp meldt dit bericht als mislukt, nadat het eerst voor verzending was aangenomen. Fout: :error',

    // Automatisch aanmelden van de webhook.
    'webhook_subscribe_button'     => 'Webhook aanmelden',
    'webhook_subscribed_success'   => 'De webhook-aanmelding is door Meta bevestigd.',
    'webhook_subscribe_failed'     => 'Aanmelden voor de webhooks van Meta is niet gelukt: :error',

    'media_attachment_unavailable' => 'Het meegestuurde bestand kon niet bij WhatsApp worden opgehaald.',
    'media_preview_no_caption'     => 'Bijlage (:type)',
    'reaction_text'                => 'Reageerde met: :emoji',
    'reaction_removed'             => 'Heeft een reactie weggehaald',
    'reaction_text_quoted'         => 'Reageerde met :emoji op: ":excerpt"',
    'reaction_removed_quoted'      => 'Heeft een reactie weggehaald bij: ":excerpt"',
    'contacts_shared'              => 'Gedeelde contactgegevens:',
    'contacts_shared_empty'        => 'Heeft een contactkaart gedeeld (zonder naam of telefoonnummer erin).',
];
