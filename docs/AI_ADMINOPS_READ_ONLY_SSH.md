# AI AdminOps: SSH de solo lectura

El conector de AI AdminOps no usa contrasenas, tokens ni llaves privadas
guardadas en Drupal. Cada servidor debe tener una referencia tecnica en la
entidad del servidor y el perfil sensible se declara solo en `settings.php`.

## Principios

- El conector acepta exclusivamente seis lecturas: carga, CPU, memoria, disco,
  cola Exim y estado SSL.
- No acepta comandos libres, argumentos remotos, claves en formularios ni
  confirmaciones de host nuevas.
- SSH exige una huella ya registrada en `known_hosts`.
- La llave remota debe usar una cuenta dedicada sin privilegios y un
  `forced-command` que valide `adminops-read <tool_id>`.
- Si el perfil no existe, el cron registra `connection_not_configured` y no
  intenta abrir SSH.

## Referencia en el servidor

En la consola AdminOps, escriba un identificador tecnico sin secretos en
**Credential reference**. Ejemplo:

```text
whm_produccion_observer
```

## Perfil privado en settings.php

Agregue el perfil correspondiente al archivo `settings.php` del ambiente. No
lo agregue a Git.

```php
$settings['ai_adminops_ssh']['whm_produccion_observer'] = [
  'username' => 'adminops_observer',
  'private_key_path' => '/home/josera/.ssh/adminops_whm_observer',
  'known_hosts_path' => '/home/josera/.ssh/known_hosts',
];
```

La llave privada y `known_hosts` deben ser legibles por el usuario que ejecuta
PHP/Drupal y no por otros usuarios del servidor. Use permisos `600` para la
llave privada.

## Contrato remoto requerido

El cliente Drupal llama exclusivamente a:

```text
adminops-read get_server_load
adminops-read get_cpu_usage
adminops-read get_memory_usage
adminops-read get_disk_usage
adminops-read get_exim_queue
adminops-read get_ssl_status
```

El servidor remoto debe rechazar cualquier otro comando y devolver un unico
objeto JSON por ejecucion. La cuenta observadora no debe tener `sudo`, shell de
administracion, reenvio de puertos, agente SSH, X11 ni TTY.

## Activacion segura

1. Cree una llave dedicada y una cuenta remota sin privilegios.
2. Instale el `forced-command` remoto que aplique la lista anterior.
3. Registre la clave publica con `no-port-forwarding,no-agent-forwarding,no-X11-forwarding,no-pty`.
4. Registre la huella del servidor en el `known_hosts` del ambiente.
5. Agregue el perfil al `settings.php` de ese ambiente.
6. Coloque la misma referencia en la ficha del servidor de AdminOps.
7. Ejecute una revision manual de cron y confirme en **Audit log** que todas
   las ejecuciones aparecen como `succeeded`.

No se deben habilitar acciones de escritura, reinicios ni escalamiento de
privilegios en esta fase.
