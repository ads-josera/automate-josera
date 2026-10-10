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
| Alertas (`MonitoringAlertEvaluator`) | El código funciona, pero **en la copia local están apagadas** | Reproducido en local el 2026-10-09: con carga 21 contra umbral 4 abre un evento, no lo duplica y lo resuelve al bajar la carga. En los datos, la carga pasó de 4 **25 veces** (siempre hacia las 02:00, probablemente los respaldos) y **no hay ningún evento**. Falta confirmar si en producción `alerts.enabled` está en falso o si la copia se limpió al importarla. |
| Notificaciones (`NotificationBridge`) | Código presente; apagadas en la copia | Reutiliza el envío de WhatsApp de `ai_whatsapp_automation` y el correo del sitio (SES). No probadas de punta a punta. |
| Solicitudes de acción con aprobación | Funciona solo como registro | Aprobar no ejecuta nada (lo dice la pantalla). |
| Herramientas (9 plugins `AdminOpsTool`) | **Catálogo sin ejecución** | `execute()` lanza "no connector enabled yet" en las nueve, incluidas las de lectura. El monitoreo no pasa por ellas: llama directo al conector. Los reinicios (Apache, MySQL, PHP-FPM) no pueden hacer nada, lo cual hoy es lo seguro. |
| **IA** (`AdminOpsAiClient`) | **No se usa** | Registrado como servicio; nadie lo llama. El propósito central de la especificación (un agente que ayude mediante IA) no está implementado. |
| Pruebas automáticas | **No hay** | Ni una. |

## Seguridad (revisada, está bien)

- El conector acepta **solo seis lecturas fijas**, sin argumentos.
- Ejecuta `ssh` con `Symfony\Component\Process\Process` y un arreglo de argumentos: **no hay shell, así que no hay inyección**.
- Usa `BatchMode`, `StrictHostKeyChecking=yes`, sin contraseña ni TTY, con un tiempo máximo de 20 s y la respuesta limitada a 64 KB en JSON.
- **Ningún secreto en la base de datos**: el usuario, la llave y `known_hosts` van en `settings.php` (`$settings['ai_adminops_ssh'][<referencia>]`), y el servidor solo guarda la referencia.
- El lado remoto exige un `forced-command` `adminops-read <tool>` y una cuenta sin privilegios (ver el documento de SSH).
- Permisos: `access ai adminops dashboard` para ver y `administer ai adminops` para configurar o cambiar estados.

## Defectos encontrados el 2026-10-09 (sin corregir)

1. **La bitácora marca "Succeeded" lecturas que no dieron datos** (Exim `unavailable`, SSL `not_configured`): la pantalla dice que todo va bien cuando dos de seis indicadores no funcionan.
2. **No hay ninguna pantalla con los valores medidos**: más de 20,000 lecturas solo se ven en crudo en la bitácora. El tablero muestra conteos, no cómo está el servidor.
3. **"Servidores 1 · 0 activos"** en el tablero: "activos" se refiere al monitoreo, no al servidor, y se lee como un error.
4. **Sin limpieza de la bitácora**: unas 4,000 filas por semana (~11 MB cada 5 semanas en la copia), sin límite.
5. **Idioma**: mezcla de español e inglés y **faltan acentos** en todos los textos en español ("ADMINISTRACION", "auditoria", "Bitacora", "Configuracion", "revision").
6. Los valores decimales se guardan con ruido de coma flotante (`1.0900000000000000799…`).

## Estructura

```
ai_adminops/
  src/Entity/            AdminOpsServer (config), AdminOpsEvent, AdminOpsToolExecution,
                         AdminOpsActionRequest (contenido)
  src/Plugin/AdminOpsTool/  Catálogo de herramientas (atributo #[AdminOpsTool], riesgo:
                         read_only / controlled / critical)
  src/Plugin/QueueWorker/   MonitoringQueueWorker
  src/Service/           MonitoringScheduler (cron → cola), MonitoringJobProcessor (lee y
                         evalúa), ReadOnlySshConnector, MonitoringAlertEvaluator,
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
