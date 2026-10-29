<p align="center">
  <a href="https://github.com/jonaaix/laravel-islands-filemanager">
    <img src="https://raw.githubusercontent.com/jonaaix/laravel-islands-filemanager/main/laravel-islands-filemanager.svg" alt="Laravel Islands File Manager Logo" width="200">
  </a>
</p>

<h1 align="center">Laravel Islands File Manager</h1>

<p align="center">
A file manager for <a href="https://github.com/jonaaix/laravel-islands">Laravel Islands</a>: folders, list and thumbnail views, an info column with notes and comments, previews, keyboard shortcuts and full-text search — including Office files and scanned documents.
</p>

<p align="center">
  <a href="https://packagist.org/packages/aaix/laravel-islands-filemanager"><img src="https://img.shields.io/packagist/v/aaix/laravel-islands-filemanager.svg?style=flat-square" alt="Latest Version on Packagist"></a>
  <a href="https://packagist.org/packages/aaix/laravel-islands-filemanager"><img src="https://img.shields.io/packagist/dt/aaix/laravel-islands-filemanager.svg?style=flat-square" alt="Total Downloads"></a>
</p>

---

A file manager as one Laravel Islands page: folder tree, list and thumbnail views, notes and
comments, previews, keyboard shortcuts, folder uploads and full-text search — including Office
files and scanned documents.

## Requirements

- PHP 8.3+, Laravel 12 or 13, a queue worker
- `aaix/laravel-islands`, `aaix/laravel-islands-datagrid`, `aaix/laravel-islands-search`
- PHP `zip` and Imagick, the poppler tools; optionally `tesseract` for scans

```bash
apt-get install poppler-utils tesseract-ocr tesseract-ocr-deu tesseract-ocr-eng
```

## Installation

```bash
composer require aaix/laravel-islands-filemanager
php artisan vendor:publish --tag=filemanager-migrations
php artisan vendor:publish --tag=filemanager-config
php artisan migrate
```

```js
// vite.config.js
import islandsFilemanager from './vendor/aaix/laravel-islands-filemanager/vite.js';

export default defineConfig({
    plugins: [/* … */ islandsFilemanager()],
});
```

```js
// resources/js/app.js
import { filemanagerIslands } from '@aaix/laravel-islands-filemanager';

startVueIslands({ ...islands, ...filemanagerIslands });
```

```css
@source '../../vendor/aaix/laravel-islands-filemanager/resources/js/**/*';
```

```dotenv
FILEMANAGER_HASH_SALT=a-long-random-string   # never change once files are stored
```

## Setup

Allow access through a gate (set `filemanager.ability` to `null` to let every signed-in user in):

```php
Gate::define('filemanager.access', fn (User $user): bool => $user->hasRole('office'));
```

Place the page inside your layout — it answers under `/files` and renders `filemanager.page.view`
with `$island` and `$islandProps`:

```blade
{{-- resources/views/files.blade.php, config: 'page' => ['view' => 'files'] --}}
<x-layouts.app :title="$title">
    <x-island :name="$island" :props="$islandProps" />
</x-layouts.app>
```

Optionally add the sources to your [Laravel Islands Search](https://github.com/jonaaix/laravel-islands-search) endpoint:

```php
IslandsSearch::route('search', [FileSearchSource::class, FileContentSearchSource::class]);
```

Drives are Laravel disks listed under `volumes` in `config/filemanager.php`; each appears in the
folder tree and may ask for a Gate ability to be seen and another to be changed (read-only otherwise).
Copying and moving between drives can be restricted with `transfer_ability`:

```php
'volumes' => [
    'files' => ['name' => 'Files', 'disk' => 'filemanager'],
    'archive' => ['name' => 'Archive', 'disk' => 's3-archive', 'ability' => 'view-archive', 'write_ability' => 'edit-archive'],
],
'transfer_ability' => null,
```

Thumbnails and extracted text are kept on the same disk in a hidden `.filemanager` folder. Route
prefix, middleware, OCR languages and more live in the same config file.

## License

The MIT License (MIT). See [LICENSE](LICENSE.md) for more information.

