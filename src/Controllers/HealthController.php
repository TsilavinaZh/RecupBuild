<?php

declare(strict_types=1);

namespace RecupBuild\Controllers;

use RecupBuild\Core\Controller;
use RecupBuild\Core\Http;

/** GET /api/health: whether the model key is there, and which models answer. */
final class HealthController extends Controller
{
    public function show(): never
    {
        Http::json(200, [
            'status' => 'ok',
            'vision_model' => $this->config->visionModel,
            'key_configured' => $this->config->metaApiKey !== '',
            'open_weights_model' => $this->config->openWeightsBase !== '' ? $this->config->openWeightsModel : null,
        ]);
    }
}
