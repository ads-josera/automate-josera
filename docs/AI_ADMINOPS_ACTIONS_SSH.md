# AI AdminOps: SSH para las acciones de Sentinel (fase B)

Las acciones usan **una cuenta y una llave distintas** a las del monitoreo. Si se filtrara la llave de lectura, no serviría para reiniciar nada; y la de acciones solo puede pedir dos cosas.

## Lo que se instala en el servidor (como root)

1. **Cuenta** `adminops_actor`, sin contraseña y sin shell interactiva útil.
2. **Script** `/usr/local/bin/adminops-act` (dueño root, permisos 755). Es el `forced-command`: lee `SSH_ORIGINAL_COMMAND`, acepta solo `adminops-act restart_apache` y `adminops-act restart_php_fpm`, y responde un solo JSON:

```bash
#!/bin/bash
# Forced command for the adminops_actor key: two actions, nothing else.
set -u
emit() { printf '{"status":"%s","action":"%s","exit_code":%d}\n' "$1" "$2" "$3"; }
read -r cmd action extra <<< "${SSH_ORIGINAL_COMMAND:-}"
if [ "${cmd:-}" != "adminops-act" ] || [ -n "${extra:-}" ]; then emit rejected unknown 1; exit 1; fi
case "${action:-}" in
  restart_apache)  sudo -n /usr/local/cpanel/scripts/restartsrv_httpd --graceful >/dev/null 2>&1; rc=$? ;;
  restart_php_fpm) sudo -n /usr/local/cpanel/scripts/restartsrv_apache_php_fpm >/dev/null 2>&1; rc=$? ;;
  *) emit rejected unknown 1; exit 1 ;;
esac
if [ "$rc" -eq 0 ]; then emit ok "$action" 0; else emit failed "$action" "$rc"; fi
```

3. **sudoers** `/etc/sudoers.d/adminops_actor` (permisos 440, validar con `visudo -cf`):

```
Defaults:adminops_actor !requiretty
adminops_actor ALL=(root) NOPASSWD: /usr/local/cpanel/scripts/restartsrv_httpd --graceful, /usr/local/cpanel/scripts/restartsrv_apache_php_fpm
```

4. **Llave pública** en `~adminops_actor/.ssh/authorized_keys`:

```
command="/usr/local/bin/adminops-act",no-port-forwarding,no-agent-forwarding,no-X11-forwarding,no-pty,no-user-rc ssh-ed25519 AAAA… adminops-actions
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
