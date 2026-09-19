# Deploy a app.josera.com.mx

Proceso real de despliegue. Validado el 2026-09-16 (Drupal core 11.4.5 →
11.4.7) y el 2026-09-18 (fase 1 de AI WhatsApp, commit `fef76e4`).

## Cómo llega el código al servidor

```text
GitHub (main) ──git pull──▶ ~/app.josera.com.mx/_repo ──rsync──▶ ~/app.josera.com.mx
                               (clon de git)                       (sitio en vivo)
```

- El sitio en vivo (`~/app.josera.com.mx`) **no** es un clon de git: por eso
  `git status` ahí dice «not a git repository».
- El clon está en la subcarpeta **`_repo`**. Se actualiza con `git pull` y
  después `rsync` copia los archivos al sitio.
- Configuración sensible del servidor y archivos subidos por usuarios: no se
  pisan (ver exclusiones del `rsync`).

## Datos del servidor

| Dato | Valor |
|---|---|
| SSH | `josera@72.167.47.47` |
| Sitio en vivo | `/home/josera/app.josera.com.mx` (tiene `composer.json`) |
| Clon de git | `/home/josera/app.josera.com.mx/_repo` |
| Document root | `/home/josera/app.josera.com.mx/web` |
| PHP del sitio web | 8.4.24 (cPanel → MultiPHP Manager) |
| PHP de la terminal SSH | **8.1** por defecto: no sirve para Drupal 11.4 |
| PHP 8.4 para la terminal | `/opt/cpanel/ea-php84/root/usr/bin/php` |
| Composer | `/usr/local/bin/composer` (script PHP) |

### Trampas conocidas

- **`web/.htaccess` de producción termina con el bloque de cPanel que activa
  PHP 8.4** (`AddHandler application/x-httpd-ea-php84`). El `.htaccess` del
  repositorio no lo tiene. Si se sobrescribe, el sitio cae al PHP 8.1 del
  `.htaccess` superior y deja de funcionar. Por eso:
  - el `rsync` **siempre** excluye `web/.htaccess`;
  - después de cualquier `composer install` (el scaffold de core reescribe
    `web/.htaccess`) hay que confirmar `grep -c ea-php84 web/.htaccess` (debe
    ser `2`) y, si falta, restaurar el bloque desde un respaldo o desde
    MultiPHP Manager.
- **`php` en SSH es 8.1.** Composer y Drush fallan con
  `Your Composer dependencies require a PHP version ">= 8.3.0"`. Solución:
  PHP 8.4 primero en el `PATH` (ver «Preparar la sesión»). Pasar solo el
  binario no basta: `drush updb` lanza procesos internos con
  `#!/usr/bin/env php`.
- **`vendor/bin/drush` y `vendor/drush/drush/drush` son scripts de bash.**
  Con un binario PHP explícito hay que llamar a `vendor/drush/drush/drush.php`.
- **`drush sql:dump` avisa** `Access denied; you need ... PROCESS privilege`.
  Es inofensivo: el respaldo de tablas y datos se genera completo.
- **Local y producción no tienen los mismos módulos habilitados** (ECA solo
  en local), porque la configuración no se exporta.
- **Modo mantenimiento:** devuelve 503 también a los webhooks de Twilio, así
  que los WhatsApp que lleguen mientras está activo se pierden. Para cambios
  de código se prefiere no activarlo y ejecutar copia + `cr` + `updb` + `cr`
  en una sola línea, en un horario con poco movimiento.

## Nunca versionar ni sobrescribir

- `web/.htaccess` de producción (ver arriba)
- `web/sites/default/settings.php`, `settings.local.php`, `settings.ddev.php`
- `web/sites/default/services.yml`
- `web/sites/default/files/`
- respaldos SQL, zips, logs y dumps

## 1. Local: probar, commit y push

```bash
ddev snapshot --name antes-de-<cambio>
ddev drush updb -y
ddev drush cr
ddev exec "SIMPLETEST_DB=mysql://db:db@db/db vendor/bin/phpunit -c web/core web/modules/custom/ai_whatsapp_automation/tests"
```

Las pruebas necesitan `drupal/core-dev`, que **no** está en `composer.json`
(instalarlo bajaba un paquete que usa producción). En local:
`ddev composer require --dev drupal/core-dev:^11.4` y después
`git checkout -- composer.json composer.lock` antes de hacer commit.

```bash
git add <archivos>
git commit -m "Describe el cambio"
git push origin main
```

## 2. Servidor: forma de ejecutar los comandos

- Un comando por bloque, en orden. Se revisa la salida antes de seguir.
- Si la salida no es la indicada, **detenerse**.
- En cada terminal nueva, repetir «Preparar la sesión».

### Preparar la sesión

```bash
cd ~/app.josera.com.mx
PHP84=/opt/cpanel/ea-php84/root/usr/bin/php
DRUSH="$PHP84 vendor/drush/drush/drush.php --uri=https://app.josera.com.mx"
export PATH=/opt/cpanel/ea-php84/root/usr/bin:$PATH
```

```bash
$DRUSH status --field=drupal-version
```

## 3. Servidor: respaldos

```bash
$DRUSH sql:dump --gzip --result-file=$HOME/backups/pre-deploy-$(date +%Y%m%d-%H%M).sql
```

```bash
tar -czf ~/backups/custom-modules-pre-deploy-$(date +%Y%m%d-%H%M).tar.gz web/modules/custom && cp web/.htaccess ~/backups/web-htaccess-pre-deploy && ls -lh ~/backups/
```

## 4. Servidor: actualizar el clon

```bash
cd ~/app.josera.com.mx/_repo
```

```bash
git status --short; git log --oneline -1
```

`git status` no debe mostrar nada (clon limpio).

```bash
git pull origin main
```

Debe terminar en `Fast-forward` con el commit esperado.

## 5. Servidor: ensayo del rsync (no cambia nada)

```bash
cd ~/app.josera.com.mx
```

```bash
rsync -anci --exclude='.git' --exclude='web/.htaccess' --exclude='tests/' --exclude='web/sites/default/settings.php' --exclude='web/sites/default/settings.local.php' --exclude='web/sites/default/settings.ddev.php' --exclude='web/sites/default/services.yml' --exclude='web/sites/default/files/' _repo/ ./ | grep -v '/$'
```

Revisar la lista: solo deben aparecer los archivos del commit. `.f..t` significa
que solo cambia la fecha (inofensivo); `>fcst` y `>f+++` son contenido
cambiado o nuevo. Si aparece algo inesperado (un archivo de core, `.htaccess`,
`settings.php`), **detenerse** y revisar con `diff _repo/<archivo> <archivo>`.

## 6. Servidor: despliegue

Si cambiaron `composer.json` o `composer.lock` en el commit, correr antes
`$PHP84 /usr/local/bin/composer install --no-dev --optimize-autoloader` y
revisar `web/.htaccess` (ver «Trampas conocidas»).

```bash
rsync -aci --exclude='.git' --exclude='web/.htaccess' --exclude='tests/' --exclude='web/sites/default/settings.php' --exclude='web/sites/default/settings.local.php' --exclude='web/sites/default/settings.ddev.php' --exclude='web/sites/default/services.yml' --exclude='web/sites/default/files/' _repo/ ./ | grep -c '^>f' && $DRUSH cr && $DRUSH updb -y && $DRUSH cr
```

## 7. Servidor: verificación

```bash
grep -c "ea-php84" web/.htaccess; curl -s -o /dev/null -w "%{http_code}\n" https://app.josera.com.mx/user/login
```

Debe mostrar `2` y `200`.

```bash
$DRUSH updatedb:status; $DRUSH watchdog:show --severity-min=3 --count=10
```

Después, en el navegador y con cuentas reales: página de estado, login con TFA,
un WhatsApp de prueba al bot, el widget web y las pantallas que tocó el cambio.

## 8. Volver atrás

```bash
rm -rf web/modules/custom && tar -xzf ~/backups/custom-modules-pre-deploy-AAAAMMDD-HHMM.tar.gz && gunzip < ~/backups/pre-deploy-AAAAMMDD-HHMM.sql.gz | $DRUSH sql:cli && $DRUSH cr
```

Si el despliegue incluyó dependencias, restaurar también el `composer.lock`
anterior y correr `composer install --no-dev`.

## Requisitos del servidor

Para soporte PDF en RAG, el servidor debe tener `pdftotext` (paquete
`poppler-utils`). Si falta, Drupal muestra un warning en el reporte de estado y
TXT/DOCX siguen funcionando.

## Primera instalación en servidor

1. Crear base de datos en cPanel.
2. Crear o conservar `web/sites/default/settings.php` del servidor.
3. Document root de `app.josera.com.mx` hacia `web/`; PHP 8.4 en MultiPHP
   Manager.
4. Clonar el repositorio en `~/app.josera.com.mx/_repo` y copiarlo al sitio
   con el `rsync` de la sección 6.
5. Con la sesión preparada:
   `$PHP84 /usr/local/bin/composer install --no-dev --optimize-autoloader`
   y revisar `web/.htaccess`.
6. Permisos de escritura solo para `web/sites/default/files`.

## Webhooks Twilio

```text
https://app.josera.com.mx/ai-whatsapp-automation/webhook/twilio
```
