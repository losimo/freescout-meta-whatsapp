# MetaWhatsApp — WhatsApp Business per a FreeScout via Meta Cloud API

[Català](README.ca.md) · [English](README.md) · [Castellano](README.es.md)

Mòdul per a FreeScout que integra **WhatsApp Business directament amb la Meta Cloud API**, sense intermediaris de pagament com 1msg.io o Twilio. Els missatges van de Meta a la teva instal·lació de FreeScout, amb control complet de credencials, dades i flux operatiu.

El projecte està publicat i les proves internes són completes, però encara és especialment útil trobar una empresa o persona que pugui integrar-lo i fer-lo servir durant uns dies en un entorn real per validar el comportament en producció, detectar casos límit i confirmar que el flux operatiu encaixa amb l’ús diari.

## Característiques principals

- **Channel-first**: configures un canal WhatsApp, no una bústia de correu.
- **Zero-core**: no modifica cap fitxer del core de FreeScout.
- **Fail-closed**: el webhook rebutja qualsevol petició sense signatura HMAC vàlida.
- **Integració directa amb Meta**: sense passarel·les de tercers.
- **Interfície neta de correu**: a les vistes del canal, el mòdul amaga els artefactes d'email del core (toggle Cc/Bcc, adreça tècnica interna), sense afectar les bústies de correu normals.
- **Compatible amb FreeScout 1.8.x** sobre Laravel 5.8 i PHP 8.x.

## Captures de pantalla

*Llistat de canals WhatsApp configurats:*

![Llistat de comptes WhatsApp](docs/accounts-list.png)

*Alta d'un canal nou (formulari channel-first):*

![Formulari d'alta del canal](docs/add-channel.png)

## Abast del MVP

Aquesta v1 cobreix:

- Missatges de **text pla** inbound i outbound.
- Un o més números WhatsApp, cadascun com a compte independent del mòdul.
- Creació automàtica de converses a FreeScout a partir de missatges entrants.
- Resposta des de FreeScout cap a WhatsApp respectant la finestra d'undo del core.
- Actualització best-effort dels estats `delivered` i `read` a la base de dades del mòdul.
- Des de la v1.2.0, l'estat `read` de Meta també marca el thread outbound com a obert, amb l'indicador natiu "obert" de FreeScout.
- Des de la v1.3.0, recuperació manual d'una finestra caducada amb una única plantilla HSM pre-aprovada — vegeu [Recuperació de finestra caducada](#recuperació-de-finestra-caducada-v130) més avall.

Queda fora d'abast en aquesta versió:

- Multimèdia entrant o sortint.
- Indicadors visuals de `delivered/read` a la conversa (el `read` només obre el thread — vegeu més amunt).
- Chatbots, automatitzacions avançades o integracions multicanal compartides.

## Instal·lació

1. Copia o enllaça el mòdul dins de `Modules/MetaWhatsApp` a la instal·lació de FreeScout.
2. Activa'l i executa les migracions:

```bash
php artisan module:enable MetaWhatsApp
php artisan module:migrate MetaWhatsApp
php artisan freescout:clear-cache
```

3. El mòdul apareixerà a **Gestionar → WhatsApp** per a usuaris administradors.

El mòdul crea dues taules pròpies:

- `meta_whatsapp_accounts`
- `meta_whatsapp_messages`

No fa cap `ALTER` sobre taules del core de FreeScout.

## Requisits previs a Meta

Abans de configurar el canal a FreeScout, cal tenir preparat un entorn mínim a [Meta for Developers](https://developers.facebook.com):

1. Una **App** de tipus Business amb el producte **WhatsApp** afegit.
2. Un **número de telèfon** registrat al producte WhatsApp.
3. Les dades següents:

| Valor | On trobar-lo |
|---|---|
| **Phone Number ID** | App Dashboard → WhatsApp → API Setup |
| **WABA ID** | App Dashboard → WhatsApp → API Setup |
| **Access Token** | Vegeu la nota sobre token permanent |
| **App Secret** | App Dashboard → App Settings → Basic |

> **Important sobre el token**
>
> El token que mostra la pantalla d'**API Setup** és temporal i sol caducar en 24 hores. Per a un entorn real, cal generar un **token permanent de System User** des de Meta Business Manager, assignant-li l'App i el WABA, amb els permisos:
>
> - `whatsapp_business_messaging`
> - `whatsapp_business_management`

## Configuració del canal

### A FreeScout

Des de **Gestionar → WhatsApp → Afegeix compte**:

1. Introdueix el **nom del canal**.
2. Introdueix el **número de telèfon** en format E.164 (`+34...`).
3. Omple **Phone Number ID**, **WABA ID**, **Access Token** i **App Secret**.
4. Copia el **token de verificació** generat automàticament.
5. Copia la **URL del webhook** mostrada pel mòdul (sempre té la forma `https://el-teu-domini/meta-whatsapp/webhook`, compartida per tots els comptes).
6. Tria si vols:
   - crear una bústia nova (recomanat), o
   - associar-ne una d'existent compatible (sense servidors de correu configurats i no vinculada a cap altre compte WhatsApp; el desplegable només mostra les vàlides).
7. Desa el compte.

### A Meta

Des de **App Dashboard → WhatsApp → Configuration → Webhook**:

1. A **Callback URL**, enganxa la URL del webhook del mòdul.
2. A **Verify Token**, enganxa el token de verificació generat a FreeScout.
3. Prem **Verify and save**.
4. A **Webhook fields**, activa com a mínim el camp **messages**.

> **Requisit important**
>
> La URL del webhook ha de ser pública, accessible per HTTPS i amb certificat vàlid. Meta no accepta certificats autosignats.

Quan la configuració és correcta, un missatge enviat al número de WhatsApp crearà una conversa a la bústia associada.

## Funcionament diari

- Els missatges entrants creen una conversa nova o s'afegeixen a la conversa activa del mateix client.
- La identitat del client es resol pel seu telèfon.
- Respondre des de FreeScout envia la resposta a WhatsApp **després dels 15 segons** de la finestra de desfer del core.
- Si l'agent desfà la resposta dins d'aquest marge, el missatge no s'envia.
- Les **notes internes no s'envien mai** al client.

### Finestra de 24 hores

La Meta Cloud API només permet enviar missatges lliures dins de les 24 hores posteriors a l'últim missatge del client.

Si s'intenta respondre fora de finestra:

- Meta retorna l'error `131047`.
- El missatge queda registrat com a fallit.
- El client no rep cap resposta.

Des de la v1.3.0, una finestra caducada es pot recuperar manualment amb una plantilla HSM pre-aprovada — vegeu més avall.

### Recuperació de finestra caducada (v1.3.0)

Quan la finestra del client sembla caducada, apareix un banner a la conversa que permet enviar **una única plantilla de WhatsApp pre-aprovada**, configurada per compte (nom + idioma). L'enviament és sempre **manual**: un agent prem el botó del banner; no hi ha cap reintent automàtic de plantilla.

- Només s'admet **una** plantilla per compte; no hi ha selector de plantilles ni variables/paràmetres.
- Que aparegui el banner depèn d'un **llindar operatiu intern configurable** (`template_threshold_minutes`, per defecte **1435 minuts**). Aquest llindar només determina quan el mòdul comença a tractar la finestra com a caducada per a la seva pròpia UI — no canvia la regla real de les 24 hores de Meta. Consulta la [documentació de Meta](https://developers.facebook.com/documentation/business-messaging/whatsapp/messages/send-messages).
- Abans d'enviar la plantilla de debò, el servidor torna a comprovar la finestra i rebutja la petició si el client ha tornat a escriure mentrestant (finestra reoberta) o si ja s'ha enviat una plantilla per a la mateixa conversa en els últims 60 segons (protecció contra doble clic / doble enviament).
- Meta **factura** els missatges de plantilla igual que qualsevol altra plantilla HSM, de manera independent a aquest mòdul.

### Token invàlid o caducat

Si Meta retorna l'error `190`:

- el compte passa a estat **Inactiu**,
- el canal deixa d'enviar i rebre correctament,
- i cal actualitzar el token d'accés des de l'edició del compte.

## Limitacions conegudes

Aquestes limitacions són conegudes i acceptades en l'abast del MVP:

- Només es processen missatges de **text pla**.
- Missatges entrants de tipus multimèdia, documents, àudio o reaccions no es processen com a conversa útil.
- Només **una** plantilla HSM pre-aprovada per compte (configurada al compte); sense selector de plantilles, sense variables/paràmetres, sense sincronització automàtica amb el catàleg de plantilles de Meta.
- L'enviament de la plantilla de recuperació és sempre **manual**, iniciat per un agent des del banner de la conversa; no hi ha reintent automàtic fora de finestra.
- Els estats `delivered` i `read` s'actualitzen a la base de dades del mòdul; només el `read` es mostra visualment (via l'indicador natiu "obert" del thread) — el `delivered` no es mostra a la conversa.
- Si Meta agrupa en un sol enviament de webhook esdeveniments de **números diferents**, només es processen els del compte corresponent al primer; la resta es descarta amb un avís al log. En la pràctica Meta sol enviar webhooks separats per número, però amb diversos números sota la mateixa App convé tenir-ho present.
- En mode xat, el core de FreeScout pot generar **esborranys buits** a la conversa per l'autodesat de l'editor; són innocus i es poden descartar manualment.
- La **bústia tècnica** del canal continua sent visible a **Gestionar → Bústies**.
- El webhook no implementa rate limiting propi; la barrera principal és la signatura HMAC.
- El lookup del `verify_token` al handshake no és constant-time.

## Checklist per passar a compte real

Abans de fer el pas de proves a producció:

1. ☐ Comprova que la instal·lació és accessible públicament per HTTPS.
2. ☐ Fes servir un certificat vàlid.
3. ☐ Genera un **token permanent de System User**.
4. ☐ Elimina comptes i converses de prova si ja no et calen.
5. ☐ Crea el compte real al mòdul amb les credencials definitives.
6. ☐ Configura el webhook real a Meta amb la URL i el verify token correctes.
7. ☐ Verifica que la subscripció al camp `messages` està activa.
8. ☐ Envia un missatge real al número i comprova que entra a FreeScout.
9. ☐ Respon des de FreeScout dins de la finestra de 24 hores i comprova que arriba al mòbil.
10. ☐ Verifica que el worker de cues està funcionant de manera contínua.
11. ☐ Revisa els logs després de les primeres proves reals.

## Resolució de problemes

| Símptoma | Causa probable |
|---|---|
| Meta no verifica el webhook | URL no accessible públicament, certificat invàlid o verify token incorrecte |
| Meta retorna 403 als POST del webhook | `phone_number_id` desconegut, compte inactiu o signatura HMAC invàlida |
| Els missatges entren però no surten | Error `131047` per finestra de 24 hores o error `190` per token caducat |
| El compte surt com a `⚠ Bústia desvinculada` | La bústia associada s'ha eliminat o ja no és resoluble |
| No es processa res | El worker de cues està aturat (`php artisan queue:work`) |

Tots els logs del mòdul porten el prefix `[MetaWhatsApp]`.

```bash
grep MetaWhatsApp storage/logs/laravel-$(date +%Y-%m-%d).log
```

## Tests

La suite de tests del mòdul es pot executar amb:

```bash
vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php Modules/MetaWhatsApp/Tests
```

Els tests treballen contra la base de dades de la instal·lació amb rollback per test i no deixen dades persistents.

## Llicència

AGPL-3.0, igual que FreeScout.
