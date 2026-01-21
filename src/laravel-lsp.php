#!/usr/bin/env php
<?php

/**
 * Laravel Marauder - Zed Extension
 * Provides go-to-definition, auto-completion, and diagnostics for Laravel Blade views.
 */

class LaravelMarauder
{
    private const VERSION = '0.1.0';
    private const VIEW_PATTERN = '/\bview\s*\(\s*[\'"]([^\'"]+)[\'"]/';
    private const ROUTE_VIEW_PATTERN = '/Route\s*::\s*view\s*\(\s*[\'"][^\'"]*[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]/';
    private const VIEW_COMPLETION_PATTERN = '/\bview\s*\(\s*[\'"]([^\'"]*)/';
    private const ROUTE_VIEW_COMPLETION_PATTERN = '/Route\s*::\s*view\s*\(\s*[\'"][^\'"]*[\'"]\s*,\s*[\'"]([^\'"]*)/';

    private string $rootPath = '';
    private array $documents = [];
    private ?array $viewCache = null;

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

    private function notify(string $method, array $params): void
    {
        $this->send([
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
        ]);
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
            'textDocument/completion' => $this->completion($params),
            'textDocument/codeAction' => $this->codeAction($params),
            'workspace/executeCommand' => $this->executeCommand($params),
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
                'completionProvider' => [
                    'triggerCharacters' => ["'", '"', '.'],
                ],
                'codeActionProvider' => true,
                'executeCommandProvider' => [
                    'commands' => ['laravel-marauder.createView'],
                ],
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

        if (str_contains($uri, '.blade.php')) {
            $this->viewCache = null;
        }

        $this->publishDiagnostics($uri, $text);

        return null;
    }

    private function didClose(array $params): null
    {
        $uri = $params['textDocument']['uri'] ?? '';

        unset($this->documents[$uri]);

        $this->notify('textDocument/publishDiagnostics', [
            'uri' => $uri,
            'diagnostics' => [],
        ]);

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

        if (str_contains($uri, '.blade.php')) {
            $this->viewCache = null;
        }

        if (isset($this->documents[$uri])) {
            $this->publishDiagnostics($uri, $this->documents[$uri]);
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

    private function completion(array $params): ?array
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

        $line = substr($lines[$lineNumber], 0, $character);

        return $this->getViewCompletions($line);
    }

    private function publishDiagnostics(string $uri, string $content): void
    {
        $diagnostics = [];
        $lines = explode("\n", $content);
        $patterns = [
            self::VIEW_PATTERN,
            self::ROUTE_VIEW_PATTERN,
        ];

        foreach ($lines as $lineNumber => $line) {
            foreach ($patterns as $pattern) {
                if (!preg_match_all($pattern, $line, $matches, PREG_OFFSET_CAPTURE)) {
                    continue;
                }

                foreach ($matches[1] as [$viewName, $offset]) {
                    if ($this->resolveViewPath($viewName) === null) {
                        $diagnostics[] = [
                            'range' => [
                                'start' => ['line' => $lineNumber, 'character' => $offset],
                                'end' => ['line' => $lineNumber, 'character' => $offset + strlen($viewName)],
                            ],
                            'severity' => 2, // Warning
                            'source' => 'laravel-marauder',
                            'message' => "View '{$viewName}' not found",
                            'data' => ['viewName' => $viewName],
                        ];
                    }
                }
            }
        }

        $this->notify('textDocument/publishDiagnostics', [
            'uri' => $uri,
            'diagnostics' => $diagnostics,
        ]);
    }

    private function codeAction(array $params): array
    {
        $diagnostics = $params['context']['diagnostics'] ?? [];
        $actions = [];

        foreach ($diagnostics as $diagnostic) {
            if (($diagnostic['source'] ?? '') !== 'laravel-marauder') {
                continue;
            }

            $viewName = $diagnostic['data']['viewName'] ?? null;
            if ($viewName === null) {
                continue;
            }

            $actions[] = [
                'title' => "Create view '{$viewName}'",
                'kind' => 'quickfix',
                'diagnostics' => [$diagnostic],
                'command' => [
                    'title' => "Create view '{$viewName}'",
                    'command' => 'laravel-marauder.createView',
                    'arguments' => [$viewName],
                ],
            ];
        }

        return $actions;
    }

    private function executeCommand(array $params): ?array
    {
        $command = $params['command'] ?? '';
        $arguments = $params['arguments'] ?? [];

        if ($command === 'laravel-marauder.createView' && isset($arguments[0])) {
            $viewName = $arguments[0];
            $this->createViewFile($viewName);
        }

        return null;
    }

    private function createViewFile(string $viewName): void
    {
        $viewPath = $this->getViewFilePath($viewName);
        $directory = dirname($viewPath);

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        if (!file_exists($viewPath)) {
            file_put_contents($viewPath, $this->getViewTemplate($viewName));
            $this->viewCache = null;
        }
    }

    private function getViewFilePath(string $viewName): string
    {
        $relativePath = str_replace('.', '/', $viewName);
        return $this->rootPath . '/resources/views/' . $relativePath . '.blade.php';
    }

    private function getViewTemplate(string $viewName): string
    {
        $title = str_replace(['.', '-', '_'], ' ', $viewName);
        $title = ucwords($title);

        return <<<BLADE
{{-- {$viewName} --}}

BLADE;
    }

    private function getViewCompletions(string $line): ?array
    {
        $patterns = [
            self::ROUTE_VIEW_COMPLETION_PATTERN,
            self::VIEW_COMPLETION_PATTERN,
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line, $matches)) {
                $prefix = $matches[1] ?? '';
                return $this->buildCompletionList($prefix);
            }
        }

        return null;
    }

    private function buildCompletionList(string $prefix): array
    {
        $views = $this->getAvailableViews();
        $items = [];

        foreach ($views as $viewName) {
            if ($prefix === '' || str_starts_with($viewName, $prefix)) {
                $items[] = [
                    'label' => $viewName,
                    'kind' => 17, // File
                    'insertText' => $viewName,
                ];
            }
        }

        return $items;
    }

    private function getAvailableViews(): array
    {
        if ($this->viewCache !== null) {
            return $this->viewCache;
        }

        $viewsPath = $this->rootPath . '/resources/views';
        if (!is_dir($viewsPath)) {
            return [];
        }

        $this->viewCache = $this->scanViewDirectory($viewsPath, '');

        return $this->viewCache;
    }

    private function scanViewDirectory(string $basePath, string $prefix): array
    {
        $views = [];
        $items = scandir($basePath);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $fullPath = $basePath . '/' . $item;

            if (is_dir($fullPath)) {
                $newPrefix = $prefix === '' ? $item : $prefix . '.' . $item;
                $views = array_merge($views, $this->scanViewDirectory($fullPath, $newPrefix));
            } elseif (str_ends_with($item, '.blade.php')) {
                $viewName = substr($item, 0, -10); // Remove .blade.php
                $views[] = $prefix === '' ? $viewName : $prefix . '.' . $viewName;
            }
        }

        return $views;
    }

    private function findViewDefinition(string $line, int $character): ?array
    {
        $patterns = [
            self::VIEW_PATTERN,
            self::ROUTE_VIEW_PATTERN,
        ];

        foreach ($patterns as $pattern) {
            if (!preg_match_all($pattern, $line, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[1] as [$viewName, $offset]) {
                $isWithinRange = $character >= $offset && $character <= $offset + strlen($viewName);

                if (!$isWithinRange) {
                    continue;
                }

                $viewPath = $this->resolveViewPath($viewName);
                if ($viewPath === null) {
                    $this->createViewFile($viewName);
                    $viewPath = $this->getViewFilePath($viewName);
                }

                return $this->createLocationResponse($viewPath);
            }
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
