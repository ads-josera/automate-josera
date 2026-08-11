<?php

declare(strict_types=1);

namespace Drupal\ai_adminops\Plugin\AdminOpsTool;

use Drupal\ai_adminops\Attribute\AdminOpsTool;

#[AdminOpsTool(id: 'restart_mysql', label: 'Restart MySQL', description: 'Requests a MySQL restart after approval.', risk: 'critical', parameters: ['server_id' => ['required' => TRUE]])]
final class RestartMysqlTool extends AdminOpsToolBase {}
