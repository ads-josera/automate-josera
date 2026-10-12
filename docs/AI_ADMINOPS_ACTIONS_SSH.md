# AI AdminOps: SSH para las acciones de Sentinel (fase B)

Las acciones usan **una cuenta y una llave distintas** a las del monitoreo. Si se filtrara la llave de lectura, no serviría para reiniciar nada; y la de acciones solo puede pedir dos cosas.

## Lo que se instala en el servidor (como root)

1. **Cuenta** `adminops_actor`, sin contraseña y sin shell interactiva útil.
2. **Script** `/usr/local/sbin/adminops-act` (dueño root, permisos 755), junto al `adminops-read` del monitoreo. Su fuente está versionada en **`scripts/server/adminops-act`** y se instala desde `_repo` (fuera del webroot). Es el `forced-command`: acepta solo `adminops-act restart_apache` y `adminops-act restart_php_fpm`, responde un solo JSON (`ok`, `failed` o `rejected`) y deja una línea en el syslog (`adminops-act`).

3. **sudoers** `/etc/sudoers.d/adminops_actor` (permisos 440, validar con `visudo -cf`):

```
Defaults:adminops_actor !requiretty
adminops_actor ALL=(root) NOPASSWD: /usr/local/cpanel/scripts/restartsrv_httpd --graceful, /usr/local/cpanel/scripts/restartsrv_apache_php_fpm
```

4. **Llave pública** en `~adminops_actor/.ssh/authorized_keys`:

```
command="/usr/local/sbin/adminops-act",no-port-forwarding,no-agent-forwarding,no-X11-forwarding,no-pty,no-user-rc ssh-ed25519 AAAA… adminops-actions
```

## Lo que va en `settings.php` de producción (no en Git)

```php
// Same key as the server's "Credential reference" in AdminOps.
$settings['ai_adminops_ssh_actions']['<credential_reference>'] = [
  'username' => 'adminops_actor',
  'private_key_path' => '/home/josera/.ssh/adminops_whm_actor',
  'known_hosts_path' => '/home/josera/.ssh/known_hosts',
];
// Where the approval codes go. Only here, never in the panel.
$settings['ai_adminops_sentinel_approval_email'] = 'ads@josera.com.mx';
```

Sin estas dos entradas no hay acciones: Sentinel no recibe las herramientas `solicitar_accion` ni `confirmar_accion`.

## Cómo apagarlo de emergencia

Quitar (o comentar) `ai_adminops_sentinel_approval_email` en `settings.php`: al siguiente mensaje Sentinel ya no tiene acciones. Para cortarlo también del lado del servidor, borrar la línea de `authorized_keys` de `adminops_actor`.
