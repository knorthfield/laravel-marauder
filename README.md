# Laravel Marauder

A Zed extension that provides go-to-definition and auto-completion for Laravel Blade views.

## Features

- **Go-to-definition**: Cmd+click on `view('...')` or `Route::view()` to jump to the Blade template
- **Auto-completion**: Get suggestions for view names as you type in `view()` or `Route::view()` calls
- Works with dot-notation view names (e.g., `view('pages.home')` → `resources/views/pages/home.blade.php`)

## Installation

Install from the Zed extension marketplace by searching for "Laravel Marauder".

## Requirements

- PHP 8.0+ installed and available in your PATH
- A Laravel project with views in `resources/views/`

## Usage

### Go-to-definition

In any PHP file, hold Cmd (macOS) or Ctrl (Linux) and click on a view name:

```php
return view('dashboard.index');
//          ^^^^^^^^^^^^^^^^^ Cmd+click to open resources/views/dashboard/index.blade.php

Route::view('/', 'home');
//               ^^^^^^ Cmd+click to open resources/views/home.blade.php
```

### Auto-completion

Start typing a view name inside `view()` or `Route::view()` to get suggestions:

```php
return view('dash|');  // Shows: dashboard, dashboard.index, dashboard.settings, etc.
```

## License

MIT
