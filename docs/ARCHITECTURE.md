# Arquitectura de AI WhatsApp Automation

Documento de referencia del módulo `ai_whatsapp_automation` (Drupal 11.4, PHP 8.4). Explica qué hace cada pieza, cómo se relacionan y qué reglas no son obvias leyendo el código. Revisado contra el código el 2026-10-09.

Para desplegar, ver [DEPLOY.md](DEPLOY.md). Para el correo por Amazon SES, [SES_API_SETUP.md](SES_API_SETUP.md). El otro módulo propio, el monitoreo de servidores `ai_adminops`, tiene su propio documento: [AI_ADMINOPS.md](AI_ADMINOPS.md).

## Qué es

Una plataforma **multicliente** de asistentes con IA para WhatsApp y para un chat web incrustable. Cada cliente (empresa) tiene sus bots, sus números de WhatsApp, sus bases de conocimiento, sus conversaciones y sus prospectos. Un administrador (José) configura todo; los agentes de cada cliente entran a un panel donde solo ven y operan lo suyo.

En producción: `https://app.josera.com.mx`. Cliente activo: JG Mylard (seguros de transporte de mercancías).

## Capas

```
src/
  Entity/          Entidades de contenido y sus manejadores (lista, acceso, almacenamiento).
  Application/     Lógica de negocio, en servicios. Aquí vive todo lo que decide algo.
    AI/            Motor de conversación, prompts, filtro de ruido.
    WebChat/       Chat web: configuración pública, sesiones, límites, dominios.
    Webhook/       Entrada de mensajes de los proveedores, cola, envío, formato.
    Lead/          Detección de prospectos y aviso al cliente.
    HumanOperator/ Paso de la IA a una persona y vuelta.
    Client/        Asignación de registros a clientes y chequeo de consistencia.
    RAG/           Documentos, fragmentos, embeddings y búsqueda vectorial.
    Evolution/     Conexión de números por QR (Evolution API).
    Dashboard/     Métricas del panel.
    OpenAI/        Interfaz del proveedor de modelo.
  Infrastructure/  Implementaciones contra servicios externos (OpenAI).
  Controller/      Puntos de entrada HTTP. Delegan en Application/.
  Form/            Formularios. Delegan en Application/.
  Access/, Routing/ Acceso por cliente.
  Plugin/          Colas y el plugin de correo.
  Ui/              Piezas de interfaz reutilizables (gráfica, filtro de cliente, tabla responsiva).
```

La regla: Controllers, Forms y hooks son entradas; la decisión vive en un servicio de `Application/`. Los servicios están en `ai_whatsapp_automation.services.yml`.

## Entidades

| Entidad | Para qué | Referencias clave |
|---|---|---|
| `ai_whatsapp_client` (Client) | La empresa cliente. | — |
| `ai_whatsapp_bot` (Bot) | Un asistente: prompt, modelo, reglas de traspaso, **toda la configuración del widget web**, límites. | client, knowledge_base, lead_notification_account |
| `ai_whatsapp_account` (WhatsAppAccount) | Un número de WhatsApp y su proveedor (Twilio, Cloud API o Evolution). | client, bot, knowledge_base |
| `ai_whatsapp_conversation` | Una conversación con un contacto, por WhatsApp o web. | client, bot, whatsapp_account, assigned_operator (user) |
| `ai_whatsapp_message` | Cada mensaje: `sender` contact / ai / operator, tokens y coste. | conversation |
| `ai_whatsapp_lead` | Un prospecto detectado. | client, conversation, bot |
| `ai_whatsapp_operator_action` | Bitácora de lo que hace una persona en una conversación. | conversation, user |
| `ai_whatsapp_knowledge_base` / `_document` / `_chunk` | RAG: base, documento subido y fragmentos con su embedding. | client (base), file |

### El modelo multicliente

- El campo `client` se declara una sola vez en `Client::referenceField()` y lo usan Bot, WhatsAppAccount, KnowledgeBase, Conversation y Lead.
- En Conversation y Lead **se rellena solo** al guardar (hooks `presave`), a partir del bot o de la cuenta. Nunca se escribe a mano.
- El usuario tiene el campo `ai_whatsapp_client` (añadido en `hook_entity_base_field_info`). El formulario de usuario exige elegirlo si el rol es «Atención a clientes».
- **Acceso:** el rol `ai_whatsapp_client_agent` tiene `access own client ai whatsapp records`, `operate own client ai whatsapp conversations`, `view ai whatsapp automation dashboard` y `view the administration theme`. Las consultas se restringen en `hook_query_TAG_alter` (`_ai_whatsapp_automation_restrict_to_client`), y las operaciones en `Access\ClientAccess`. **Los agentes de cliente no editan bots ni cuentas:** solo ven y operan conversaciones y prospectos de su empresa.

## Flujos

### WhatsApp

```
Proveedor ─▶ WebhookController ─▶ WebhookProviderService (normaliza Twilio / Cloud API / Evolution)
          ─▶ WebhookQueueService (procesa al momento; si falla, va a la cola, que el cron vacía)
          ─▶ WebhookProcessorService ─▶ ConversationEngineService ─▶ OpenAIService
          ─▶ WhatsAppTextFormatter ─▶ ProviderMessageSenderService ─▶ Proveedor
                                     └▶ LeadHandoffService (¿prospecto listo? crear y avisar)
```

- **Qué cuenta contesta un número** (`BotManagerService::getAccountForProviderMessage()`): la cuenta **activa** del proveedor cuyo número coincide (`WhatsAppAccount::answers()`: solo cuenta el estado; `connection_status` es cosa de Evolution, que ya mantiene los dos en sincronía). Si dos cuentas activas comparten número, gana la más antigua y queda un aviso en el registro, pero el formulario de la cuenta ya no deja guardar ese caso. Si el número pertenece a una cuenta desactivada, el mensaje se guarda **sin contestar** y **no** se pasa a otra cuenta. Solo un identificador que ninguna cuenta conoce cae en la única cuenta activa del proveedor, por si el proveedor manda el destino en otro formato. Hasta el 2026-10-10 bastaba con `connection_status = CONNECTED` para contestar, así que desactivar una cuenta de Twilio no detenía a su bot. Pruebas: `tests/src/Kernel/AI/AccountRoutingTest.php`.
- **Ruteo por remitente (dueño)**, desde el 2026-10-10, en `BotManagerService::botForSender()`. Una cuenta puede tener `owner_bot` y `owner_numbers` (números completos con lada; `+52…` y `+521…` valen lo mismo). Los números del dueño hablan con el bot del dueño y **cualquier otro número habla con el bot de la cuenta**. Se decide **en cada mensaje** a partir del remitente, sin mirar el bot que guardó la conversación, así que una conversación creada cuando el bot público era el privado no deja pasar a un desconocido. **Falla cerrado**: si el bot del dueño es también el bot de la cuenta, un desconocido no recibe respuesta. Los ajustes de la cuenta (prompt, modelo, base de conocimiento) se aplican solo al bot público; el del dueño usa los suyos, y su base de conocimiento privada **debe vivir en el bot, nunca en la cuenta**. El formulario rechaza números sin lada, el mismo bot en los dos papeles, un bot de otro cliente, números sin bot o bot sin números, y la base del dueño puesta en la cuenta. Los números que reciben los avisos de prospectos (`lead_notification_numbers`, o la lista global si la cuenta no tiene) no pasan a ningún bot: reciben un texto automático una vez cada 24 h. **Excepción: el dueño de una cuenta con ruteo de dueño siempre llega a su bot**, aunque también reciba los avisos (2026-10-10). Una cuenta sin dueño configurado (como la de JG) funciona exactamente como antes, y guarda lo mismo en la conversación. Pruebas: `tests/src/Kernel/Webhook/OwnerRoutingTest.php`, que leen qué prompt y qué modelo llegan a OpenAI.
- Notas de voz y adjuntos se guardan con un marcador (🎤 Nota de voz, 🖼️ Imagen…) y se contesta con `media_reply_text` del bot.
- El formato de WhatsApp no es Markdown: el formateador convierte negritas y listas (viñetas anidadas como `◦`).

### Chat web

```
Sitio del cliente
  <script async src="/ai-whatsapp-automation/embed/{token}">   ← WebChatController::embed
      pinta el botón flotante y crea el iframe (oculto hasta el clic)
  <iframe src="/ai-whatsapp-automation/chat/{token}">          ← WebChatController::page
      HTML autónomo + css/web-chat.css + js/web-chat.js
      js/web-chat.js ─▶ POST /ai-whatsapp-automation/api/web-chat/{token}  ← WebChatController::message
                       ─▶ WebChatService::processMessage ─▶ el mismo motor de conversación
```

- `{token}` es `web_widget_token` o, si está vacío, el UUID del bot.
- **Dominios permitidos** (`web_widget_allowed_domains`): `WebChatService::isRequestAllowed()` comprueba Origin/Referer; `WebChatFrameResponseSubscriber` pone `Content-Security-Policy: frame-ancestors` con esos dominios y quita `X-Frame-Options`. Por eso **el chat real no se puede incrustar en el propio panel** si el dominio del panel no está en la lista.
- Límites por bot: mensajes por ventana, conversaciones diarias y presupuesto diario (`WebChatLimitException` → 429).
- `widgetConfig()` en `WebChatService` es la única fuente de la configuración pública (nombre, colores, logo, imagen del botón, bienvenida, posición, icono, tamaño, idioma). Los colores salen siempre saneados (`WebChatService::safeColor()`): lo que se imprime en el sitio de un cliente nunca es texto libre.

#### El botón flotante (desde el 2026-10-09)

- **Un solo dibujante:** `js/widget-launcher.js` (`window.AIWhatsAppAutomationLauncher.paint()`) pinta el color y el icono. `WebChatController::embed()` sirve en **una sola respuesta** la configuración, `widget-launcher.js` y `js/widget-embed.js` (que monta el botón y el iframe). El formulario del bot carga el mismo `widget-launcher.js` para la vista previa, así que la vista previa no puede divergir del botón real. No se divide en varias peticiones: el código que un cliente ya pegó en su sitio es una sola etiqueta `<script>`.
- **Iconos:** `chat`, `sparkles`, `help` (SVG) e `image`, que usa `web_widget_button_image` o, si no hay, el logo; si la imagen no carga, vuelve al icono de chat. El color del icono (blanco u oscuro) se elige por contraste WCAG.
- **Colores:** restricción `Regex` en la propia definición del campo (`Bot::COLOR_PATTERN`, solo `#RGB` o `#RRGGBB`), así que valida el formulario y cualquier otro guardado. El **principal** pinta el botón, la línea bajo la cabecera, las burbujas del visitante y el botón de enviar. El **secundario** (en el formulario, *header color*) pinta la **cabecera del chat** desde el 2026-10-09; su texto sale blanco u oscuro según el contraste (`WebChatService::readableInk()`, la misma regla que `inkFor()` en JS). La actualización 11040 pasó los secundarios sin tocar (`#111827`, que nunca se vio) al azul marino `#12336E` que todas las cabeceras ya mostraban: ningún sitio cambió de aspecto.
- **Vista previa** (`js/bot-widget-preview.js`, `css/bot-widget-preview.css`): una maqueta, no el chat real en un iframe, porque `frame-ancestors` solo deja incrustar el chat en los dominios del bot. Añade un selector de color nativo junto a cada campo hex, sigue el idioma del widget y avisa si el texto blanco sobre el color principal baja de 4.5:1. La cabecera de la maqueta copia la de `css/web-chat.css`: si cambia una, hay que cambiar la otra.
- Las animaciones del chat se apagan con `prefers-reduced-motion: reduce`.
- Pruebas: `tests/src/Kernel/WebChat/WebChatWidgetAppearanceTest.php`.

### El motor de conversación

`ConversationEngineService` es el único punto por el que pasan los dos canales:

1. **Filtro de ruido** (`UnintelligibleMessageDetector`): un mensaje sin sentido no llega al modelo, así que no se paga. La escalera es invitar a reescribir, luego ofrecer una salida, y después silencio. Los textos son por bot.
2. `PromptBuilderService` arma el prompt del bot con el contexto del RAG (`RAGService`).
3. `OpenAIService` llama al modelo con el `reasoning_effort` del bot.

### Prospectos

`LeadHandoffService::isLeadReady()` exige a la vez: un contacto, N grupos de señales (`handoff_required_fields`, **un grupo por línea**, y las comas son alternativas dentro de la línea) y una frase de traspaso o de resumen en la respuesta. Un bot con menos grupos que `handoff_minimum_fields` **nunca** crearía prospectos, y le pasó dos veces (Laboratorio JVC el 2026-09-24 y Hermes el 2026-10-10), así que desde el 2026-10-10 el formulario del bot **no deja guardar** esa combinación cuando el traspaso está activo. Además muestra bajo el mínimo cuántos grupos tiene el bot de verdad (`LeadHandoffService::signalGroupCount()`, contados con el mismo analizador que usa la detección). Pruebas en `tests/src/Kernel/Lead/HandoffConfigurationTest.php`, incluida la configuración exacta de JG Mylard (8 grupos, mínimo 6), que debe seguir aceptándose.

### Operador humano

`HumanOperatorService` detiene la IA, asigna a una persona, permite responder a mano y reactivar la IA. Cada acción queda en OperatorAction. Las conversaciones inactivas se cierran por cron (`ai_whatsapp_automation_close_stale_conversations`).

## Configuración

- `ai_whatsapp_automation.settings`: claves de `openai`, `twilio`, `whatsapp_cloud`, `evolution` y `options` (textos por defecto, entre otros). En local, la llave de OpenAI va en `settings.local.php` como sobrescritura de configuración. Ojo: el SID y el token de Twilio de cada número **sí se guardan en la base de datos**, en los campos de WhatsAppAccount.
- `ai_whatsapp_automation.client_mail`: correos a los agentes de cliente (con vista previa).
- **Idioma:** el sitio está en español. Los formularios de configuración del bot y de ajustes se quedan en inglés **por decisión de José** («solo yo las veo»). Todo lo que ve un cliente va en español.

## Tema y panel

- `josera_mkt`: el armazón del panel (barra lateral, barra superior con «Cerrar sesión», menú plegable en móvil). Los tokens de color viven en `css/brand.css`.
- `josera_access`: la pantalla de acceso, con la marca tomada de la configuración del sitio.
- `Ui\ResponsiveTable` + `css/responsive-tables.css`: tablas que se desplazan dentro de su contenedor en pantallas estrechas.

## Convenciones

- **Actualizaciones de base de datos:** `ai_whatsapp_automation_update_110NN`, sin huecos. La última es la **11041** (ruteo por remitente: `owner_bot` y `owner_numbers` en las cuentas). Un campo nuevo en una entidad necesita su update con `installFieldStorageDefinition()`.
- **Pruebas:** `ddev exec "SIMPLETEST_DB=mysql://db:db@db/db vendor/bin/phpunit -c web/core web/modules/custom/ai_whatsapp_automation/tests"`. Son 133 pruebas y 1240 aserciones, con una deprecación conocida (`#[LegacyRequirementsHook]`). Las llamadas HTTP están simuladas, así que no gastan dinero.
- **Revisión visual:** `scripts/revision-visual.mjs` recorre 14 pantallas en 5 anchos. Hay que correrlo como `josera` y como el agente del cliente (`agente_jg` en la base anterior, `aseguramientojg` en la importada de producción el 2026-10-10). Lo que está dentro de un `<svg>` solo cuenta como desborde si el `svg` mismo se sale, porque el svg recorta su contenido; está probado que un desborde real de la gráfica se sigue detectando.
- **Despliegue:** no se pisa nunca el `web/.htaccess` de producción (lleva el manejador de PHP 8.4). Ver [DEPLOY.md](DEPLOY.md).

## Deuda conocida

- La configuración de prospectos puede quedar imposible en silencio (ver «Prospectos»).
- `#[LegacyRequirementsHook]` por migrar.
