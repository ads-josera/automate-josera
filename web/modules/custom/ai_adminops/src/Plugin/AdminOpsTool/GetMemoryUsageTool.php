<?php

declare(strict_types=1);

namespace Drupal\ai_adminops\Plugin\AdminOpsTool;

use Drupal\ai_adminops\Attribute\AdminOpsTool;

#[AdminOpsTool(id: 'get_memory_usage', label: 'Get memory usage', description: 'Reads current memory utilization.', parameters: ['server_id' => ['required' => TRUE]])]
final class GetMemoryUsageTool extends AdminOpsToolBase {}
