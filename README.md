# Laravel Marauder

A Zed extension that provides go-to-definition for Laravel Blade views.

## Features

- **Cmd+click** on `view('...')` calls to jump directly to the Blade template file
- **Cmd+click** on `Route::view()` view names to jump to the Blade template
- Works with dot-notation view names (e.g., `view('pages.home')` → `resources/views/pages/home.blade.php`)

## Installation

Install from the Zed extension marketplace by searching for "Laravel Marauder".

## Requirements

- PHP 8.0+ installed and available in your PATH
- A Laravel project with views in `resources/views/`

## Usage

In any PHP file, hold Cmd (macOS) or Ctrl (Linux) and click on a view name:

```php
return view('dashboard.index');
//          ^^^^^^^^^^^^^^^^^ Cmd+click to open resources/views/dashboard/index.blade.php

Route::view('/', 'home');
//               ^^^^^^ Cmd+click to open resources/views/home.blade.php
```

## License

MIT
