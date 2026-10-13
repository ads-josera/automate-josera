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

## Script remoto versionado (2026-10-11)

El `forced-command` real es `/usr/local/sbin/adminops-read` (cuenta `adminops_observer`). Desde el 2026-10-11 su fuente vive en **`scripts/server/adminops-read`** y se instala desde `_repo`. Cambios de esa versión:

- **Exim**: la cuenta observadora no puede contar la cola (solo root), por eso siempre daba `unavailable`. Ahora usa `sudo -n /usr/sbin/exim -bp`, con un `sudoers` (`/etc/sudoers.d/adminops_observer`) que permite **solo ese comando**, y devuelve solo conteos: `queue_count` (mensajes **activos**, el valor que leen las alertas), `frozen_count` y `total_count`. Los congelados van aparte porque no son correo esperando salir: el 2026-10-11 había ~530, todos rebotes (`<>`) al hostname, renovándose solos cada 24 h, y habrían disparado una alerta permanente.
- **SSL**: lee los certificados de los dominios listados en `/etc/adminops/ssl-domains` (uno por línea, `#` comenta), conectando a `127.0.0.1:443` con SNI. Devuelve `days_left` y `matches_domain` por dominio, y `min_days_left` solo de los que coinciden (un certificado de otro nombre es el predeterminado del servidor y no dice nada del dominio).
- **Reinicio pendiente** (`get_reboot_status`, nueva): `needs-restarting -r`, los paquetes que lo piden (`updated_packages`, hasta 10), el kernel en uso y el más nuevo instalado. El 2026-10-11 pedía reinicio con el kernel ya al día: lo piden también glibc, systemd, openssl… Sentinel avisa; reiniciar es cosa de José en WHM.

## Consumo por cuenta (fase C1, 2026-10-13)

Tres revisiones más en `adminops-read`: `get_accounts`, `get_mail_by_account` (las dos por `sudo -n /usr/local/sbin/adminops-whm-read accounts|mail`) y `get_account_processes` (`ps`, sin sudo). El `sudoers` del observador queda así:

```
Defaults:adminops_observer !requiretty
adminops_observer ALL=(root) NOPASSWD: /usr/sbin/exim -bp, /usr/local/sbin/adminops-whm-read accounts, /usr/local/sbin/adminops-whm-read mail
```
