<?php

declare(strict_types=1);

namespace RecupBuild;

/**
 * Muse Spark (Meta Model API) as the eyes and the brain of the app
 * (port of backend/app/providers/vision.py, plus the text calls of Planner).
 *
 * Docs: https://dev.meta.ai/docs/image-understanding and /docs/structured-output.
 * The model answers in a fixed JSON schema, with coordinates on its normalized
 * 0-1000 grid (0,0 top-left, 1000,1000 bottom-right of the image it received).
 */
final class Vision
{
    private const POINT = ['type' => 'array', 'items' => ['type' => 'number'], 'minItems' => 2, 'maxItems' => 2];

    /** @var array<string, mixed> */
    public const SCHEMA = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['a4', 'pieces'],
        'properties' => [
            'a4' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['found', 'corners'],
                'properties' => ['found' => ['type' => 'boolean'], 'corners' => ['type' => 'array', 'items' => self::POINT]],
            ],
            'pieces' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['kind', 'corners', 'thickness_mm'],
                    'properties' => [
                        'kind' => ['type' => 'string', 'enum' => ['plank', 'batten', 'plywood', 'sheet_metal', 'pallet_board', 'other']],
                        'thickness_mm' => ['type' => 'number'],
                        'corners' => ['type' => 'array', 'items' => self::POINT, 'minItems' => 4, 'maxItems' => 4],
                    ],
                ],
            ],
        ],
    ];

    public const INSTRUCTIONS = 'You analyse a top-down photo of reclaimed building materials. Coordinates use the normalized '
        . '0-1000 grid of the image: x from left (0) to right (1000), y from top (0) to bottom (1000). '
        . '1. Find the white A4 sheet of paper used as the scale reference. Give its 4 corners in order '
        . 'around the sheet. If there is no A4 sheet, found=false and corners=[]. '
        . '2. List every separate piece of material (wood planks, battens, plywood, pallet boards, '
        . 'corrugated or flat metal sheets). Never include the A4 sheet, tools or the floor. For each piece '
        . "give its kind and the 4 corners of the tightest rotated rectangle around it, in order around the "
        . 'piece, following the piece\'s own orientation when it is rotated. Corners must lie on the piece edges. '
        . '3. Estimate each piece\'s thickness in millimetres from its visible edges, end grain, shadows and kind, '
        . 'using the A4 sheet for scale (usual values: plank 18-27, batten 20-45, plywood 5-18, pallet board 15-22, '
        . 'sheet metal 0.4-1).';

    /**
     * Sends the photo to the vision model and returns its JSON answer (normalized coordinates).
     *
     * @return array<string, mixed>
     *
     * @throws VisionError timeout / server / invalid_json
     */
    public static function analyse(string $jpeg, Config $config): array
    {
        $messages = [
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => self::INSTRUCTIONS],
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . base64_encode($jpeg)]],
                ],
            ],
        ];

        return self::ask($messages, 'Scan', self::SCHEMA, $config);
    }

    /**
     * One photo and a question, answered in a JSON schema (the reverse build reads a
     * finished object this way).
     *
     * @param array<string, mixed> $schema
     *
     * @throws VisionError timeout / server / invalid_json
     */
    public static function look(string $jpeg, string $text, array $schema, string $name, Config $config): array
    {
        $messages = [
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $text],
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . base64_encode($jpeg)]],
                ],
            ],
        ];

        return self::ask($messages, $name, $schema, $config);
    }

    /**
     * One text turn with a JSON schema answer: the plan, the project sizes, the steps.
     *
     * @param array<string, mixed> $schema
     *
     * @throws VisionError timeout / server / invalid_json
     */
    public static function complete(string $text, array $schema, string $name, Config $config): array
    {
        $messages = [['role' => 'user', 'content' => [['type' => 'text', 'text' => $text]]]];

        return self::ask($messages, $name, $schema, $config);
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     *
     * @throws VisionError
     */
    private static function ask(array $messages, string $name, array $schema, Config $config): array
    {
        if ($config->metaApiKey === '') {
            throw new VisionError('server', 'No Meta API key configured (MODEL_API_KEY in the repository .env).');
        }

        $body = [
            'model' => $config->visionModel,
            'messages' => $messages,
            'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => $name, 'schema' => $schema]],
        ];

        $client = curl_init(rtrim($config->metaApiBase, '/') . '/chat/completions');
        if ($client === false) {
            throw new VisionError('server', 'curl_init failed');
        }
        curl_setopt_array($client, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $config->metaApiKey,
            ],
            CURLOPT_TIMEOUT => $config::VISION_TIMEOUT_S,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);

        $response = curl_exec($client);
        if ($response === false) {
            $error = curl_error($client);
            $errno = curl_errno($client);
            curl_close($client);
            if ($errno === CURLE_OPERATION_TIMEDOUT) {
                throw new VisionError('timeout', $error);
            }
            throw new VisionError('server', $error);
        }

        $status = (int) curl_getinfo($client, CURLINFO_RESPONSE_CODE);
        curl_close($client);

        if ($status >= 400) {
            throw new VisionError('server', 'Vision model answered HTTP ' . $status . ': ' . substr((string) $response, 0, 300));
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            throw new VisionError('invalid_json', 'the model answer is not JSON');
        }
        $content = $decoded['choices'][0]['message']['content'] ?? null;
        if (!is_string($content)) {
            throw new VisionError('invalid_json', 'the model answer has no message content');
        }

        $model = json_decode($content, true);
        if (!is_array($model)) {
            throw new VisionError('invalid_json', 'the content is not a JSON object');
        }

        return $model;
    }
}