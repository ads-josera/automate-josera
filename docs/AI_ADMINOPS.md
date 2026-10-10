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
- La actualización `ai_adminops_update_10003` dejaba las alertas **encendidas** por defecto. En producción están apagadas: **alguien las apagó a propósito**. Hay que preguntarle a José por qué antes de volver a encenderlas.

## Registro de Drupal saturado (por verificar en producción)

Cada lectura escribe un aviso en el registro de Drupal (`AdminOps execution … finished`): unas 576 líneas al día. El registro (`dblog`) guarda **solo las últimas 1,000 filas** (`row_limit`), así que AdminOps **puede estar empujando fuera los errores reales del sitio**. En la copia local, `ai_adminops` es el tercer tipo más frecuente (340 de 2,293 filas). Falta confirmarlo en producción.

## Plan aprobado el 2026-10-09 (en curso)

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

Depende de él, pero **no lo modifica**, por regla de la especificación. Consume solo dos servicios: `provider_message_sender`, para enviar WhatsApp, y `OpenAIServiceInterface`, a través de `AdminOpsAiClient` (sin uso). Para documentar ese módulo, ver [ARCHITECTURE.md](ARCHITECTURE.md).

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
