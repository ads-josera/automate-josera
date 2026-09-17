# Deploy a app.josera.com.mx

Proceso real de despliegue, validado el 2026-09-16 al actualizar Drupal core
11.4.5 → 11.4.7 (SA-CORE-2026-013).

Regla práctica:

- El código vive en Git (GitHub `ads-josera/automate-josera`, rama `main`).
- **El servidor NO es un clon de Git**: los archivos se suben (cPanel o `scp`)
  y las dependencias se instalan con Composer en el servidor.
- Configuración sensible del servidor: no se pisa.
- Archivos subidos por usuarios: no se pisan.

## Datos del servidor

| Dato | Valor |
|---|---|
| Carpeta del proyecto | `/home/josera/app.josera.com.mx` (tiene `composer.json`) |
| Document root | `/home/josera/app.josera.com.mx/web` |
| PHP del sitio web | 8.4.24 (definido en cPanel → MultiPHP Manager) |
| PHP de la terminal SSH | **8.1** por defecto: no sirve para Drupal 11.4 |
| PHP 8.4 para la terminal | `/opt/cpanel/ea-php84/root/usr/bin/php` |
| Composer | `/usr/local/bin/composer` (script PHP) |

### Trampas conocidas

- **`php` en SSH es 8.1.** Composer y Drush fallan con
  `Your Composer dependencies require a PHP version ">= 8.3.0"`.
  Solución: poner PHP 8.4 primero en el `PATH` (ver «Preparar la sesión»).
  Pasar solo el binario no basta: `drush updb` lanza procesos internos con
  `#!/usr/bin/env php`, que toman el `php` del `PATH`.
- **`vendor/bin/drush` y `vendor/drush/drush/drush` son scripts de bash.**
  `php vendor/bin/drush ...` solo imprime el script sin ejecutar nada. Con un
  binario PHP explícito hay que llamar a `vendor/drush/drush/drush.php`.
- **El `.htaccess` de la carpeta del proyecto** (no el de `web/`) dice
  `ea-php81`. Es una línea vieja: el sitio usa 8.4 por MultiPHP. No editarlo a
  mano; cualquier cambio de versión se hace desde MultiPHP Manager.
- **`composer install` reescribe `web/.htaccess`** (scaffold de core). Es
  normal: las líneas de cPanel no están ahí.
- **`drush sql:dump` avisa** `Access denied; you need ... PROCESS privilege`.
  Es inofensivo en hosting compartido: el respaldo de tablas y datos se genera
  completo.
- **Local y producción no tienen los mismos módulos habilitados** (por ejemplo
  ECA está habilitado solo en local), porque la configuración no se exporta.
  Por eso `drush updb` puede aplicar actualizaciones en local y ninguna en
  producción.

## Nunca versionar ni sobrescribir

- `web/sites/default/settings.php`
- `web/sites/default/settings.local.php`
- `web/sites/default/settings.ddev.php`
- `web/sites/default/services.yml`
- `web/sites/default/files/`
- respaldos SQL, zips, logs y dumps

## 1. Local: probar, commit y push

Cada commit se sube de inmediato a GitHub; un commit sin push no se puede
desplegar ni recuperar desde otra máquina.

Probar en DDEV antes de subir:

```bash
ddev snapshot --name antes-de-<cambio>
ddev drush updb -y
ddev drush cr
ddev composer audit
```

Commit y push:

```bash
git status
git add <archivos>
git commit -m "Describe el cambio"
git push origin main
```

Listar los archivos que hay que subir al servidor desde el último despliegue:

```bash
git diff --name-only <commit-desplegado-anterior> HEAD
```

## 2. Servidor: forma de ejecutar los comandos

- Un comando por bloque, en orden. Se pega, se ejecuta y se revisa la salida
  antes de pasar al siguiente.
- Cada paso indica qué debe mostrar. Si la salida es distinta, **detenerse**
  y revisar antes de seguir.
- Al abrir una terminal nueva, repetir siempre «Preparar la sesión»: las
  variables no sobreviven entre sesiones.

### Preparar la sesión

```bash
cd ~/app.josera.com.mx
```

```bash
export PATH=/opt/cpanel/ea-php84/root/usr/bin:$PATH
```

Esta línea ya está en `~/.bashrc` del usuario `josera`, así que en sesiones
nuevas debería aplicarse sola. Se repite por seguridad.

```bash
php -v
```

Debe decir `PHP 8.4.x`.

```bash
PHP84=/opt/cpanel/ea-php84/root/usr/bin/php
DRUSH="$PHP84 vendor/drush/drush/drush.php --uri=https://app.josera.com.mx"
```

```bash
$DRUSH status
```

Debe mostrar la versión de Drupal y `Database : Connected`.

## 3. Servidor: respaldo y mantenimiento

```bash
mkdir -p ~/backups
```

```bash
$DRUSH sql:dump --gzip --result-file=$HOME/backups/pre-deploy-$(date +%Y%m%d-%H%M).sql
```

```bash
ls -lh ~/backups/
```

El `.sql.gz` debe pesar varios MB (≈1.9 MB en septiembre de 2026).

```bash
zcat ~/backups/pre-deploy-AAAAMMDD-HHMM.sql.gz | tail -1
```

Debe decir `-- Dump completed on ...`.

Si cambian las dependencias, respaldar también el lock actual:

```bash
cp composer.lock ~/backups/composer.lock.pre-deploy-$(date +%Y%m%d-%H%M)
```

```bash
$DRUSH state:set system.maintenance_mode 1 --input-format=integer
```

```bash
$DRUSH cr
```

## 4. Subir los archivos

Subir los archivos listados con `git diff --name-only` (paso 1) a
`/home/josera/app.josera.com.mx/`, respetando su ruta, con una de estas
opciones:

- **cPanel → Administrador de archivos → Cargar**, sobrescribiendo.
- **Desde la terminal del Mac:**

  ```bash
  scp <archivo-local> josera@<servidor>:~/app.josera.com.mx/<misma-ruta>
  ```

Si cambian las dependencias, lo que se sube es `composer.json` y/o
`composer.lock`. Nunca subir `vendor/`, `web/core/` ni `web/modules/contrib/`:
los instala Composer.

Para comprobar que el archivo llegó igual que en Git, comparar la suma MD5.

En el Mac:

```bash
md5 -q composer.lock
```

En el servidor debe salir el mismo valor:

```bash
md5sum composer.lock
```

## 5. Servidor: dependencias, base de datos y caché

Solo si cambiaron `composer.json` o `composer.lock`:

```bash
php /usr/local/bin/composer install --no-dev --optimize-autoloader
```

Debe terminar con `Generating optimized autoload files` y sin errores en rojo.

```bash
$DRUSH updb -y
```

Debe terminar con `[success] Finished performing updates.` o
`[success] No pending updates.`

```bash
$DRUSH cr
```

## 6. Servidor: verificación

```bash
$DRUSH status --field=drupal-version
```

```bash
php /usr/local/bin/composer audit
```

Debe decir `No security vulnerability advisories found.`

```bash
$DRUSH updatedb:status
```

Debe decir `No database updates required.`

```bash
$DRUSH watchdog:show --severity-min=3 --count=20
```

No debe haber errores posteriores al despliegue.

```bash
$DRUSH state:set system.maintenance_mode 0 --input-format=integer
```

```bash
$DRUSH cr
```

```bash
curl -s -o /dev/null -w "%{http_code}\n" https://app.josera.com.mx/user/login
```

Debe decir `200`. Si dice `500`, volver a poner mantenimiento y revisar.

### Pruebas en el navegador, con cuentas reales

- `https://app.josera.com.mx/admin/reports/status`: versión de Drupal y
  `PHP 8.4.x`.
- Login con TFA.
- Configuración de TFA con un usuario de prueba (el QR se muestra).
- Envío de correo por SES.
- AdminOps y tablero de WhatsApp.

## 7. Volver atrás

Restaura código y base de datos al estado previo. Si el despliegue era una
actualización de seguridad, el sitio vuelve a quedar vulnerable: usarlo solo
como medida temporal.

```bash
$DRUSH state:set system.maintenance_mode 1 --input-format=integer
```

Restaurar los archivos anteriores (subir la versión previa desde Git o, para
dependencias, el lock respaldado):

```bash
cp ~/backups/composer.lock.pre-deploy-AAAAMMDD-HHMM composer.lock
```

```bash
php /usr/local/bin/composer install --no-dev --optimize-autoloader
```

```bash
gunzip < ~/backups/pre-deploy-AAAAMMDD-HHMM.sql.gz | $DRUSH sql:cli
```

```bash
$DRUSH cr
```

```bash
$DRUSH state:set system.maintenance_mode 0 --input-format=integer
```

## Requisitos del servidor

Para soporte PDF en RAG, el servidor debe tener disponible `pdftotext`
(paquete `poppler-utils` en Linux). Si falta, Drupal mostrará un warning en el
reporte de estado y TXT/DOCX seguirán funcionando.

## Primera instalación en servidor

1. Crear base de datos en cPanel.
2. Crear o conservar `web/sites/default/settings.php` del servidor.
3. Configurar el document root del dominio `app.josera.com.mx` hacia `web/`.
4. En MultiPHP Manager, asignar PHP 8.4 al dominio.
5. Subir el código del repositorio (sin `vendor/`, `web/core/`,
   `web/modules/contrib/` ni los archivos de «Nunca versionar ni sobrescribir»)
   y ejecutar, con la sesión preparada (sección 2):

   ```bash
   php /usr/local/bin/composer install --no-dev --optimize-autoloader
   ```

6. Si ya existe `settings.php` en producción, no reemplazarlo.
7. Asegurar permisos de escritura solo para:

   ```text
   web/sites/default/files
   ```

## Webhooks Twilio

Cuando el sitio esté arriba, configurar en Twilio:

```text
https://app.josera.com.mx/ai-whatsapp-automation/webhook/twilio
```
