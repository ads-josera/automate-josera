# AI AdminOps: estado y arquitectura

Documento de referencia del módulo `ai_adminops`, que monitorea los servidores de José y avisa de los problemas. Revisado contra el código y los datos el 2026-10-09. La especificación original está en [Nuevo _Modulo_AI_Adminops.md](Nuevo%20_Modulo_AI_Adminops.md) y el diseño de SSH en [AI_ADMINOPS_READ_ONLY_SSH.md](AI_ADMINOPS_READ_ONLY_SSH.md).

## En una frase

Cada 15 minutos lee por SSH seis indicadores de los servidores registrados, guarda cada lectura, abre un evento cuando un valor pasa su umbral (y lo cierra cuando vuelve a la normalidad), y puede avisar por correo o WhatsApp. **Hoy no tiene inteligencia artificial ni acciones sobre los servidores.**

## Qué funciona y qué no

| Pieza | Estado | Comprobación |
|---|---|---|
| Registro de servidores (entidad de configuración `ai_adminops_server`) | Funciona | Hay uno: "WHM produccion Josera MKT", conexión SSH, referencia `whm_produccion_observer`. |
| Lectura por SSH (`ReadOnlySshConnector`) | **Funciona** | En producción, del 2026-08-11 al 2026-09-16 (cuando se tomó la copia local), 3,416 lecturas de cada indicador, todas con éxito: una cada 15 min. |
| Carga, CPU, memoria y disco | Funcionan | Valores reales: carga ~1, CPU ~46 %, memoria ~37 %, disco 53 %. |
| **Cola de Exim** | **Nunca funcionó** | Las 3,416 lecturas devolvieron `unavailable`. Probablemente falta la parte del wrapper remoto. |
| **SSL** | **Nunca configurado** | Las 3,416 devolvieron `not_configured`. |
| Alertas (`MonitoringAlertEvaluator`) | El código funciona, pero **están APAGADAS en producción** | Reproducido en local el 2026-10-09: con carga 21 contra umbral 4 abre un evento, no lo duplica y lo resuelve al bajar la carga. **Confirmado en producción el 2026-10-09: `alerts.enabled: false`, `unreachable_enabled: false`, 0 eventos**, con el monitoreo encendido y 33,570 lecturas (la última, ese mismo día). En los datos, la carga pasó de 4 al menos 25 veces, siempre hacia las 02:00 (probablemente los respaldos). **El monitoreo mide pero nunca ha avisado de nada.** |
| Notificaciones (`NotificationBridge`) | Código presente; **APAGADAS en producción** (correo y WhatsApp, sin destinatarios) | Reutiliza el envío de WhatsApp de `ai_whatsapp_automation` y el correo del sitio (SES). Nunca probadas de punta a punta. |
| Solicitudes de acción con aprobación | Funciona solo como registro | Aprobar no ejecuta nada (lo dice la pantalla). |
| Herramientas (9 plugins `AdminOpsTool`) | **Catálogo sin ejecución** | `execute()` lanza "no connector enabled yet" en las nueve, incluidas las de lectura. El monitoreo no pasa por ellas: llama directo al conector. Los reinicios (Apache, MySQL, PHP-FPM) no pueden hacer nada, lo cual hoy es lo seguro. |
| **IA** (`AdminOpsAiClient`) | **No se usa** | Registrado como servicio; nadie lo llama. El propósito central de la especificación (un agente que ayude mediante IA) no está implementado. |
| Pruebas automáticas | Desde el 2026-10-09 | `tests/src/Kernel/`: `AdminOpsKernelTestBase` sustituye el SSH por lecturas simuladas (`MonitoringCollectorInterface`), así que ninguna prueba toca un servidor real. |

## Seguridad (revisada, está bien)

- El conector acepta **solo seis lecturas fijas**, sin argumentos.
- Ejecuta `ssh` con `Symfony\Component\Process\Process` y un arreglo de argumentos: **no hay shell, así que no hay inyección**.
- Usa `BatchMode`, `StrictHostKeyChecking=yes`, sin contraseña ni TTY, con un tiempo máximo de 20 s y la respuesta limitada a 64 KB en JSON.
- **Ningún secreto en la base de datos**: el usuario, la llave y `known_hosts` van en `settings.php` (`$settings['ai_adminops_ssh'][<referencia>]`), y el servidor solo guarda la referencia.
- El lado remoto exige un `forced-command` `adminops-read <tool>` y una cuenta sin privilegios (ver el documento de SSH).
- Permisos: `access ai adminops dashboard` para ver y `administer ai adminops` para configurar o cambiar estados.

## Defectos encontrados el 2026-10-09 (sin corregir)

1. ~~**La bitácora marca "Succeeded" lecturas que no dieron datos**~~ **Corregido el 2026-10-09 (paso 1).** Ahora salen como "No data" en ámbar. La actualización 10005 corrigió también la etiqueta de las lecturas pasadas (6,832 en la copia local: todas las de Exim y SSL).
1b. ~~**Ninguna etiqueta de estado tenía color**~~ **Corregido el 2026-10-09.** `AdminOpsConsoleController::badge()` imprimía las clases `--status` y `--failed` por separado, y el CSS espera `--status-failed`, así que «Failed», «Critical» y «Healthy» salían en el mismo gris. Se añadió la clase combinada.
2. **No hay ninguna pantalla con los valores medidos**: más de 20,000 lecturas solo se ven en crudo en la bitácora. El tablero muestra conteos, no cómo está el servidor.
3. **"Servidores 1 · 0 activos"** en el tablero: "activos" se refiere al monitoreo, no al servidor, y se lee como un error.
4. **Sin limpieza de la bitácora**: unas 4,000 filas por semana (~11 MB cada 5 semanas en la copia), sin límite.
5. **Idioma**: mezcla de español e inglés y **faltan acentos** en todos los textos en español ("ADMINISTRACION", "auditoria", "Bitacora", "Configuracion", "revision").
6. Los valores decimales se guardan con ruido de coma flotante (`1.0900000000000000799…`).

## Datos para calibrar las alertas (2026-10-09)

- **El servidor de producción tiene 4 núcleos** (`nproc`). Una carga de 4 = los cuatro ocupados: el umbral de 4 es razonable.
- **De los 25 picos de carga por encima de 4** (2026-08-11 a 2026-09-16), **24 fueron una sola lectura** hacia las 02:00, seguramente los respaldos nocturnos, con la carga a 15 min todavía baja. A los 15 minutos ya había bajado.
- **Solo uno se sostuvo**: el 2026-09-03 a las 15:23 y 15:38, en pleno día (5.72 y 4.29, carga a 15 min en 3.7). Ese sí merecía aviso.
- Conclusión: exigir **dos lecturas seguidas** por encima del umbral filtra el 100 % del ruido de los respaldos y conserva el episodio real.
- La actualización `ai_adminops_update_10003` dejaba las alertas **encendidas** por defecto. En producción están apagadas. **José las apagó porque el módulo no estaba terminado: iba a conectar un número de WhatsApp para recibir los avisos** (respuesta del 2026-10-09). No fue por ruido.

## Registro de Drupal saturado (por verificar en producción)

Cada lectura escribe un aviso en el registro de Drupal (`AdminOps execution … finished`): unas 576 líneas al día. El registro (`dblog`) guarda **solo las últimas 1,000 filas** (`row_limit`), así que AdminOps **puede estar empujando fuera los errores reales del sitio**. En la copia local, `ai_adminops` es el tercer tipo más frecuente (340 de 2,293 filas). Falta confirmarlo en producción.

## Plan aprobado el 2026-10-09 — pasos 1 a 3 DESPLEGADOS

Desplegados en producción el 2026-10-09 (`2b9c8f4`):
- Corrieron las actualizaciones 10004 a 10006, con las definiciones al día.
- `consecutive_readings` quedó en 2.
- **11,194 lecturas pasadas de Exim y SSL reetiquetadas como `no_data`**; las otras cuatro lecturas siguen como `succeeded` (5,597 cada una).
- `ea-php84` = 2, login 200 y los ajustes devuelven 403 sin sesión.
- Respaldo previo en `~/backups/db-antes-adminops-2026-10-09.sql.gz`.

**Verificado en vivo el 2026-10-09 a las 23:29**: una ronda disparada a mano (`queueDueChecks(TRUE)` + `drush queue:run ai_adminops_monitoring`, solo lectura, igual que cron) escribió Exim y SSL como `no_data` y las otras cuatro como `succeeded`, en 3 s y sin errores. Totales: 5,598 cada una.

**Registro saturado, confirmado en producción el 2026-10-09:** de las 1,000 filas de `dblog`, 653 son de `cron` y 337 de `ai_adminops`. **Solo 11 son de otra cosa.** Un error real del sitio dura poco antes de que lo empujen fuera. Arreglo pendiente (siguiente mejora): bajar a debug o quitar los avisos rutinarios de cada lectura y de cada cron, o subir `row_limit`.

**Pendiente para el 2026-10-10 (José: "lo hacemos, pero mañana"):**
1. **Paso 4**: probar la entrega con el botón y encender las alertas. José quería recibirlas **por WhatsApp**; el canal de WhatsApp está construido (cuenta activa de `ai_whatsapp_account` + destinatarios + plantilla de Twilio opcional) pero nunca se ha probado. Correo también disponible.
2. **Registro saturado** (`dblog`): la siguiente mejora recomendada.
3. Después, sin prioridad decidida: tablero con valores medidos, limpieza de la bitácora, Exim y SSL en el script remoto, acentos e idioma.

**El paso 4 (encender) lo hace José desde los ajustes**, después de comprobar con el botón de prueba que el correo le llega.

José aprobó estos cuatro pasos, sin tocar `ai_whatsapp_automation`:

1. ✅ **Hecho** — **Bitácora honesta**: un estado nuevo `no_data` («No data», en ámbar) cuando una lectura llega por SSH pero no trae valores (`unavailable`, `not_configured`), en lugar de «Succeeded». Requiere actualizar el campo `status` de `ai_adminops_tool_execution`.
2. ✅ **Hecho** — **Alertas sin ruido**: un valor alto solo abre evento si **las últimas N lecturas** de ese indicador lo superan (`alerts.consecutive_readings`, 2 por defecto), leídas de la propia bitácora. «Servidor sin respuesta» exige también N trabajos seguidos sin respuesta (contador en State). La resolución sigue siendo inmediata en cuanto el valor baja.
3. ✅ **Hecho** — **Botón «enviar aviso de prueba»** en los ajustes, para comprobar de punta a punta el correo y el WhatsApp con los valores del formulario.
4. **Encenderlo**: alertas, aviso de servidor sin respuesta y correo a José. Lo hace José desde los ajustes después del despliegue, verificándolo con el botón de prueba.

Después, sin fecha: un tablero con los valores medidos, limpieza automática de la bitácora, bajar el nivel de esos avisos del registro, arreglar Exim y SSL en el script remoto del servidor (no en Drupal), y corregir acentos e idioma. La IA y las acciones (reinicios) quedan a decisión de José.

## Arquitectura

### El flujo, de cron al aviso

```
cron ── ai_adminops_cron()
        └▶ MonitoringScheduler::queueDueChecks()
             ¿monitoring.enabled? ¿pasaron interval_minutes desde la última vez? (State)
             └▶ un elemento por servidor activo en la cola «ai_adminops_monitoring»

cola (cron, 45 s de trabajo) ── MonitoringQueueWorker
        └▶ MonitoringJobProcessor::process()
             ¿el servidor tiene perfil SSH en settings.php? si no: se anota y no se conecta
             por cada lectura (las seis fijas):
               AdminOpsExecutionAudit::begin() ─ registro en la bitácora ANTES de conectar
               MonitoringCollectorInterface::collect() ─ ReadOnlySshConnector (ssh, sin shell)
               ├ trae valores ("ok")   → markSucceeded() → MonitoringAlertEvaluator::evaluate()
               ├ responde sin valores  → markNoData()   (desde 2026-10-09)
               └ falla                 → markFailed()
             si ninguna lectura respondió → evento «servidor sin respuesta»; si alguna, se resuelve

MonitoringAlertEvaluator ── compara con alerts.* y llama a AdminOpsEventManager
        ├ valor ≥ umbral en las últimas alerts.consecutive_readings lecturas (2 por defecto,
        │   leídas de la bitácora con AdminOpsExecutionAudit::recentReadings(); una lectura
        │   sin datos o fallida rompe la racha)
        │   → record():  UN evento abierto por servidor y tipo (huella sha256);
        │                si ya hay uno, lo actualiza y NO vuelve a avisar
        ├ valor ≥ umbral pero sin racha suficiente → nada (un evento abierto sigue abierto)
        └ valor < umbral → resolveCondition(): lo cierra en la primera lectura normal
        «Servidor sin respuesta»: también exige N trabajos seguidos sin respuesta
        (contador en State ai_adminops.monitoring.unreachable_streak.<servidor>,
        que se borra en cuanto el servidor responde).

AdminOpsEventManager::record() ── solo al CREAR un evento
        └▶ NotificationBridge::notifyEvent()
             ¿notifications.enabled? ¿severidad ≥ minimum_severity? ¿fuera del enfriamiento? (State)
             ├ correo: plugin.manager.mail (SES del sitio)
             └ WhatsApp: ai_whatsapp_automation.provider_message_sender (con plantilla de Twilio)
             Si el aviso falla, el evento queda guardado igual y se registra el error.
```

### Entidades

| Entidad | Tipo | Para qué |
|---|---|---|
| `ai_adminops_server` | Configuración | Un servidor: nombre, hostname, puerto, proveedor, tipo de conexión, **referencia** al perfil SSH de `settings.php`, activo y estado. Nunca guarda secretos. |
| `ai_adminops_tool_execution` | Contenido | **La bitácora**: una fila por lectura, con herramienta, estado (`queued`, `running`, `succeeded`, `no_data`, `failed`, `denied`), riesgo, resultado saneado y tiempos. Crece unas 4,000 filas por semana. |
| `ai_adminops_event` | Contenido | Un problema detectado: tipo, severidad (`info`, `warning`, `critical`), estado (`open`, `acknowledged`, `resolved`), evidencia y huella para no duplicarlo. |
| `ai_adminops_action_request` | Contenido | Una petición de acción controlada o crítica, que alguien aprueba o rechaza. **Aprobarla no ejecuta nada.** |

Todas apuntan al servidor por su id. Los datos que salen de un servidor pasan por `PayloadSanitizer` antes de guardarse.

### Herramientas

Plugins con el atributo `#[AdminOpsTool(id, label, description, risk, parameters, permissions)]`, en `src/Plugin/AdminOpsTool/` y gestionados por `AdminOpsToolManager`. `AdminOpsToolPolicy` comprueba los permisos declarados y decide si el riesgo (`controlled`, `critical`) exige aprobación. **Hoy ninguna ejecuta nada**: el monitoreo usa directamente el conector, y el catálogo está listo para cuando se implementen.

### Relación con `ai_whatsapp_automation`

Depende de él, pero **no lo modifica**, por regla de la especificación. Consume `provider_message_sender`, para enviar WhatsApp, y `OpenAIServiceInterface`, a través de `AdminOpsAiClient` (sin uso). Desde el 2026-10-11 además **le ofrece herramientas a Sentinel** (abajo). Para documentar ese módulo, ver [ARCHITECTURE.md](ARCHITECTURE.md).

### Herramientas de Sentinel (fase A, 2026-10-11)

`SentinelServerTools` implementa `OwnerToolProviderInterface` de `ai_whatsapp_automation` y se registra con la etiqueta `ai_whatsapp_automation.owner_tool_provider`. **Quién** puede usarlas lo decide `OwnerToolRunner` en el otro módulo (tres candados: interruptor «Owner tools» del bot, bot del dueño de la línea, número del dueño). Todas son de **solo lectura**:

| Herramienta | Qué da | ¿Se conecta al servidor? |
|---|---|---|
| `servidores` | Servidores activos: id, nombre, hostname, sistema, proveedor, estado, última lectura. **Nunca** `credential_reference`. | No |
| `estado_servidor` | Última lectura programada (lo que guardó `MonitoringJobProcessor` en *state*) y los umbrales de alerta. | No |
| `eventos_abiertos` | Hasta 10 eventos `open` o `acknowledged`, los más recientes primero. | No |
| `historial` | Últimas lecturas de una revisión (máx. 12) desde la bitácora. | No |
| `leer_ahora` | Las seis revisiones fijas por `ReadOnlySshConnector`. | Sí, **una vez por minuto como máximo** por servidor |

- El servidor es un `enum` con los servidores **activos**: el modelo no puede nombrar otro, y uno inactivo se rechaza. Sin servidores activos no se manda el `enum` vacío, que OpenAI rechaza junto con toda la petición.
- Cada lectura de `leer_ahora` queda en la bitácora (`ai_adminops_tool_execution`) con `{"source":"sentinel","conversation":"<id>"}` en los parámetros.
- **Una lectura en vivo no cuenta para las alertas**: no pasa por `MonitoringAlertEvaluator` ni toca la lectura programada guardada. Si contara, preguntarle dos veces a Sentinel podría abrir o cerrar un evento.
- Las horas salen en la zona del sitio, con `hace_minutos` para que Sentinel diga de cuándo es el dato.
- Pruebas: `tests/src/Kernel/SentinelServerToolsTest.php` (8). Se rompió a propósito cada salvaguarda (límite por minuto, servidores inactivos, fuga de la referencia de credencial, `enum` vacío, tope del historial, eventos resueltos, parámetros de auditoría) y cada una hizo fallar su prueba.

### Consumo por cuenta de cPanel (fase C1, 2026-10-13)

José, como dueño del WHM, pidió saber qué cuenta consume qué. El servidor es AlmaLinux 9.8 **sin CloudLinux**, así que no hay historial de CPU por cuenta: hay lista de cuentas y correo por cuenta (API de WHM) y procesos del momento.

- Herramienta de Sentinel **`cuentas(servidor, orden?, cuenta?)`** en `SentinelServerTools`. Hace tres revisiones nuevas, en la bitácora como las demás, **a lo sumo una vez por minuto** (otra orden o cuenta dentro del minuto se contesta con las mismas lecturas):
  - `get_accounts`: `whmapi1 listaccts` (disco, cuota, suspendida);
  - `get_mail_by_account`: `whmapi1 emailtrack_user_stats` de la última hora y del día;
  - `get_account_processes`: CPU, memoria y procesos por usuario, de `ps`.
- `AccountUsageReport` (sin E/S, probado aparte) une las tres en una fila por cuenta, ordena (disco, correo, correo_fallido, cpu, memoria) y devuelve **máximo 15** con cuántas quedaron fuera. Lo que no es cuenta de cPanel (root, `-remote-`, mysql, nobody) va aparte en «sistema». Una lectura fallida se nombra en `lecturas_sin_dato`.
- **Decisión de José:** a OpenAI van el **usuario y el dominio** de cada cuenta; nunca contraseñas ni contenido de correos.
- Servidor: solo root puede consultar WHM. El monitoreo **no** tiene permiso para WHM en general: un script de root, `/usr/local/sbin/adminops-whm-read` (`scripts/server/`), hace solo `accounts` o `mail`, y `sudoers` permite solo esas dos llamadas.
- Pruebas: `tests/src/Kernel/SentinelAccountsTest.php` (7), con datos con la forma de los de producción. Se rompió a propósito cada parte (dominio, sistema separado, orden, tope de 15, lecturas faltantes, conversión de GB, límite por minuto) y cada una hizo fallar su prueba.
### Historial por cuenta (fase C2a, 2026-10-13)

- **Cada lectura programada** (15 min) lee además las tres revisiones por cuenta (`MonitoringScheduler` marca el trabajo con `accounts => TRUE`; los trabajos encolados antes no las leen). Lo hace `AccountUsageCollector`, después de las revisiones del servidor: **un fallo aquí nunca cuesta las revisiones del servidor**.
- Se guarda **una fila por cuenta y lectura** en la tabla `ai_adminops_account_usage` (update 10007): disco y cuota en MB exactos, correos de la hora anterior (enviados, fallidos, diferidos, límite alcanzado), CPU, memoria y procesos. **30 días**; lo más viejo se borra en cada lectura (`AccountUsageHistory::RETENTION_DAYS`). Del orden de 40 cuentas × 96 lecturas al día.
- La bitácora de AdminOps registra cada lectura por cuenta con un **resumen**, no la respuesta completa de WHM (unos 30 KB).
- Sentinel: **`historial_cuenta(servidor, horas, cuenta?, orden?)`**. Con una cuenta: CPU máxima y promedio, lecturas con CPU ≥ 50 %, memoria máxima, máximo de correos y fallidos en una hora, promedio por hora, disco al inicio y al final, y la hora de los picos. Sin cuenta: las 10 que más consumieron. Como cada fila cuenta la hora anterior y las lecturas se enciman, **se dan picos y promedios por hora, nunca sumas**.
- Pruebas: `tests/src/Kernel/AccountUsageHistoryTest.php` (10). Al escribirlas se encontraron dos defectos: el orden no ordenaba (claves distintas) y el disco se guardaba a partir de los GB redondeados. Cada protección se rompió a propósito y su prueba falló, incluida una que solo se pudo probar haciendo fallar la base de datos.
- **C2b (pendiente):** alertas por cuenta (correo por hora, fallidos, cuota de disco, CPU sostenida), con umbrales sacados de este historial tras unos días.

### Acciones de Sentinel con código por correo (fase B, 2026-10-11)

Decisión de José: **el código llega por correo**, nunca al WhatsApp que lo pide, y en la primera versión solo hay **dos acciones**: reinicio *graceful* de Apache y reinicio de PHP-FPM (MySQL queda fuera a propósito). Nunca hay comandos libres.

- `SentinelActionTools` (proveedor) ofrece `solicitar_accion(servidor, accion, motivo)` y `confirmar_accion(solicitud, codigo)`. **Solo aparecen** en servidores donde están configuradas las dos cosas de `settings.php` (abajo); si falta cualquiera, Sentinel ni siquiera sabe que existen.
- `SentinelActionApproval` hace el flujo:
  1. `solicitar_accion` crea una **solicitud de acción** (la entidad que ya existía, riesgo `critical`, vence en **10 min**) y un código de 6 dígitos que se manda **solo por correo** a `ai_adminops_sentinel_approval_email`. El código se guarda como HMAC con la sal del sitio y **nunca** se le devuelve al modelo. Si el correo falla, la solicitud se cancela.
  2. José escribe el código en WhatsApp y Sentinel llama a `confirmar_accion`. Solo la conversación que pidió puede confirmar; **3 códigos incorrectos** rechazan la solicitud; un código vencido no sirve; el correcto se usa **una vez**, aprueba la solicitud (aprobada por el usuario con ese correo) y ejecuta la acción, registrada en la bitácora con riesgo `critical` y la solicitud.
  3. Una solicitud nueva reemplaza la pendiente de esa conversación. Máximo **5 códigos por hora** por conversación.
- `ActionSshConnector` ejecuta por **otra llave SSH y otra cuenta remota** (no la del monitoreo): `adminops-act <accion>`, con un `forced-command` y un `sudoers` que solo permiten esos dos scripts de cPanel. Ver [AI_ADMINOPS_ACTIONS_SSH.md](AI_ADMINOPS_ACTIONS_SSH.md).
- **El correo y el perfil viven en `settings.php` a propósito**: quien entre al panel no puede mandarse los códigos a otro lado ni activar acciones.
- Pruebas: `tests/src/Kernel/SentinelActionApprovalTest.php` (11). Se rompió a propósito cada salvaguarda (cualquier código, intentos ilimitados, otra conversación, sin vencimiento, código filtrado al modelo, sin límite por hora, sin reemplazo, sin correo configurado, correo fallido ignorado) y cada una hizo fallar su prueba. Borrar el código tras usarlo es redundante: la solicitud deja de estar pendiente y el código ya no sirve, y la prueba lo comprueba.

**Reinicio completo del servidor (pregunta de José, 2026-10-11): no es una acción**, a propósito. Sentinel vive en ese servidor: se apagaría con él y no podría confirmar que regresó, y si no levanta solo se arregla desde la consola del proveedor. Propuesta: una lectura «¿pide reinicio?» (kernel nuevo instalado) para que Sentinel avise y José reinicie desde WHM a la hora que elija.

### Permisos

- `access ai adminops dashboard`: ver el tablero, los eventos, las solicitudes y la bitácora.
- `administer ai adminops`: ajustes, servidores, y cambiar el estado de eventos y solicitudes.

Solo los usa el administrador. Los agentes de los clientes de AI WhatsApp no tienen ninguno.

### Aviso de prueba

`NotificationBridge::sendTest()` envía un mensaje `[AdminOps][TEST]` por los mismos `sendEmail()` y `sendWhatsApp()` que las alertas reales, con los valores que estén escritos en el formulario (guardados o no). Ignora a propósito el interruptor general, la severidad mínima y el enfriamiento: sirve para comprobar la entrega **antes** de encender las alertas. El botón está al final de la sección de notificaciones de los ajustes, no guarda el formulario y muestra el resultado por destinatario.

**Lección (vale para cualquier formulario de Drupal):** los servicios inyectados en un formulario **no pueden ser `private readonly`** si el formulario se reconstruye (un botón con `#submit` propio, AJAX). Drupal guarda el formulario en caché y restaura sus servicios con `DependencySerializationTrait`, que vive en una clase padre y no puede inicializar una propiedad `readonly` declarada en la hija. El primer clic funciona y el segundo falla con *must not be accessed before initialization*. Por eso se usan propiedades `protected` normales, como en el core. `NotificationTestSendTest::testButtonWorksOnARestoredForm` lo cubre.

### Pruebas

Desde el 2026-10-09 (antes no había ninguna):

```
ddev exec "SIMPLETEST_DB=mysql://db:db@db/db vendor/bin/phpunit -c web/core web/modules/custom/ai_adminops/tests"
```

## Estructura

```
ai_adminops/
  src/Entity/            AdminOpsServer (config), AdminOpsEvent, AdminOpsToolExecution,
                         AdminOpsActionRequest (contenido)
  src/Plugin/AdminOpsTool/  Catálogo de herramientas (atributo #[AdminOpsTool], riesgo:
                         read_only / controlled / critical)
  src/Plugin/QueueWorker/   MonitoringQueueWorker
  src/Service/           MonitoringScheduler (cron → cola), MonitoringJobProcessor (lee y
                         evalúa), MonitoringCollectorInterface + ReadOnlySshConnector, MonitoringAlertEvaluator,
                         AdminOpsEventManager, NotificationBridge, AdminOpsExecutionAudit,
                         AdminOpsToolPolicy, AdminOpsActionRequestManager, PayloadSanitizer,
                         AdminOpsAiClient (sin uso)
  src/Controller/        AdminOpsDashboardController (tablero), AdminOpsConsoleController
                         (servidores, eventos, solicitudes, bitácora)
  src/Form/              Ajustes, servidor, cambio de estado de eventos y solicitudes
```

Rutas: `/admin/reports/ai-adminops` (tablero, eventos, solicitudes y bitácora) y `/admin/config/system/ai-adminops` (ajustes y servidores).

Configuración por defecto (`ai_adminops.settings`): el monitoreo viene apagado y, una vez activado, corre cada 15 min. Umbrales: carga 4, CPU 90 %, memoria 90 %, disco 85 %, Exim 100. Las alertas vienen encendidas al instalar y las notificaciones por correo también; WhatsApp viene apagado.

## Historia

Construido el 2026-08-10 y 11 en 20 commits, siguiendo la especificación por fases. Las fases 1 a 9 cubren la estructura, las entidades, los servicios, las herramientas (solo el catálogo), el monitoreo y las notificaciones. Lo que falta respecto de la especificación es el agente con IA; las integraciones con WHM, cPanel, CloudLinux, JetBackup y AutoSSL; las herramientas de cuentas, cuotas e inodos; y la ejecución real de cualquier herramienta.
