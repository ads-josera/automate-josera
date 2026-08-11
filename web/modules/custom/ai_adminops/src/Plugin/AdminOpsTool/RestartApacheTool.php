<?php

declare(strict_types=1);

namespace Drupal\ai_adminops\Plugin\AdminOpsTool;

use Drupal\ai_adminops\Attribute\AdminOpsTool;

#[AdminOpsTool(id: 'restart_apache', label: 'Restart Apache', description: 'Requests an Apache restart after approval.', risk: 'critical', parameters: ['server_id' => ['required' => TRUE]])]
final class RestartApacheTool extends AdminOpsToolBase {}
