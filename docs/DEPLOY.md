# Deploy a app.josera.com.mx

Regla practica:

- Codigo y estructura: si se actualizan desde Git.
- Configuracion sensible del servidor: no se pisa.
- Archivos subidos por usuarios: no se pisan.

## Nunca versionar ni sobrescribir

- `web/sites/default/settings.php`
- `web/sites/default/settings.local.php`
- `web/sites/default/settings.ddev.php`
- `web/sites/default/services.yml`
- `web/sites/default/files/`
- respaldos SQL, zips, logs y dumps

## Comandos locales

```bash
git status
git add .
git commit -m "Describe el cambio"
git push origin main
```

Antes de subir cambios importantes:

```bash
ddev exec drush updb -y
ddev exec drush cr
```

## Comandos en servidor

Estos comandos van en el directorio del proyecto en el servidor:

```bash
git pull origin main
composer install --no-dev --optimize-autoloader
php vendor/bin/drush updb -y
php vendor/bin/drush cr
```

Si el servidor usa otro binario PHP, ajustar `php`.

Para soporte PDF en RAG, el servidor debe tener disponible `pdftotext`
paquete `poppler-utils` en Linux. Si falta, Drupal mostrara un warning en el
reporte de estado y TXT/DOCX seguiran funcionando.

## Primera instalacion en servidor

1. Crear base de datos en cPanel.
2. Crear o conservar `web/sites/default/settings.php` del servidor.
3. Configurar document root del dominio `app.josera.com.mx` hacia `web/`.
4. Clonar el repo:

```bash
git clone git@github.com:ads-josera/automate-josera.git .
composer install --no-dev --optimize-autoloader
```

5. Si ya existe `settings.php` en produccion, no reemplazarlo.
6. Asegurar permisos de escritura solo para:

```text
web/sites/default/files
```

## Si cPanel no permite Composer

No subir `settings.php` ni `files/`. Preparar un build local con dependencias y subir solo el artefacto generado excluyendo:

```text
web/sites/default/settings.php
web/sites/default/settings.local.php
web/sites/default/settings.ddev.php
web/sites/default/services.yml
web/sites/default/files/
```

## Webhooks Twilio

Cuando el sitio este arriba, configurar en Twilio:

```text
https://app.josera.com.mx/ai-whatsapp-automation/webhook/twilio
```
