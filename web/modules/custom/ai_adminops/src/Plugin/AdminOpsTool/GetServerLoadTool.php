<?php

declare(strict_types=1);

namespace Drupal\ai_adminops\Plugin\AdminOpsTool;

use Drupal\ai_adminops\Attribute\AdminOpsTool;

#[AdminOpsTool(id: 'get_server_load', label: 'Get server load', description: 'Reads the current server load average.', parameters: ['server_id' => ['required' => TRUE]])]
final class GetServerLoadTool extends AdminOpsToolBase {}
