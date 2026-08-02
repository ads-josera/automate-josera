# Amazon SES API: Activacion Y Operacion

Guia de referencia para enviar todos los correos salientes de Drupal mediante
Amazon SES por API HTTPS. Esta configuracion se hizo para
`app.josera.com.mx`, usando el remitente `noreply@koemexico.site` y la region
AWS `us-east-1` (Norte de Virginia).

La integracion usa HTTPS hacia AWS, no SMTP. Esto evita la intercepcion de
SMTP Restrictions / SMTP Tweak de cPanel, que redirigia las conexiones SMTP de
Drupal a Exim local y provocaba errores de certificado.

## Alcance

Al dejar activo este backend, Drupal entrega por Amazon SES los correos de:

- Restablecimiento de contrasena y correos de usuario.
- Webform y otros formularios que usen el mailer predeterminado de Drupal.
- Notificaciones de modulos, cron y avisos administrativos.
- Cualquier modulo que no configure expresamente un mailer propio.

No recibe correo entrante y no modifica Twilio, WhatsApp ni el chat web.

## Requisitos

- Cuenta AWS con acceso a Amazon SES e IAM.
- Control de la zona DNS de `koemexico.site`.
- Acceso SSH a produccion y permiso para editar
  `web/sites/default/settings.php`.
- PHP 8.4 en el servidor.

## 1. Abrir Amazon SES en la region correcta

1. En la consola de AWS, abrir **Amazon SES**.
2. Seleccionar **Estados Unidos (Norte de Virginia)**, cuyo codigo es
   `us-east-1`.
3. Seleccionar **Comenzar** si es la primera vez que se usa SES.
4. En la preparacion inicial, verificar una direccion de correo que se pueda
   abrir. Puede ser una direccion administrativa, por ejemplo
   `josera.pereah@gmail.com`.
5. Abrir el correo de AWS y confirmar el enlace de verificacion.

La direccion sirve para validar la cuenta. El remitente real se habilita al
verificar el dominio en el paso siguiente.

## 2. Verificar el dominio remitente

1. En SES, abrir **Identidades** y agregar el dominio `koemexico.site`.
2. Activar DKIM Easy DKIM con firma `RSA_2048_BIT`.
3. Configurar un dominio MAIL FROM personalizado. En esta instalacion se usa
   `ses.koemexico.site`.
4. Copiar en el proveedor DNS los registros que SES muestra:
   - Tres registros `CNAME` de DKIM.
   - Un `MX` para `ses.koemexico.site`, con prioridad `10`, apuntando a
     `feedback-smtp.us-east-1.amazonses.com`.
   - Un `TXT` SPF para `ses.koemexico.site` con
     `v=spf1 include:amazonses.com ~all`.
   - El registro DMARC recomendado por SES. Si ya existe un registro
     `_dmarc.koemexico.site`, no crear otro: conservar uno solo y ajustar su
     valor cuando sea necesario.
5. Esperar la propagacion DNS y actualizar la pantalla de SES hasta que la
   identidad, DKIM y MAIL FROM indiquen **Verificado**.

No se deben copiar valores de ejemplo: los selectores CNAME de DKIM son
distintos para cada cuenta e identidad.

## 3. Solicitar acceso de produccion

Mientras SES esta en sandbox solo permite enviar a identidades verificadas.

1. En el panel de SES elegir **Solicitar acceso de produccion**.
2. Seleccionar **Transaccional**.
3. Sitio web: `https://app.josera.com.mx`.
4. Explicar que se enviaran correos transaccionales de Drupal: restablecimiento
   de contrasena, formularios Webform, avisos administrativos y
   notificaciones solicitadas por usuarios.
5. Aceptar los terminos y enviar la solicitud.
6. Esperar la aprobacion y confirmar en el panel que aparece
   **Acceso a produccion concedido**.

## 4. Crear un usuario IAM solo para el API

No usar las credenciales de la cuenta root ni las credenciales SMTP de SES.

1. Abrir **IAM > Usuarios** y crear un usuario sin acceso a consola, por
   ejemplo `drupal-app-josera-ses-api`.
2. Crear una clave de acceso para uso programatico.
3. Asociar una politica minima. Sustituir el ID de cuenta si se configura en
   otra cuenta AWS:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "SendOnlyFromKoemexico",
      "Effect": "Allow",
      "Action": "ses:SendRawEmail",
      "Resource": [
        "arn:aws:ses:us-east-1:325527186316:identity/koemexico.site",
        "arn:aws:ses:us-east-1:325527186316:configuration-set/my-first-configuration-set"
      ],
      "Condition": {
        "StringEquals": {
          "ses:FromAddress": "noreply@koemexico.site"
        }
      }
    }
  ]
}
```

El recurso del configuration set solo se requiere si SES tiene un conjunto de
configuracion predeterminado, como `my-first-configuration-set`. Si no existe
uno, se puede omitir esa linea.

4. Guardar en un gestor de contrasenas el **Access key ID** y el **Secret
   access key**. El secreto se muestra una unica vez.

## 5. Desplegar el backend SES en Drupal

El proyecto ya incluye el backend `amazon_ses_api` y su dependencia
`aws/aws-sdk-php`. Para futuras instalaciones o actualizaciones del proyecto:

```bash
cd /home/josera/app.josera.com.mx/_repo
git pull origin main

cd /home/josera/app.josera.com.mx
rsync -av \
  --exclude='.git' \
  --exclude='web/sites/default/settings.php' \
  --exclude='web/sites/default/settings.local.php' \
  --exclude='web/sites/default/settings.ddev.php' \
  --exclude='web/sites/default/services.yml' \
  --exclude='web/sites/default/files/' \
  _repo/ ./

PHP84=/opt/cpanel/ea-php84/root/usr/bin/php
export PATH=/opt/cpanel/ea-php84/root/usr/bin:/usr/local/bin:/usr/bin:/bin

$PHP84 /usr/local/bin/composer install --no-dev --optimize-autoloader
$PHP84 vendor/drush/drush/drush.php updb -y
$PHP84 vendor/drush/drush/drush.php cr
```

No subir `settings.php` a Git ni incluirlo en el `rsync`.

## 5.1 Usar El Modulo Reutilizable SES API Mailer

Este proyecto incluye el modulo reutilizable `ses_api_mailer`. Puede
instalarse en otros proyectos Drupal que tengan la dependencia Composer
`aws/aws-sdk-php`.

Para un nuevo sitio Drupal, agregar este bloque seguro a `settings.php`:

```php
$settings['ses_api_mailer'] = [
  'region' => 'us-east-1',
  'access_key_id' => 'REPLACE_WITH_AWS_ACCESS_KEY_ID',
  'secret_access_key' => 'REPLACE_WITH_AWS_SECRET_ACCESS_KEY',
  'from_address' => 'noreply@example.com',
  'from_name' => 'Site notifications',
];
```

Despues habilitar el modulo y visitar
`/admin/config/system/ses-api-mailer`. El formulario envia una prueba y puede
activar o desactivar SES como mailer predeterminado de Drupal. Al desactivarlo,
restaura el mailer anterior.

Para migrar este proyecto actual al modulo generico, hacer lo siguiente una
vez desplegado el modulo:

1. Copiar las credenciales SES existentes a `$settings['ses_api_mailer']`.
2. Eliminar de `settings.php` la linea fija:

   ```php
   $config['system.mail']['interface']['default'] = 'amazon_ses_api';
   ```

3. Habilitar **SES API Mailer** en `/admin/modules`.
4. Abrir `/admin/config/system/ses-api-mailer`, enviar una prueba y activar
   **Use Amazon SES API for Drupal email**.

La linea fija debe retirarse porque la configuracion sobreescrita desde
`settings.php` no se puede activar ni desactivar mediante un formulario.

## 6. Configurar produccion sin exponer secretos

Editar manualmente el archivo de produccion:

```bash
cd /home/josera/app.josera.com.mx
chmod 644 web/sites/default/settings.php
```

Al final de `web/sites/default/settings.php`, agregar las claves reales:

```php
$settings['ai_whatsapp_automation_ses'] = [
  'region' => 'us-east-1',
  'access_key_id' => 'REEMPLAZAR_CON_ACCESS_KEY_ID',
  'secret_access_key' => 'REEMPLAZAR_CON_SECRET_ACCESS_KEY',
  'from_address' => 'noreply@koemexico.site',
  'from_name' => 'Automate Josera',
];

$config['system.mail']['interface']['default'] = 'amazon_ses_api';
```

Despues protegerlo de nuevo y reconstruir cache:

```bash
chmod 444 web/sites/default/settings.php

PHP84=/opt/cpanel/ea-php84/root/usr/bin/php
export PATH=/opt/cpanel/ea-php84/root/usr/bin:/usr/local/bin:/usr/bin:/bin
$PHP84 vendor/drush/drush/drush.php cr
```

El bloque anterior activa Amazon SES API como mailer predeterminado global de
Drupal. La configuracion vieja del modulo SMTP puede quedarse guardada, pero
ya no es usada mientras esta asignacion exista.

## 7. Probar el backend real de Drupal

Ejecutar desde produccion:

```bash
cd /home/josera/app.josera.com.mx
PHP84=/opt/cpanel/ea-php84/root/usr/bin/php

$PHP84 vendor/drush/drush/drush.php php:eval '
$mail = \Drupal::service("plugin.manager.mail")->createInstance("amazon_ses_api");
$message = [
  "to" => "ads@josera.com.mx",
  "subject" => "Prueba Drupal mediante Amazon SES API",
  "body" => ["Correo enviado por el backend real de Drupal."],
  "headers" => ["Content-Type" => "text/plain; charset=UTF-8"],
  "params" => [],
];
$message = $mail->format($message);
var_export($mail->mail($message));
echo PHP_EOL;
'
```

Resultado esperado:

```text
true
```

Tambien debe llegar el correo al destinatario. `true` confirma que Drupal
entrego el mensaje a SES; SES se encarga de su entrega final.

## Diagnostico de problemas encontrados

### Error de certificado al usar SMTP

Sintoma:

```text
Peer certificate CN=47.47.167.72.host.secureserver.net
hostname mismatch
```

La causa fue **SMTP Restrictions / SMTP Tweak** de cPanel: las conexiones
salientes SMTP de scripts se redirigian al Exim local. No se debe desactivar
esa proteccion solo para hacer funcionar Drupal. Amazon SES API evita el
problema porque se comunica por HTTPS en puerto 443.

### `AccessDenied` para `SendRawEmail`

Revisar que la politica IAM permita `ses:SendRawEmail` para la identidad
`koemexico.site`. Si SES usa un configuration set predeterminado, agregar su
ARN a `Resource` como se muestra en el paso 4.

### `trim(): Argument #1 ... null given`

Este error ocurrio en una version inicial del backend cuando el mensaje no
tenia encabezados CC/BCC. Fue corregido en el commit `b7e93cc`
(`Handle empty SES recipient headers`). Desplegar esa version o una posterior.

### Comprobar los ultimos eventos de correo

```bash
PHP84=/opt/cpanel/ea-php84/root/usr/bin/php
$PHP84 vendor/drush/drush/drush.php watchdog:show --type=mail --count=10 --format=table
```

## Revertir temporalmente a otro mailer

Para dejar de usar SES API, comentar o eliminar solamente esta linea de
`settings.php` y reconstruir cache:

```php
$config['system.mail']['interface']['default'] = 'amazon_ses_api';
```

No eliminar las credenciales de AWS hasta comprobar que otro metodo de entrega
funciona. Nunca compartir ni versionar el bloque de secretos.
