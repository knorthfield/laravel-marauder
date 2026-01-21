#!/usr/bin/env php
<?php

/**
 * Laravel Marauder - Zed Extension
 * Provides go-to-definition for view() calls in Laravel projects.
 */

class LaravelMarauder
{
    private const VERSION = '0.1.0';
    private const VIEW_PATTERN = '/\bview\s*\(\s*[\'"]([^\'"]+)[\'"]/';

    private string $rootPath = '';
    private array $documents = [];

    public function run(): void
    {
        while (true) {
            $headers = $this->readHeaders();
            if ($headers === null) {
                break;
            }

            $length = (int) ($headers['Content-Length'] ?? 0);
            $body = $this->readBody($length);
            if ($body === null) {
                break;
            }

            $request = json_decode(trim($body), true);
            if ($request === null) {
                continue;
            }

            $response = $this->handleRequest($request);
            if ($response !== null) {
                $this->send($response);
            }
        }
    }

    private function readHeaders(): ?array
    {
        $headers = [];

        while (($line = fgets(STDIN)) !== false) {
            $line = trim($line);
            if ($line === '') {
                break;
            }

            if (preg_match('/^([^:]+):\s*(.+)$/', $line, $matches)) {
                $headers[$matches[1]] = $matches[2];
            }
        }

        return empty($headers) ? null : $headers;
    }

    private function readBody(int $length): ?string
    {
        if ($length <= 0) {
            return '';
        }

        return fread(STDIN, $length);
    }

    private function send(array $message): void
    {
        $json = json_encode($message);
        $output = sprintf("Content-Length: %d\r\n\r\n%s", strlen($json), $json);

        fwrite(STDOUT, $output);
        fflush(STDOUT);
    }

    private function handleRequest(array $request): ?array
    {
        $method = $request['method'] ?? '';
        $id = $request['id'] ?? null;
        $params = $request['params'] ?? [];

        if ($method === 'exit') {
            exit(0);
        }

        $result = match ($method) {
            'initialize' => $this->initialize($params),
            'shutdown' => [],
            'textDocument/didOpen' => $this->didOpen($params),
            'textDocument/didClose' => $this->didClose($params),
            'textDocument/didChange' => $this->didChange($params),
            'textDocument/definition' => $this->definition($params),
            default => null,
        };

        if ($id === null) {
            return null;
        }

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ];
    }

    private function initialize(array $params): array
    {
        $this->rootPath = $this->uriToPath($params['rootUri'] ?? '');

        return [
            'capabilities' => [
                'definitionProvider' => true,
                'textDocumentSync' => [
                    'openClose' => true,
                    'change' => 1,
                ],
            ],
            'serverInfo' => [
                'name' => 'laravel-marauder',
                'version' => self::VERSION,
            ],
        ];
    }

    private function didOpen(array $params): null
    {
        $uri = $params['textDocument']['uri'] ?? '';
        $text = $params['textDocument']['text'] ?? '';

        $this->documents[$uri] = $text;

        return null;
    }

    private function didClose(array $params): null
    {
        $uri = $params['textDocument']['uri'] ?? '';

        unset($this->documents[$uri]);

        return null;
    }

    private function didChange(array $params): null
    {
        $uri = $params['textDocument']['uri'] ?? '';
        $changes = $params['contentChanges'] ?? [];

        foreach ($changes as $change) {
            if (isset($change['text'])) {
                $this->documents[$uri] = $change['text'];
            }
        }

        return null;
    }

    private function definition(array $params): ?array
    {
        $uri = $params['textDocument']['uri'] ?? '';
        $lineNumber = $params['position']['line'] ?? 0;
        $character = $params['position']['character'] ?? 0;

        $content = $this->documents[$uri] ?? $this->readFile($uri);
        if ($content === null) {
            return null;
        }

        $lines = explode("\n", $content);
        if (!isset($lines[$lineNumber])) {
            return null;
        }

        return $this->findViewDefinition($lines[$lineNumber], $character);
    }

    private function findViewDefinition(string $line, int $character): ?array
    {
        if (!preg_match_all(self::VIEW_PATTERN, $line, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        foreach ($matches[1] as [$viewName, $offset]) {
            $isWithinRange = $character >= $offset && $character <= $offset + strlen($viewName);

            if (!$isWithinRange) {
                continue;
            }

            $viewPath = $this->resolveViewPath($viewName);
            if ($viewPath === null) {
                continue;
            }

            return $this->createLocationResponse($viewPath);
        }

        return null;
    }

    private function createLocationResponse(string $path): array
    {
        return [
            'uri' => 'file://' . $path,
            'range' => [
                'start' => ['line' => 0, 'character' => 0],
                'end' => ['line' => 0, 'character' => 0],
            ],
        ];
    }

    private function resolveViewPath(string $viewName): ?string
    {
        $relativePath = str_replace('.', '/', $viewName);
        $fullPath = $this->rootPath . '/resources/views/' . $relativePath . '.blade.php';

        return file_exists($fullPath) ? $fullPath : null;
    }

    private function readFile(string $uri): ?string
    {
        $path = $this->uriToPath($uri);

        if (!file_exists($path)) {
            return null;
        }

        return file_get_contents($path);
    }

    private function uriToPath(string $uri): string
    {
        if (!str_starts_with($uri, 'file://')) {
            return $uri;
        }

        return urldecode(substr($uri, 7));
    }
}

(new LaravelMarauder())->run();
