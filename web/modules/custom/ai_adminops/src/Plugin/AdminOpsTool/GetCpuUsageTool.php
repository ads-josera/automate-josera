<?php

declare(strict_types=1);

namespace Drupal\ai_adminops\Plugin\AdminOpsTool;

use Drupal\ai_adminops\Attribute\AdminOpsTool;

#[AdminOpsTool(id: 'get_cpu_usage', label: 'Get CPU usage', description: 'Reads current CPU utilization.', parameters: ['server_id' => ['required' => TRUE]])]
final class GetCpuUsageTool extends AdminOpsToolBase {}
