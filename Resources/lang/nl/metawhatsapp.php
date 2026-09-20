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
    'core_outdated'               => 'Deze module is gebouwd en getest voor FreeScout :minimum en nieuwer, en je draait :current. Hij blijft hier werken, maar is op deze versie niet getest, en latere versies van FreeScout hebben beveiligingslekken gedicht. FreeScout bijwerken is de moeite waard.',
    'diagnostics_title'           => 'Uitgebreide logging',
    'diagnostics_help'            => 'Slaat de volledige WhatsApp-payloads op, en daarmee de tekst van de berichten van je klanten en hun telefoonnummers. Dat is een tweede kopie van die gesprekken, in een bestand dat niet bijgewerkt wordt als je een klant of een gesprek verwijdert. Laat het niet langer aanstaan dan je diagnose nodig heeft. Alleen beheerders kunnen het lezen.',
    'diagnostics_forced_env'      => 'De uitgebreide logging staat ook aan via METAWHATSAPP_DEBUG in de .env van FreeScout, en die gaat voor wat je hier instelt.',
    'diagnostics_state'           => 'Op dit moment',
    'diagnostics_off'             => 'Uit',
    'diagnostics_on_always'       => 'Aan, zonder einddatum',
    'diagnostics_on_until'        => 'Aan tot :date',
    'diagnostics_window'          => 'Aanzetten voor',
    'diagnostics_window_off'      => 'Uit',
    'diagnostics_window_keep'     => 'Laten zoals het is',
    'diagnostics_window_days'     => ':days dagen',
    'diagnostics_window_always'   => 'Tot ik het uitzet',
    'diagnostics_retention'       => 'Logbestanden bewaren',
    'diagnostics_retention_help'  => 'Aantal dagen. Eén bestand per dag; oudere bestanden worden verwijderd.',
    'diagnostics_view_log'        => 'Log bekijken',
    'diagnostics_saved'           => 'Uitgebreide logging bijgewerkt.',
    'app_id'                      => 'App ID',
    'app_id_help'                 => 'Optioneel. Staat naast het App secret op hetzelfde scherm bij Meta. Hiermee kan de module je laten weten wanneer je access token verloopt, in plaats van dat je daar pas achter komt als er een bericht niet meer uitgaat.',
    'token_invalid_warning'       => 'Volgens Meta is dit access token niet meer geldig. Het kanaal is opgeslagen, maar er wordt niets afgeleverd totdat je het vervangt.',
    'token_missing_scopes_warning' => 'Dit access token mist rechten: :scopes. Versturen mislukt totdat die bij Meta zijn toegekend.',
    'health_token'                => 'Access token',
    'health_token_unknown'        => 'Niet gecontroleerd. Vul hierboven de App ID in en sla op om te zien wanneer het verloopt.',
    'health_token_never_expires'  => 'Verloopt niet',
    'health_token_expired'        => 'Verlopen op :date',
    'health_token_expires'        => 'Verloopt op :date, over :days dagen',
    'not_sent_no_phone'           => 'Er is niets naar WhatsApp verzonden: van deze contactpersoon is geen telefoonnummer bekend. Het antwoord staat hier in FreeScout, maar de klant heeft het niet ontvangen.',
    'not_sent_attachment_missing' => 'Er is een bestand niet naar WhatsApp verzonden: de bijlage kon niet worden gevonden. Tekst uit hetzelfde antwoord kan wel los verstuurd zijn.',
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

    // Maandteller van serviceberichten (prijswijziging van Meta per 1 oktober 2026).
    'usage_title'            => 'Serviceberichten',
    'usage_enable'           => 'Tel de serviceberichten die vanuit FreeScout verstuurd worden',
    'usage_enable_help'      => 'Vanaf 1 oktober 2026 brengt Meta de serviceberichten in rekening die binnen het klantvenster van 24 uur verstuurd worden, met een maandelijkse vrije hoeveelheid per zakelijk telefoonnummer. Deze teller ziet alleen wat via FreeScout de deur uit ging, dus wordt dit nummer ook ergens anders gebruikt, dan ligt het echte totaal bij Meta hoger.',
    'usage_sent_since'       => ':count verstuurd vanuit FreeScout sinds :date',
    'usage_scope_help'       => 'Alleen berichten die vanuit FreeScout verstuurd zijn. Verstuurt dit nummer ook ergens anders vandaan, dan ligt het echte totaal bij Meta hoger.',

    // Groepen: niet ondersteund, maar wel getoond zodat een nummer dat in een groep zit niet onopgemerkt blijft.
    'groups_title'           => 'Groepen',
    'groups_none'            => 'Geen. Gecontroleerd op :date.',
    'groups_found'           => ':count. Gecontroleerd op :date.',
    'groups_warning'         => 'Deze module maakt nooit groepen aan en verwerkt de berichten die daarin verstuurd worden niet: ze worden geweigerd en in het log vastgelegd. Zit een nummer in een groep, dan is het daar via de API vanaf een andere plek in gezet.',

    // Statuswijzigingen van templates die Meta via de webhook meldt.
    'templates_issue_title'  => 'Templates',
    'templates_issue_value'  => ':summary, sinds :date',
    'templates_issue_help'   => 'Meta heeft de status van dit template veranderd, dus het kan niet verstuurd worden tot het in WhatsApp Manager is rechtgezet. Van hieruit is daar niets aan te doen.',

    // Accountgebeurtenissen: het optionele overzicht van wat Meta over het account gemeld heeft.
    'account_events_title'    => 'Accountgebeurtenissen',
    'account_events_help'     => 'Wat Meta over je account gemeld heeft: statuswijzigingen van templates, kwaliteitsbeoordelingen, beperkingen. Niets hierin maakt een klant herkenbaar. Wat ouder is dan 90 dagen wordt verwijderd.',
    'account_events_empty'    => 'Niets vastgelegd. Er is niets gebeurd, of het bijhouden staat uit.',
    'account_events_when'     => 'Wanneer',
    'account_events_type'     => 'Gebeurtenis',
    'account_events_severity' => 'Ernst',
    'account_events_details'  => 'Details',
    'account_events_link'     => 'Accountgebeurtenissen',
    'account_events_channel'  => 'Kanaal',
    'account_events_enable'      => 'Bijhouden wat Meta over het account meldt',
    'account_events_enable_help' => 'Statuswijzigingen van templates, wijzigingen van categorie (de categorie bepaalt wat een template kost), kwaliteitsbeoordelingen en beperkingen. Dit wordt 90 dagen bewaard en is via de link hieronder in te zien. Het gaat alleen om gegevens over het account: over klanten wordt hier niets vastgelegd. Staat uit, tenzij je het aanzet.',
    // Controles op de omgeving: dingen die de module tegenhouden en die niet van
    // de module zelf zijn, benoemd zodat een beheerder weet waar te kijken.
    'env_queue_stalled_title'       => 'Er gaat niets uit en er komt niets binnen',
    'env_queue_stalled_detail'      => 'FreeScout heeft al :minutes minuten taken in de wachtrij staan, en dat betekent dat de queue worker niet draait. WhatsApp-berichten gaan via de wachtrij, dus een antwoord lijkt in het gesprek verstuurd en gaat nooit de deur uit. Controleer of de cron op je server de planner van FreeScout elke minuut draait.',
    'env_queue_sync_title'          => 'De wachtrij draait binnen het verzoek',
    'env_queue_sync_detail'         => 'QUEUE_DRIVER staat op sync, dus berichten worden tijdens het webverzoek verstuurd in plaats van op de achtergrond. De knop Ongedaan maken van FreeScout betekent daarmee niet meer wat hij zegt: die leunt op een vertraging die sync negeert, dus de klant heeft het bericht al. Binnenkomende media wordt ook binnengehaald tijdens de aflevering van Meta zelf, en die kan daardoor aflopen. Zet QUEUE_DRIVER=database in de .env van FreeScout.',
    'env_curl_title'                => 'PHP heeft geen curl',
    'env_curl_detail'               => 'Deze module praat met Meta via curl, en op jouw hosting ontbreekt die of staat hij uit, dus er kan niets verstuurd of opgehaald worden. Vraag je hostingpartij om de curl-extensie aan te zetten.',
    'env_app_url_title'             => 'APP_URL is geen https',
    'env_app_url_detail'            => 'Meta levert alleen af op https met een geldig openbaar certificaat, dus de webhook-URL die op de kanaalpagina staat werkt zo niet. Zet APP_URL goed in de .env van FreeScout en draai php artisan freescout:clear-cache.',
    'env_logs_title'                => 'De logmap van FreeScout is niet beschrijfbaar',
    'env_logs_detail'               => 'De uitgebreide logging aanzetten zou het kanaal stilleggen in plaats van je te helpen: het log wordt geschreven voordat een bericht verwerkt wordt, dus als dat niet kan, gaat er niets in of uit. Dit komt meestal doordat er artisan-opdrachten als root gedraaid zijn. Zet de eigenaar van storage/ terug.',
    'env_memory_title'              => 'Het PHP-geheugen is mogelijk te klein voor grote bijlagen',
    'env_memory_detail'             => 'memory_limit staat op :limit MB. Binnenkomende media wordt tijdens het ophalen volledig in het geheugen gehouden en WhatsApp accepteert documenten tot 100 MB, dus een groot bestand kan de worker onderuithalen en neemt dan het hele bericht mee, niet alleen de bijlage.',
];
