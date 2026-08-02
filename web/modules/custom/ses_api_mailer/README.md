# SES API Mailer

Reusable Drupal mail backend for Amazon SES using the HTTPS API. It avoids
outbound SMTP restrictions imposed by cPanel or hosting providers.

## Installation

1. Ensure the project contains `aws/aws-sdk-php`.
2. Enable **SES API Mailer** at `/admin/modules`.
3. Add credentials to `settings.php`, never to exported Drupal configuration:

```php
$settings['ses_api_mailer'] = [
  'region' => 'us-east-1',
  'access_key_id' => 'ACCESS_KEY_ID',
  'secret_access_key' => 'SECRET_ACCESS_KEY',
  'from_address' => 'noreply@example.com',
  'from_name' => 'Site notifications',
];
```

4. Visit `/admin/config/system/ses-api-mailer`.
5. Send a test email, then enable **Use Amazon SES API for Drupal email**.

Disabling the setting restores the default mailer that was active before SES
API Mailer was enabled.

## Migrating an existing custom SES integration

If a site was previously using this project's `amazon_ses_api` plugin, move
the same credentials to the `ses_api_mailer` settings array and remove any
fixed `$config['system.mail']['interface']['default']` override from
`settings.php`. A fixed settings.php override cannot be changed by an
administrative form. Then enable this module and use its settings page to
activate delivery.

## IAM policy

Grant the IAM user only `ses:SendRawEmail` for the verified SES identity. See
`docs/SES_API_SETUP.md` for the complete operating guide.
