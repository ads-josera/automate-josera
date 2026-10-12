Quiero desarrollar un nuevo módulo para Drupal 11 llamado:

ai_adminops

IMPORTANTE

Antes de escribir cualquier línea de código debes entender las siguientes reglas.

# REGLA PRINCIPAL

Existe un módulo completamente funcional llamado:

ai_whatsapp

Este módulo YA ESTÁ TERMINADO y probado.

NO debes modificar absolutamente nada dentro de ese módulo.

No debes cambiar clases.

No debes mover archivos.

No debes cambiar namespaces.

No debes modificar rutas.

No debes modificar entidades.

No debes modificar plugins.

No debes modificar servicios.

No debes modificar el sistema de Bots.

No debes modificar el Routing.

No debes modificar el Prompt Engine.

No debes modificar Function Calling.

No debes modificar el sistema RAG.

No debes modificar el historial.

No debes modificar la memoria.

No debes modificar la integración con OpenAI.

No debes modificar la administración existente.

No debes realizar refactors.

No debes sugerir cambios sobre el Core.

El objetivo es reutilizar todo lo existente.

Si necesitas alguna funcionalidad existente, debes consumirla utilizando los servicios públicos del módulo ai_whatsapp.

Nunca duplicar código.

Nunca copiar clases.

Nunca modificar el Core.

-------------------------------------------------------

OBJETIVO

Crear un nuevo módulo independiente llamado:

ai_adminops

Este módulo dependerá de:

ai_whatsapp

pero todo su código será completamente independiente.

-------------------------------------------------------

PROPÓSITO

El módulo implementará un nuevo Agente especializado en administración de infraestructura.

Será capaz de ayudar al administrador mediante IA utilizando las capacidades existentes del módulo ai_whatsapp.

-------------------------------------------------------

EL MÓDULO DEBERÁ IMPLEMENTAR

• Administración de servidores

• Monitoreo

• Eventos

• Alertas

• Integración con WHM

• Integración con cPanel

• Integración con CloudLinux

• Integración con SSH

• Integración con Exim

• Integración con Apache

• Integración con MySQL

• Integración con PHP-FPM

• Integración con JetBackup

• Integración con SSL

• Integración con AutoSSL

Todo utilizando herramientas propias del módulo.

-------------------------------------------------------

NO IMPLEMENTAR CHAT

El chat ya existe.

Las conversaciones ya existen.

El Prompt Engine ya existe.

El Routing ya existe.

El sistema MultiBot ya existe.

La integración con IA ya existe.

El módulo únicamente agregará nuevas capacidades.

-------------------------------------------------------

ARQUITECTURA

El módulo deberá seguir completamente las mejores prácticas de Drupal 11.

Utilizar:

Dependency Injection

Services

Plugins

Configuration API

Event Subscribers

Cron

Queue API

Logger

Permissions

Routing

Forms

Controllers

State API

Cache API

PSR-4

Nunca utilizar código procedural.

-------------------------------------------------------

TOOLS

Crear un sistema propio de herramientas para AdminOps.

Todas las herramientas vivirán únicamente dentro de este módulo.

Ejemplos:

GetServerLoadTool

GetCpuUsageTool

GetMemoryUsageTool

GetDiskUsageTool

GetInodesTool

GetTopProcessesTool

GetWhmAccountsTool

GetAccountQuotaTool

GetCloudLinuxUsageTool

GetEximQueueTool

GetApacheStatusTool

GetMysqlStatusTool

GetPhpFpmStatusTool

RestartApacheTool

RestartMysqlTool

RestartPhpFpmTool

GetSslStatusTool

GetBackupStatusTool

RunWhmApiTool

RunCpanelApiTool

RunSshCommandTool

Cada Tool deberá implementar una interfaz común.

Cada Tool tendrá:

- id

- nombre

- descripción

- parámetros

- validación

- permisos

- nivel de riesgo

- execute()

-------------------------------------------------------

SERVIDORES

Crear una entidad de configuración para registrar múltiples servidores.

Cada servidor tendrá:

Nombre

Hostname

Puerto

Sistema Operativo

Proveedor

SSH

WHM

cPanel

Credenciales

Estado

Descripción

Activo

Etiquetas

Nunca asumir un único servidor.

-------------------------------------------------------

MONITOREO

Crear un Scheduler utilizando Cron y Queue API.

Debe permitir ejecutar verificaciones periódicas.

Ejemplos:

CPU

RAM

Disco

Inodos

Apache

MySQL

PHP

Exim

SSL

Backups

CloudLinux

Servicios Linux

-------------------------------------------------------

EVENTOS

Crear un sistema de eventos.

Ejemplos:

CPU alta

RAM alta

Disco lleno

Cuenta cPanel sin espacio

Exim Queue alta

SSL próximo a vencer

Backup fallido

Apache detenido

MySQL detenido

PHP-FPM detenido

Intentos masivos de autenticación

Cada evento deberá almacenarse.

-------------------------------------------------------

NOTIFICACIONES

El módulo deberá utilizar la infraestructura existente del módulo ai_whatsapp para enviar mensajes.

No crear un nuevo sistema de mensajería.

Simplemente consumir el existente.

Las notificaciones podrán enviarse por:

WhatsApp

Correo

Canales futuros

-------------------------------------------------------

SEGURIDAD

Nunca ejecutar comandos arbitrarios enviados por el modelo.

Toda acción deberá implementarse mediante una Tool.

Las acciones críticas deberán requerir confirmación.

Ejemplos:

Reiniciar Apache

Reiniciar MySQL

Reiniciar PHP

Suspender cuentas

Eliminar archivos

Modificar configuraciones

Nunca permitir ejecución libre de comandos Shell.

-------------------------------------------------------

DESARROLLO

Antes de generar código:

Analiza el módulo ai_whatsapp.

Identifica los servicios públicos reutilizables.

Propón la arquitectura del nuevo módulo.

Después genera el código respetando completamente la arquitectura existente.

-------------------------------------------------------

OBJETIVO FINAL

Construir un módulo completamente desacoplado que amplíe las capacidades de la plataforma existente sin modificar una sola línea del módulo ai_whatsapp.

Todo el código nuevo deberá vivir exclusivamente dentro del módulo ai_adminops.

La plataforma existente debe seguir funcionando exactamente igual después de instalar el nuevo módulo.

-------------------------------------------------------

IMPORTANTE

Trabaja por fases.

Fase 1:
Analiza la arquitectura existente.

Fase 2:
Diseña la arquitectura del nuevo módulo.

Fase 3:
Presenta el árbol de directorios.

Fase 4:
Implementa la estructura base del módulo.

Fase 5:
Implementa las entidades.

Fase 6:
Implementa los servicios.

Fase 7:
Implementa las Tools.

Fase 8:
Implementa el monitoreo.

Fase 9:
Implementa las notificaciones.

No avances a la siguiente fase hasta que la fase actual haya sido revisada y aprobada.
