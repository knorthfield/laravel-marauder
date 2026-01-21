#!/usr/bin/env php
<?php

/**
 * Laravel Marauder - Zed Extension
 * Cmd+click on view() to open blade files
 */

class LaravelMarauder
{
    private string $rootPath = '';
    private array $documents = [];

    public function run(): void
    {
        while (true) {
            $headers = $this->readHeaders();
            if ($headers === null) break;

            $length = (int) ($headers['Content-Length'] ?? 0);
            $body = $this->readBody($length);
            if ($body === null) break;

            $request = json_decode(trim($body), true);
            if ($request === null) continue;

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
            if ($line === '') break;
            if (preg_match('/^([^:]+):\s*(.+)$/', $line, $m)) {
                $headers[$m[1]] = $m[2];
            }
        }
        return empty($headers) ? null : $headers;
    }

    private function readBody(int $length): ?string
    {
        return $length > 0 ? fread(STDIN, $length) : '';
    }

    private function send(array $msg): void
    {
        $json = json_encode($msg);
        fwrite(STDOUT, "Content-Length: " . strlen($json) . "\r\n\r\n" . $json);
        fflush(STDOUT);
    }

    private function handleRequest(array $req): ?array
    {
        $method = $req['method'] ?? '';
        $id = $req['id'] ?? null;
        $params = $req['params'] ?? [];

        $result = match ($method) {
            'initialize' => $this->initialize($params),
            'initialized' => null,
            'textDocument/didOpen' => $this->didOpen($params),
            'textDocument/didClose' => $this->didClose($params),
            'textDocument/didChange' => $this->didChange($params),
            'textDocument/definition' => $this->definition($params),
            'workspace/didChangeConfiguration' => null,
            'shutdown' => [],
            'exit' => exit(0),
            default => null,
        };

        if ($id === null) return null;
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
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
            'serverInfo' => ['name' => 'laravel-marauder', 'version' => '0.1.0'],
        ];
    }

    private function didOpen(array $params): null
    {
        $uri = $params['textDocument']['uri'] ?? '';
        $this->documents[$uri] = $params['textDocument']['text'] ?? '';
        return null;
    }

    private function didClose(array $params): null
    {
        unset($this->documents[$params['textDocument']['uri'] ?? '']);
        return null;
    }

    private function didChange(array $params): null
    {
        $uri = $params['textDocument']['uri'] ?? '';
        foreach ($params['contentChanges'] ?? [] as $change) {
            if (isset($change['text'])) {
                $this->documents[$uri] = $change['text'];
            }
        }
        return null;
    }

    private function definition(array $params): ?array
    {
        $uri = $params['textDocument']['uri'] ?? '';
        $line = $params['position']['line'] ?? 0;
        $char = $params['position']['character'] ?? 0;

        $content = $this->documents[$uri] ?? $this->readFile($uri);
        if ($content === null) return null;

        $lines = explode("\n", $content);
        if (!isset($lines[$line])) return null;

        // Match view('...') and view("...")
        if (preg_match_all('/\bview\s*\(\s*[\'"]([^\'"]+)[\'"]/', $lines[$line], $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[1] as [$viewName, $offset]) {
                if ($char >= $offset && $char <= $offset + strlen($viewName)) {
                    $viewPath = $this->resolveView($viewName);
                    if ($viewPath) {
                        return [
                            'uri' => 'file://' . $viewPath,
                            'range' => [
                                'start' => ['line' => 0, 'character' => 0],
                                'end' => ['line' => 0, 'character' => 0],
                            ],
                        ];
                    }
                }
            }
        }

        return null;
    }

    private function resolveView(string $name): ?string
    {
        $path = $this->rootPath . '/resources/views/' . str_replace('.', '/', $name) . '.blade.php';
        return file_exists($path) ? $path : null;
    }

    private function readFile(string $uri): ?string
    {
        $path = $this->uriToPath($uri);
        return file_exists($path) ? file_get_contents($path) : null;
    }

    private function uriToPath(string $uri): string
    {
        return str_starts_with($uri, 'file://') ? urldecode(substr($uri, 7)) : $uri;
    }
}

(new LaravelMarauder())->run();
