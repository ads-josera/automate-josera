<?php

declare(strict_types=1);

namespace Drupal\ai_adminops\Plugin\AdminOpsTool;

use Drupal\ai_adminops\Attribute\AdminOpsTool;

#[AdminOpsTool(id: 'get_disk_usage', label: 'Get disk usage', description: 'Reads filesystem capacity and usage.', parameters: ['server_id' => ['required' => TRUE]])]
final class GetDiskUsageTool extends AdminOpsToolBase {}
