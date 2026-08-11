<?php

declare(strict_types=1);

namespace Drupal\ai_adminops\Plugin\AdminOpsTool;

use Drupal\ai_adminops\Attribute\AdminOpsTool;

#[AdminOpsTool(id: 'restart_php_fpm', label: 'Restart PHP-FPM', description: 'Requests a PHP-FPM restart after approval.', risk: 'critical', parameters: ['server_id' => ['required' => TRUE]])]
final class RestartPhpFpmTool extends AdminOpsToolBase {}
