<?php

declare(strict_types=1);

/**
 * A stand-in for a local model server (Ollama, llama.cpp), for the tests only: it speaks
 * the same chat completions protocol and answers with what it received, so a test can
 * read which model, path and key a request used. Started by tests/plan_test.php.
 */

$body = json_decode((string) file_get_contents('php://input'), true);

header('Content-Type: application/json');
echo json_encode(['choices' => [['message' => ['content' => json_encode([
    'path' => parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH),
    'model' => $body['model'] ?? null,
    'auth' => $_SERVER['HTTP_AUTHORIZATION'] ?? '',
    'schema' => $body['response_format']['json_schema']['name'] ?? null,
])]]]]);
