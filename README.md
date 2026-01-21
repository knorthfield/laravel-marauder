# Laravel Marauder

A Zed extension that provides intelligent Blade view support for Laravel projects.

## Features

- **Go-to-definition**: Cmd+click on `view('...')` or `Route::view()` to jump to the Blade template (creates it if missing)
- **Auto-completion**: Get suggestions for view names as you type
- **Diagnostics**: Warnings when referencing views that don't exist
- **Quick fix**: Create missing view files with one click
- Works with dot-notation view names (e.g., `view('pages.home')` → `resources/views/pages/home.blade.php`)

## Installation

Install from the Zed extension marketplace by searching for "Laravel Marauder".

## Requirements

- PHP 8.0+ installed and available in your PATH
- A Laravel project with views in `resources/views/`

## Usage

### Go-to-definition

In any PHP file, hold Cmd (macOS) or Ctrl (Linux) and click on a view name to open it. If the view doesn't exist, it will be created automatically:

```php
return view('dashboard.index');
//          ^^^^^^^^^^^^^^^^^ Cmd+click to open resources/views/dashboard/index.blade.php

Route::view('/', 'home');
//               ^^^^^^ Cmd+click to open resources/views/home.blade.php

return view('new.page');
//          ^^^^^^^^^^ Cmd+click creates resources/views/new/page.blade.php and opens it
```

### Auto-completion

Start typing a view name inside `view()` or `Route::view()` to get suggestions:

```php
return view('dash|');  // Shows: dashboard, dashboard.index, dashboard.settings, etc.
```

### Diagnostics & Quick Fix

When you reference a view that doesn't exist, you'll see a warning. Click on the warning and select "Create view" to automatically create the missing Blade file:

```php
return view('pages.missing');  // ⚠️ Warning: View 'pages.missing' not found
                               // 💡 Quick fix: Create view 'pages.missing'
```

## License

MIT
