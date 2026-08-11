<?php

declare(strict_types=1);

namespace Drupal\ai_adminops\Plugin\AdminOpsTool;

use Drupal\ai_adminops\Attribute\AdminOpsTool;

#[AdminOpsTool(id: 'get_ssl_status', label: 'Get SSL status', description: 'Reads SSL certificate status and expiry.', parameters: ['server_id' => ['required' => TRUE]])]
final class GetSslStatusTool extends AdminOpsToolBase {}
