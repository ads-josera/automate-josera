# Amazon SES API Mail Setup

This project can send Drupal mail through the Amazon SES HTTPS API. This route
uses outbound HTTPS (port 443), so it does not depend on the hosting provider's
SMTP restrictions.

## 1. Create a restricted API key

In AWS IAM, create an access key for the SES sending user. The key must have
only `ses:SendRawEmail` permission. Keep the access key ID and secret access
key private.

## 2. Configure each environment outside Git

Add the following to the end of the environment's `web/sites/default/settings.php`.
Use that environment's own credentials. Do not add these values to any tracked
configuration file.

```php
$settings['ai_whatsapp_automation_ses'] = [
  'region' => 'us-east-1',
  'access_key_id' => 'REPLACE_WITH_AWS_ACCESS_KEY_ID',
  'secret_access_key' => 'REPLACE_WITH_AWS_SECRET_ACCESS_KEY',
  'from_address' => 'noreply@koemexico.site',
  'from_name' => 'Automate Josera',
];

$config['system.mail']['interface']['default'] = 'amazon_ses_api';
```

## 3. Rebuild Drupal caches and test

```bash
PHP84=/opt/cpanel/ea-php84/root/usr/bin/php
$PHP84 vendor/drush/drush/drush.php cr
```

The SMTP module is no longer required for delivery once the settings override
above is active. Keep the SMTP credentials until API delivery is confirmed.

## Operational notes

- The Amazon SES identity `koemexico.site` must remain verified in `us-east-1`.
- The IAM access key is different from SES SMTP credentials.
- SES credentials and the `settings.php` file must never be committed to Git.
