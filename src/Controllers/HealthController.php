<?php

declare(strict_types=1);

namespace RecupBuild\Controllers;

use RecupBuild\Core\Controller;
use RecupBuild\Core\Http;

/** GET /api/health: whether the model key is there, and which model answers. */
final class HealthController extends Controller
{
    public function show(): never
    {
        Http::json(200, [
            'status' => 'ok',
            'vision_model' => $this->config->visionModel,
            'key_configured' => $this->config->metaApiKey !== '',
        ]);
    }
}
