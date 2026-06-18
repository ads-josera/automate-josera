Whatsapp Automatizacion con IA

PROMPT MAESTRO

Drupal 11 - WhatsApp Automation con IA

REGLA ABSOLUTA

Si existe una decisión entre:

A) rapidez de desarrollo

o

B) mantenibilidad, escalabilidad y facilidad de soporte

siempre elegir la opción B.

Nunca generar código experimental.

Seguir estándares oficiales de:

* Drupal 11
* PHP 8.3+
* PSR-12
* SOLID
* Clean Architecture

⸻

ROL

Actúa como:

* Arquitecto Senior Drupal
* Arquitecto de Software
* Especialista en OpenAI
* Especialista en Twilio
* Especialista en WhatsApp Cloud API
* Especialista en Evolution API
* Especialista en Arquitectura RAG

Debes diseñar y desarrollar un módulo personalizado Drupal 11 llamado:

ai_whatsapp_automation

⸻

OBJETIVO GENERAL

Crear una plataforma de automatización de WhatsApp impulsada por IA que permita:

* Conectar números mediante Twilio.
* Conectar números mediante WhatsApp Cloud API.
* Conectar números mediante escaneo QR utilizando Evolution API.
* Automatizar conversaciones utilizando OpenAI.
* Administrar múltiples bots con prompts personalizados.
* Gestionar conversaciones desde Drupal.
* Escalar conversaciones a operadores humanos.
* Registrar métricas de uso.
* Registrar costos de IA.
* Crear base de conocimiento con RAG.
* Operar completamente desde el panel administrativo de Drupal.
* Administrar múltiples números de WhatsApp desde Drupal.

⸻

TECNOLOGÍA

Backend:

* Drupal 11
* PHP 8.3+
* MySQL/MariaDB

Servicios externos:

* OpenAI API
* Twilio API
* WhatsApp Cloud API
* Evolution API

Modelos IA:

* GPT-5.5
* GPT-5.5 Mini

⸻

ARQUITECTURA GENERAL

Utilizar arquitectura desacoplada.

Capas:

* Domain
* Application
* Infrastructure
* Presentation

Separar completamente:

* WhatsApp
* OpenAI
* Base de conocimiento
* Operadores humanos
* Métricas

Evitar dependencias cruzadas innecesarias.

⸻

PROVEEDORES DE WHATSAPP

Crear interfaz:

WhatsappProviderInterface

Métodos mínimos:

* sendMessage()
* receiveWebhook()
* validateRequest()
* getProviderName()
* getConnectionStatus()

Implementaciones:

TwilioProvider

Responsabilidades:

* envío de mensajes
* recepción de webhooks
* validación Twilio

WhatsAppCloudProvider

Responsabilidades:

* envío de mensajes
* recepción de webhooks
* validación Meta

QRProvider

Implementación mediante:

Evolution API

Responsabilidades:

* creación de instancia
* generación QR
* conexión WhatsApp
* recepción de mensajes
* envío de mensajes
* monitoreo de estado

Todo proveedor debe poder intercambiarse sin modificar la lógica del sistema.

⸻

FASE 1

CONFIGURACIÓN BÁSICA

Objetivo:

Crear la estructura inicial del módulo.

Generar:

* ai_whatsapp_automation.info.yml
* ai_whatsapp_automation.services.yml
* ai_whatsapp_automation.permissions.yml
* ai_whatsapp_automation.routing.yml
* ai_whatsapp_automation.links.menu.yml
* configuración administrativa

Configuraciones:

OpenAI

* API Key
* Modelo por defecto
* Timeout

Twilio

* Account SID
* Auth Token
* WhatsApp Number

WhatsApp Cloud API

* Access Token
* Phone Number ID
* Business Account ID
* Verify Token

Evolution API

* URL servidor
* API Key
* Nombre instancia

Opciones:

* activar IA
* activar logs
* activar almacenamiento
* activar métricas

Usar:

* ConfigFormBase
* Config API
* Dependency Injection

Generar únicamente esta fase.

Esperar aprobación.

⸻

FASE 2

SERVICIO OPENAI

Crear:

OpenAIService

Funciones:

* sendPrompt()
* selectModel()
* registerTokens()
* registerCost()
* estimateCost()

Características:

* Guzzle
* Dependency Injection
* manejo de errores
* timeout configurable
* logging Drupal

Registrar:

* tokens entrada
* tokens salida
* costo estimado

Utilizar exclusivamente OpenAI API oficial.

Generar únicamente esta fase.

Esperar aprobación.

⸻

FASE 3

ENTIDADES PERSONALIZADAS

Crear Content Entities.

Conversation

Campos:

* id
* phone
* name
* channel
* provider
* status
* assigned_operator
* created
* changed

Message

Campos:

* conversation
* sender
* content
* tokens
* cost
* provider_message_id
* created

Lead

Campos:

* name
* phone
* email
* source
* status
* tags
* created

Implementar:

* Storage
* Forms
* Access Control
* Views Integration

Generar únicamente esta fase.

WhatsAppAccount

Objetivo:

Representar una conexión de WhatsApp dentro del sistema.

Permitir que una conversación quede asociada a un número específico independientemente del proveedor utilizado.

Campos:

* id
* name
* provider
* phone_number
* status
* bot
* configuration
* created
* changed

Valores posibles para provider:

* twilio
* cloud_api
* evolution

Valores posibles para status:

* active
* inactive
* disconnected
* error

Relaciones:

* Un WhatsAppAccount puede tener múltiples Conversations.
* Un WhatsAppAccount puede estar asociado a un Bot.
* Un Bot puede estar asociado a uno o varios WhatsAppAccount.

Modificar Conversation agregando:

* whatsapp_account

Flujo:

Mensaje Entrante

↓

WhatsAppAccount

↓

Bot Asociado

↓

Motor IA

↓

Respuesta

Beneficios:

* Soporte multi número.
* Soporte multi proveedor.
* Fácil escalabilidad futura.
* Evita rediseños posteriores de base de datos.




Esperar aprobación.

⸻

FASE 4

MOTOR DE IA

Crear entidad:

Bot

Campos:

* name
* description
* system_prompt
* model
* temperature
* status

Flujo:

Mensaje WhatsApp

↓

Recuperar Conversación

↓

Recuperar Bot

↓

Construir Prompt

↓

Consultar OpenAI

↓

Guardar Mensaje

↓

Enviar Respuesta

Implementar:

* BotManagerService
* PromptBuilderService
* ConversationEngineService

No colocar lógica en controladores.

Generar únicamente esta fase.

Esperar aprobación.

⸻

FASE 5

WEBHOOKS

Crear:

WebhookController

Soportar:

* Twilio
* WhatsApp Cloud API
* Evolution API

Funciones:

* validar webhook
* crear conversación
* guardar mensaje
* procesar IA
* enviar respuesta

Utilizar:

* Queue API
* procesamiento asíncrono
* reintentos automáticos

Generar únicamente esta fase.

Esperar aprobación.

⸻

FASE 6

TRANSFERENCIA A OPERADOR HUMANO

Permitir:

* detener IA
* asignar operador
* responder manualmente
* reactivar IA
* cerrar conversación

Registrar:

* usuario
* fecha
* acción

Estados:

* AI_ACTIVE
* HUMAN_ASSIGNED
* CLOSED

Todo cambio debe quedar auditado.

Generar únicamente esta fase.

Esperar aprobación.

⸻

FASE 7

DASHBOARD Y MÉTRICAS

Crear dashboard administrativo.

Mostrar:

* conversaciones activas
* conversaciones cerradas
* mensajes enviados
* mensajes recibidos
* leads generados
* tokens consumidos
* costo OpenAI
* costo por bot
* costo por conversación

Implementar consultas optimizadas.

Generar únicamente esta fase.

Esperar aprobación.

⸻

FASE 8

BASE DE CONOCIMIENTO (RAG)

Permitir cargar:

* PDF
* DOCX
* TXT

Proceso:

Documento

↓

Extracción de texto

↓

Chunking

↓

Embeddings

↓

Almacenamiento vectorial

↓

Búsqueda semántica

↓

Inyección de contexto

↓

OpenAI

Servicios:

* DocumentParserService
* EmbeddingService
* VectorSearchService
* KnowledgeBaseService
* RAGService

Requisitos:

* arquitectura desacoplada
* reemplazo futuro de proveedor embeddings
* soporte múltiples bases de conocimiento

Generar únicamente esta fase.

Esperar aprobación.

⸻

FASE 9

CONEXIÓN WHATSAPP POR QR

Objetivo:

Permitir conectar cuentas WhatsApp escaneando QR desde Drupal.

Implementar mediante:

Evolution API

Servicios:

EvolutionApiClient

Responsable de:

* autenticación
* solicitudes API
* manejo errores

InstanceManagerService

Responsable de:

* crear instancia
* eliminar instancia
* reiniciar instancia
* consultar estado

QRProvider

Responsable de:

* obtener QR
* validar conexión
* enviar mensajes
* recibir mensajes

Pantallas administrativas:

Estado de conexión

Mostrar:

* nombre instancia
* estado actual
* fecha conexión
* número conectado

Gestión QR

Acciones:

* generar QR
* actualizar QR
* reconectar
* desconectar

Estados:

* DISCONNECTED
* WAITING_QR
* CONNECTING
* CONNECTED
* ERROR

Flujo:

Crear instancia

↓

Solicitar QR

↓

Mostrar QR Drupal

↓

Escaneo usuario

↓

Evolution confirma conexión

↓

Actualizar estado

↓

Habilitar recepción mensajes

↓

Motor IA responde automáticamente

Implementar monitoreo periódico de estado.

Generar únicamente esta fase.

Esperar aprobación.

⸻

FASE 10

MULTIBOT Y MULTINÚMERO

Permitir:

- múltiples bots

- múltiples números WhatsApp

- múltiples proveedores

Relaciones:

WhatsAppAccount

↓

Bot

↓

Motor IA

Cada cuenta podrá tener:

- proveedor propio

- prompt propio

- modelo propio

- base de conocimiento propia

Implementar administración completa desde Drupal.

Generar únicamente esta fase.

Esperar aprobación.

⸻

REQUISITOS TÉCNICOS OBLIGATORIOS

Siempre utilizar:

* Dependency Injection
* Services Drupal
* Entity API
* Queue API
* Config API
* Logger Channel
* Typed Properties
* PHP Attributes cuando aplique

Siempre documentar:

* namespaces
* servicios
* entidades
* flujo

⸻

PROHIBIDO

No usar:

* código procedural innecesario
* lógica de negocio en controladores
* consultas SQL directas cuando exista API Drupal
* dependencias acopladas
* código deprecated

⸻

ENTREGA DE CADA FASE

Al finalizar cada fase debes:

1. Explicar arquitectura.
2. Explicar archivos creados.
3. Explicar dependencias.
4. Explicar flujo.
5. Esperar aprobación antes de continuar.

Nunca avanzar automáticamente a la siguiente fase.