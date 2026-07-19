<?php

return [
    'title'                        => 'Comptes de WhatsApp Business',
    'menu_label'                   => 'WhatsApp',
    'add_account'                  => 'Afegeix compte',
    'edit_account'                 => 'Edita el compte',
    'no_accounts'                  => 'Encara no hi ha cap compte de WhatsApp configurat.',

    'section_identification'       => 'Identificació del canal',
    'section_credentials'          => 'Credencials de l\'API',
    'section_webhook'              => 'Webhook',
    'section_template_recovery'    => 'Recuperació de finestra caducada',
    'section_mailbox'              => 'Bústia associada',

    'channel_name'                 => 'Nom del canal',
    'channel_name_placeholder'     => 'p. ex. WhatsApp Suport',
    'phone_number'                 => 'Número de telèfon',
    'phone_number_format'          => 'El número ha de tenir format internacional, p. ex. +34600000000.',
    'phone_number_id'              => 'ID de número de telèfon',
    'phone_number_id_help'         => 'Disponible a Meta Business Manager → WhatsApp → Configuració de l\'API.',
    'waba_id'                      => 'ID de compte de WhatsApp Business',
    'waba_id_help'                 => 'ID del compte de WhatsApp Business a Meta Business Manager.',

    'access_token'                 => 'Token d\'accés',
    'app_secret'                   => 'Secret d\'aplicació',
    'app_secret_help'              => 'Disponible a Meta Business Manager → Configuració de l\'app → Bàsic.',
    'leave_blank_to_keep'          => 'Deixa-ho en blanc per mantenir el valor actual',
    'verify_token'                 => 'Token de verificació',
    'verify_token_help'            => 'Generat automàticament. Copia aquest valor a la configuració del webhook de la teva aplicació Meta.',
    'verify_token_change_warning'  => 'Canviar aquest token requereix actualitzar la configuració del webhook al Meta App Dashboard. Continuar?',
    'regenerate_token'             => 'Regenera el token',

    'webhook_url'                  => 'URL del webhook',
    'webhook_url_help'             => 'Copia aquesta URL a la configuració del webhook de la teva aplicació Meta.',
    'copy'                         => 'Copia',

    'template_name'                => 'Nom de la plantilla de recuperació',
    'template_name_help'           => 'Nom exacte d\'una plantilla ja aprovada a WhatsApp Manager. No s\'admeten variables.',
    'template_cost_warning'        => 'Meta factura els missatges de plantilla per conversa.',
    'template_lang'                => 'Codi d\'idioma de la plantilla',
    'template_threshold'           => 'Llindar de caducitat de la finestra (minuts)',
    'template_threshold_help'      => 'La finestra oficial d\'atenció al client de Meta és de 24 hores des de l\'últim missatge del client. Aquesta opció només defineix quan el mòdul comença a tractar la finestra com a caducada, com a marge de seguretat operatiu intern — no canvia la regla de Meta. <a href="https://developers.facebook.com/documentation/business-messaging/whatsapp/messages/send-messages" target="_blank" rel="noopener">Consulta la documentació de Meta</a>.',

    'mailbox'                      => 'Bústia',
    'mailbox_mode_new'             => 'Crea una bústia nova per a aquest canal',
    'mailbox_mode_existing'        => 'Utilitza una bústia existent',
    'mailbox_name'                 => 'Nom de la bústia',
    'mailbox_name_help'            => 'Pre-omplert amb el nom del canal. Les converses del canal apareixeran en aquesta bústia.',
    'mailbox_existing_help'        => 'Només es mostren bústies sense servidors de correu configurats i no vinculades a cap altre compte de WhatsApp.',
    'select_mailbox'               => 'Selecciona una bústia',
    'no_mailboxes_short'           => 'cap de disponible',
    'mailbox_unlinked'             => 'Bústia desvinculada',
    'mailbox_already_linked'       => 'Aquesta bústia ja està vinculada a un altre compte de WhatsApp.',

    'status'                       => 'Estat',
    'active'                       => 'Actiu',
    'inactive'                     => 'Inactiu',

    'save'                         => 'Desa',
    'cancel'                       => 'Cancel·la',
    'edit'                         => 'Edita',
    'delete'                       => 'Elimina',
    'delete_confirm'               => 'Vols eliminar aquest compte de WhatsApp? El webhook deixarà de funcionar immediatament.',

    'conversation_subject'         => 'WhatsApp :phone',
    'phone_number_id_change_warning' => 'Has canviat el Phone Number ID: el webhook deixarà de reconèixer aquest compte fins que actualitzis la configuració a Meta. Continuar?',

    'account_created'              => 'Compte de WhatsApp creat. Copia la URL del webhook i el token de verificació al Meta App Dashboard.',
    'account_updated'              => 'Compte de WhatsApp actualitzat.',
    'account_deleted'              => 'Compte de WhatsApp eliminat.',
    'account_deleted_mailbox_kept' => 'Compte de WhatsApp eliminat. La bústia s\'ha conservat perquè conté converses.',

    // Banner de finestra expirada (conversa).
    'window_expired_notice'        => 'La finestra de 24 hores d\'atenció al client sembla haver caducat. És probable que Meta rebutgi les respostes de text lliure.',
    'send_template_button'         => 'Envia la plantilla ":name"',
    'template_sent'                => 'Missatge de plantilla encuat per enviar.',
    'template_not_configured'      => 'Aquest compte de WhatsApp no té cap plantilla de recuperació configurada.',
    'template_no_phone'            => 'No s\'ha pogut resoldre cap número de telèfon per a aquesta conversa.',
    'template_no_phone_notice'     => 'Aquest contacte no té telèfon registrat (els contactes només amb ID de WhatsApp encara no admeten l\'enviament de plantilles — previst per a la fase 2b).',
    'template_window_open'         => 'La finestra del client ha tornat a obrir-se: envia una resposta normal en comptes d\'una plantilla de pagament.',
    'template_already_sent'        => 'Ja s\'ha enviat una plantilla fa uns moments per a aquesta conversa.',
];
