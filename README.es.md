# MetaWhatsApp — WhatsApp Business para FreeScout vía Meta Cloud API

[Català](README.ca.md) · [English](README.md) · [Castellano](README.es.md)

Módulo para FreeScout que integra **WhatsApp Business directamente con la Meta Cloud API**, sin intermediarios de pago como 1msg.io o Twilio. Los mensajes van de Meta a tu instalación de FreeScout, con control completo de credenciales, datos y flujo operativo.

El proyecto ya está publicado y las pruebas internas están completas, pero sigue siendo especialmente útil encontrar una empresa o persona que pueda integrarlo y usarlo durante unos días en un entorno real para validar el comportamiento en producción, detectar casos límite y confirmar que el flujo operativo encaja con el uso diario.

## Características principales

- **Channel-first**: configuras un canal de WhatsApp, no un buzón de correo.
- **Zero-core**: no modifica ningún fichero del core de FreeScout.
- **Fail-closed**: el webhook rechaza cualquier petición sin firma HMAC válida.
- **Integración directa con Meta**: sin pasarelas de terceros.
- **Interfaz limpia de correo**: en las vistas del canal, el módulo oculta los artefactos de email del core (toggle Cc/Bcc, dirección técnica interna) sin afectar a los buzones de correo normales.
- **Compatible con FreeScout 1.8.x** sobre Laravel 5.8 y PHP 8.x.

## Capturas de pantalla

*Listado de canales de WhatsApp configurados:*

![Listado de cuentas de WhatsApp](docs/accounts-list.png)

*Alta de un canal nuevo (formulario channel-first):*

![Formulario de alta del canal](docs/add-channel.png)

## Alcance del MVP

Esta v1 cubre:

- Mensajes de **texto plano** entrantes y salientes.
- Uno o más números de WhatsApp, cada uno como cuenta independiente del módulo.
- Creación automática de conversaciones en FreeScout a partir de mensajes entrantes.
- Respuesta desde FreeScout hacia WhatsApp respetando la ventana de deshacer del core.
- Actualización best-effort de los estados `delivered` y `read` en la base de datos del módulo.

Queda fuera de alcance en esta versión:

- Multimedia entrante o saliente.
- Plantillas HSM.
- Mensajes fuera de la ventana de 24 horas.
- Indicadores visuales de `delivered/read` en la conversación.
- Chatbots, automatizaciones avanzadas o integraciones multicanal compartidas.

## Instalación

1. Copia o enlaza el módulo dentro de `Modules/MetaWhatsApp` en la instalación de FreeScout.
2. Actívalo y ejecuta las migraciones:

```bash
php artisan module:enable MetaWhatsApp
php artisan module:migrate MetaWhatsApp
php artisan freescout:clear-cache
```

3. El módulo aparecerá en **Gestionar → WhatsApp** para usuarios administradores.

El módulo crea dos tablas propias:

- `meta_whatsapp_accounts`
- `meta_whatsapp_messages`

No hace ningún `ALTER` sobre tablas del core de FreeScout.

## Requisitos previos en Meta

Antes de configurar el canal en FreeScout, prepara un entorno mínimo en [Meta for Developers](https://developers.facebook.com):

1. Una **App** de tipo Business con el producto **WhatsApp** añadido.
2. Un **número de teléfono** registrado en el producto WhatsApp.
3. Los datos siguientes:

| Valor | Dónde encontrarlo |
|---|---|
| **Phone Number ID** | App Dashboard → WhatsApp → API Setup |
| **WABA ID** | App Dashboard → WhatsApp → API Setup |
| **Access Token** | Ver la nota sobre el token permanente |
| **App Secret** | App Dashboard → App Settings → Basic |

> **Importante sobre el token**
>
> El token que muestra la pantalla de **API Setup** es temporal y suele caducar en 24 horas. Para un entorno real, genera un **token permanente de System User** desde Meta Business Manager, asignándole la App y el WABA, con los permisos:
>
> - `whatsapp_business_messaging`
> - `whatsapp_business_management`

## Configuración del canal

### En FreeScout

Desde **Gestionar → WhatsApp → Añadir cuenta**:

1. Introduce el **nombre del canal**.
2. Introduce el **número de teléfono** en formato E.164 (`+34...`).
3. Rellena **Phone Number ID**, **WABA ID**, **Access Token** y **App Secret**.
4. Copia el **token de verificación** generado automáticamente.
5. Copia la **URL del webhook** mostrada por el módulo (siempre tiene la forma `https://tu-dominio/meta-whatsapp/webhook`, compartida por todas las cuentas).
6. Elige si quieres:
   - crear un buzón nuevo (recomendado), o
   - asociar uno existente compatible (sin servidores de correo configurados y no vinculado a otra cuenta de WhatsApp; el desplegable solo muestra los válidos).
7. Guarda la cuenta.

### En Meta

Desde **App Dashboard → WhatsApp → Configuration → Webhook**:

1. En **Callback URL**, pega la URL del webhook del módulo.
2. En **Verify Token**, pega el token de verificación generado en FreeScout.
3. Pulsa **Verify and save**.
4. En **Webhook fields**, activa como mínimo el campo **messages**.

> **Requisito importante**
>
> La URL del webhook debe ser pública, accesible por HTTPS y con certificado válido. Meta no acepta certificados autofirmados.

Cuando la configuración es correcta, un mensaje enviado al número de WhatsApp creará una conversación en el buzón asociado.

## Funcionamiento diario

- Los mensajes entrantes crean una conversación nueva o se añaden a la conversación activa del mismo cliente.
- La identidad del cliente se resuelve por su teléfono.
- Responder desde FreeScout envía la respuesta a WhatsApp **pasados los 15 segundos** de la ventana de deshacer del core.
- Si el agente deshace la respuesta dentro de ese margen, el mensaje no se envía.
- Las **notas internas nunca se envían** al cliente.

### Ventana de 24 horas

La Meta Cloud API solo permite enviar mensajes libres dentro de las 24 horas posteriores al último mensaje del cliente.

Si se intenta responder fuera de ventana:

- Meta devuelve el error `131047`.
- El mensaje queda registrado como fallido.
- El cliente no recibe ninguna respuesta.

En esta v1 no se implementan plantillas HSM para abrir o retomar conversaciones fuera de ventana.

### Token inválido o caducado

Si Meta devuelve el error `190`:

- la cuenta pasa a estado **Inactivo**,
- el canal deja de enviar y recibir correctamente,
- y hay que actualizar el token de acceso desde la edición de la cuenta.

## Limitaciones conocidas

Estas limitaciones son conocidas y aceptadas en el alcance del MVP:

- Solo se procesan mensajes de **texto plano**.
- Los mensajes entrantes multimedia, documentos, audio o reacciones no se procesan como conversación útil.
- No hay soporte para **plantillas HSM**.
- No se pueden enviar mensajes fuera de la ventana de 24 horas.
- Los estados `delivered` y `read` se actualizan en la base de datos del módulo, pero **no se muestran visualmente** dentro de la conversación.
- Si Meta agrupa en un solo envío de webhook eventos de **números diferentes**, solo se procesan los de la cuenta correspondiente al primero; el resto se descarta con un aviso en el log. En la práctica Meta suele enviar webhooks separados por número, pero conviene tenerlo presente con varios números bajo la misma App.
- En modo chat, el core de FreeScout puede generar **borradores vacíos** en la conversación por el autoguardado del editor; son inocuos y se pueden descartar manualmente.
- El **buzón técnico** del canal sigue siendo visible en **Gestionar → Buzones**.
- El webhook no implementa rate limiting propio; la barrera principal es la firma HMAC.
- El lookup del `verify_token` en el handshake no es constant-time.

## Checklist para pasar a cuenta real

Antes de pasar de pruebas a producción:

1. ☐ Comprueba que la instalación es accesible públicamente por HTTPS.
2. ☐ Usa un certificado válido.
3. ☐ Genera un **token permanente de System User**.
4. ☐ Elimina cuentas y conversaciones de prueba si ya no las necesitas.
5. ☐ Crea la cuenta real en el módulo con las credenciales definitivas.
6. ☐ Configura el webhook real en Meta con la URL y el verify token correctos.
7. ☐ Verifica que la suscripción al campo `messages` está activa.
8. ☐ Envía un mensaje real al número y comprueba que entra en FreeScout.
9. ☐ Responde desde FreeScout dentro de la ventana de 24 horas y comprueba que llega al móvil.
10. ☐ Verifica que el worker de colas funciona de manera continua.
11. ☐ Revisa los logs después de las primeras pruebas reales.

## Resolución de problemas

| Síntoma | Causa probable |
|---|---|
| Meta no verifica el webhook | URL no accesible públicamente, certificado inválido o verify token incorrecto |
| Meta recibe 403 en los POST del webhook | `phone_number_id` desconocido, cuenta inactiva o firma HMAC inválida |
| Los mensajes entran pero no salen | Error `131047` por ventana de 24 horas o error `190` por token caducado |
| La cuenta aparece como `⚠ Buzón desvinculado` | El buzón asociado se ha eliminado o ya no es resoluble |
| No se procesa nada | El worker de colas está parado (`php artisan queue:work`) |

Todos los logs del módulo llevan el prefijo `[MetaWhatsApp]`.

```bash
grep MetaWhatsApp storage/logs/laravel-$(date +%Y-%m-%d).log
```

## Tests

La suite de tests del módulo se puede ejecutar con:

```bash
vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php Modules/MetaWhatsApp/Tests
```

Los tests trabajan contra la base de datos de la instalación con rollback por test y no dejan datos persistentes.

## Licencia

AGPL-3.0, igual que FreeScout.
