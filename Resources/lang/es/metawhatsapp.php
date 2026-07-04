<?php

return [
    'title'                        => 'Cuentas de WhatsApp Business',
    'menu_label'                   => 'WhatsApp',
    'add_account'                  => 'Añadir cuenta',
    'edit_account'                 => 'Editar cuenta',
    'no_accounts'                  => 'Aún no hay ninguna cuenta de WhatsApp configurada.',

    'section_identification'       => 'Identificación del canal',
    'section_credentials'          => 'Credenciales de la API',
    'section_webhook'              => 'Webhook',
    'section_mailbox'              => 'Buzón asociado',

    'channel_name'                 => 'Nombre del canal',
    'channel_name_placeholder'     => 'p. ej. WhatsApp Soporte',
    'phone_number'                 => 'Número de teléfono',
    'phone_number_format'          => 'El número debe tener formato internacional, p. ej. +34600000000.',
    'phone_number_id'              => 'ID de número de teléfono',
    'phone_number_id_help'         => 'Disponible en Meta Business Manager → WhatsApp → Configuración de la API.',
    'waba_id'                      => 'ID de cuenta de WhatsApp Business',
    'waba_id_help'                 => 'ID de la cuenta de WhatsApp Business en Meta Business Manager.',

    'access_token'                 => 'Token de acceso',
    'app_secret'                   => 'Secreto de aplicación',
    'app_secret_help'              => 'Disponible en Meta Business Manager → Configuración de la app → Básico.',
    'leave_blank_to_keep'          => 'Déjalo en blanco para mantener el valor actual',
    'verify_token'                 => 'Token de verificación',
    'verify_token_help'            => 'Generado automáticamente. Copia este valor en la configuración del webhook de tu aplicación Meta.',
    'verify_token_change_warning'  => 'Cambiar este token requiere actualizar la configuración del webhook en el Meta App Dashboard. ¿Continuar?',
    'regenerate_token'             => 'Regenerar token',

    'webhook_url'                  => 'URL del webhook',
    'webhook_url_help'             => 'Copia esta URL en la configuración del webhook de tu aplicación Meta.',
    'copy'                         => 'Copiar',

    'mailbox'                      => 'Buzón',
    'mailbox_mode_new'             => 'Crear un buzón nuevo para este canal',
    'mailbox_mode_existing'        => 'Usar un buzón existente',
    'mailbox_name'                 => 'Nombre del buzón',
    'mailbox_name_help'            => 'Pre-rellenado con el nombre del canal. Las conversaciones del canal aparecerán en este buzón.',
    'mailbox_existing_help'        => 'Solo se muestran buzones sin servidores de correo configurados y no vinculados a otra cuenta de WhatsApp.',
    'select_mailbox'               => 'Selecciona un buzón',
    'no_mailboxes_short'           => 'ninguno disponible',
    'mailbox_unlinked'             => 'Buzón desvinculado',
    'mailbox_already_linked'       => 'Este buzón ya está vinculado a otra cuenta de WhatsApp.',

    'status'                       => 'Estado',
    'active'                       => 'Activo',
    'inactive'                     => 'Inactivo',

    'save'                         => 'Guardar',
    'cancel'                       => 'Cancelar',
    'edit'                         => 'Editar',
    'delete'                       => 'Eliminar',
    'delete_confirm'               => '¿Eliminar esta cuenta de WhatsApp? El webhook dejará de funcionar inmediatamente.',

    'conversation_subject'         => 'WhatsApp :phone',
    'phone_number_id_change_warning' => 'Has cambiado el Phone Number ID: el webhook dejará de reconocer esta cuenta hasta que actualices la configuración en Meta. ¿Continuar?',

    'account_created'              => 'Cuenta de WhatsApp creada. Copia la URL del webhook y el token de verificación en el Meta App Dashboard.',
    'account_updated'              => 'Cuenta de WhatsApp actualizada.',
    'account_deleted'              => 'Cuenta de WhatsApp eliminada.',
    'account_deleted_mailbox_kept' => 'Cuenta de WhatsApp eliminada. El buzón se ha conservado porque contiene conversaciones.',
];
