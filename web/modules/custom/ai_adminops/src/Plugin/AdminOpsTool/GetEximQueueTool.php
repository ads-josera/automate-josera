<?php

declare(strict_types=1);

namespace Drupal\ai_adminops\Plugin\AdminOpsTool;

use Drupal\ai_adminops\Attribute\AdminOpsTool;

#[AdminOpsTool(id: 'get_exim_queue', label: 'Get Exim queue', description: 'Reads current Exim queue information.', parameters: ['server_id' => ['required' => TRUE]])]
final class GetEximQueueTool extends AdminOpsToolBase {}
