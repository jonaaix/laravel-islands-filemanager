<p align="center">
  <a href="https://github.com/jonaaix/laravel-islands-filemanager">
    <img src="https://raw.githubusercontent.com/jonaaix/laravel-islands-filemanager/main/laravel-islands-filemanager.svg" alt="Laravel Islands File Manager Logo" width="200">
  </a>
</p>

<h1 align="center">Laravel Islands File Manager</h1>

<p align="center">
A Finder-like file manager for <a href="https://github.com/jonaaix/laravel-islands">Laravel Islands</a>: folders, list and thumbnail views, an info column with notes and comments, Quick Look, keyboard shortcuts and full-text search — including Office files and scanned documents.
</p>

<p align="center">
  <a href="https://packagist.org/packages/aaix/laravel-islands-filemanager"><img src="https://img.shields.io/packagist/v/aaix/laravel-islands-filemanager.svg?style=flat-square" alt="Latest Version on Packagist"></a>
  <a href="https://packagist.org/packages/aaix/laravel-islands-filemanager"><img src="https://img.shields.io/packagist/dt/aaix/laravel-islands-filemanager.svg?style=flat-square" alt="Total Downloads"></a>
</p>

---

The package ships one page: a window in the manner of the macOS Finder, filling the content area of
your layout. Everyone allowed in works on the same shared folders. The folder tree is virtual — it
lives in the database, while every file is stored in a directory of its own.

- **Window** — folder tree, content, info column and status line; tree and info column resize and remember their width per user
- **Views** — a list (name, owner, modified, added, size) with in-place folder expansion, or thumbnails with a size slider; view, size and sort order are remembered per user and folder
- **Finder interaction** — click, ⌘/Ctrl- and Shift-selection, context menus, inline rename, drag and drop to move, a new folder from the selection, Quick Look on <kbd>Space</kbd>
- **Upload** — drop files or whole folders from the desktop onto the window or straight onto a folder; the folder structure is rebuilt
- **Notes and comments** — one notes field and a comment thread per file
- **Search** — through the open folder and everything below it, several words at once, matches by name kept apart from matches inside files
- **Full text** — PDFs, plain text, Word, Excel, PowerPoint and OpenDocument files; scanned PDFs and images through text recognition
- **Thumbnails** — for images and the first page of PDFs
- **Downloads** — single files, several files one by one, or folders and selections as a ZIP that keeps the folder structure
- **Global search** — two ready sources for [Laravel Islands Search](https://github.com/jonaaix/laravel-islands-search); a file hit opens straight in Quick Look

## Requirements

- PHP 8.3+, Laravel 12 or 13
- `aaix/laravel-islands`, `aaix/laravel-islands-datagrid` and `aaix/laravel-islands-search`
- The PHP `zip` extension and Imagick (thumbnails through Intervention Image)
- The poppler tools `pdftotext` and `pdftoppm` for PDF text and thumbnails
- Optional: `tesseract` with the language packs you need, for scanned PDFs and images
- A queue worker — text is extracted in the background after each upload

On Debian or Ubuntu:

```bash
apt-get install poppler-utils tesseract-ocr tesseract-ocr-deu tesseract-ocr-eng
```

## Installation

```bash
composer require aaix/laravel-islands-filemanager
```

Publish and run the migration, and publish the configuration:

```bash
php artisan vendor:publish --tag=filemanager-migrations
php artisan vendor:publish --tag=filemanager-config
php artisan migrate
```

Add the Vite plugin — it registers the import name `@aaix/laravel-islands-filemanager` and, when the
package is installed from a Composer path repository, points it at the working copy:

```js
// vite.config.js
import islandsFilemanager from './vendor/aaix/laravel-islands-filemanager/vite.js';

export default defineConfig({
    plugins: [/* … */ islandsFilemanager()],
});
```

Register the package's island next to your own and let Tailwind see its classes:

```js
// resources/js/app.js
import islands from '@aaix/laravel-islands/islands';
import { startVueIslands } from '@aaix/laravel-islands/vue';
import { filemanagerIslands } from '@aaix/laravel-islands-filemanager';

startVueIslands({ ...islands, ...filemanagerIslands });
```

```css
@source '../../vendor/aaix/laravel-islands-filemanager/resources/js/**/*';
```

Set a salt for the storage directories in `.env`. It must never change once files are stored:

```dotenv
FILEMANAGER_HASH_SALT=a-long-random-string
```

## Access

Every page and endpoint asks Laravel's Gate for the ability named in `filemanager.ability`. Define it
in your application:

```php
// AppServiceProvider::boot()
Gate::define('filemanager.access', fn (User $user): bool => $user->hasRole('office'));
```

Set the ability to `null` to let every signed-in user in. Further middleware — `verified`, your own
area checks — goes into `filemanager.routes.middleware`.

## The page

The file manager answers under `/files` (route `filemanager.index`). It renders the view named in
`filemanager.page.view` with these variables:

| Variable | Content |
| --- | --- |
| `$title` | the page title (`filemanager.page.title`) |
| `$island` | the island name, `FileManager` |
| `$islandProps` | the island's start payload |
| `$flush` | `true` — the window wants the whole content area, without padding |

The default view is a bare HTML page. To place the file manager inside your layout, point the
setting at a view of your own:

```blade
{{-- resources/views/files.blade.php --}}
<x-layouts.app :title="$title">
    <x-island :name="$island" :props="$islandProps" />
</x-layouts.app>
```

```php
'page' => ['view' => 'files', 'title' => 'Files'],
```

The window measures the space below its top edge and takes all of it, so it belongs directly into
the content area, not into a padded card.

## Global search

With Laravel Islands Search, add the two sources to your search endpoint. Files and folders are
found by name and notes; files whose text contains the words follow in a group of their own:

```php
use Aaix\LaravelIslandsFilemanager\Search\FileContentSearchSource;
use Aaix\LaravelIslandsFilemanager\Search\FileSearchSource;

IslandsSearch::route('search', [
    FileSearchSource::class,
    FileContentSearchSource::class,
])->name('search');
```

Both sources respect the access ability.

## Text extraction

After every upload a queued job reads the file's text so search can find it:

| Files | Read with |
| --- | --- |
| PDF | `pdftotext`; pages without text fall back to text recognition |
| Word, Excel, PowerPoint, OpenDocument | the document's XML, no extra tools |
| Plain text | as is |
| Images | text recognition |

Text recognition needs `tesseract` and reads at most the first ten pages of a scan, so the job stays
below a queue `retry_after` of 90 seconds. Without `tesseract` installed, scans and images are simply
found by name and notes. Choose the languages with `filemanager.ocr_languages` (`deu+eng`, …).

Read the text of files uploaded before the package could extract it:

```bash
php artisan filemanager:extract-text        # files without text
php artisan filemanager:extract-text --all  # every file again
```

## Storage

Folders exist only in the database. Every file is stored in a directory of its own on the configured
disk:

```text
<environment>/<files table>/<h1>/<h2>/<h3>/<rest>/original.<ext>
                                              /thumbnail.webp
```

`<h1>…<rest>` is the md5 of the salt and the file's id, so directories cannot be guessed. The full
path is stored with the file and stays authoritative. Moving or renaming never touches the disk; a
readable folder structure comes out of the ZIP download. A backup needs both, the database and the
disk.

## Configuration

| Key | Default | Meaning |
| --- | --- | --- |
| `ability` | `filemanager.access` | Gate ability guarding everything; `null` for every signed-in user |
| `user_model` | auth provider model | owner of files, folders and comments; its `name` is shown |
| `user_avatar_attribute` | `null` | attribute with a profile picture URL for the owner and author chips; `null` shows the initial |
| `table_prefix` | `filemanager_` | prefix of the package's tables |
| `disk` | `local` | storage disk for files and thumbnails |
| `hash_salt` | `FILEMANAGER_HASH_SALT` | salt for the storage directories |
| `routes.prefix` / `routes.name` / `routes.middleware` | `files` / `filemanager.` / `web`, `auth` | where the page and its endpoints live |
| `page.view` / `page.title` | `filemanager::page` / `Files` | the hosting view and its title |
| `extract_text` | `true` | read file text after upload |
| `ocr` / `ocr_languages` | `true` / `eng` | text recognition for scans and images |
| `max_upload_kilobytes` | `512000` | largest accepted file |

## Keyboard

| Action | Keys |
| --- | --- |
| Move the selection / extend it | Arrow keys / <kbd>Shift</kbd> + arrow keys |
| Open / close a folder in the list | <kbd>→</kbd> / <kbd>←</kbd> |
| Select all | <kbd>⌘A</kbd> |
| Open | <kbd>⌘↓</kbd> or double-click |
| Enclosing folder | <kbd>⌘↑</kbd> |
| Quick Look | <kbd>Space</kbd> |
| Rename | <kbd>Enter</kbd> |
| Delete | <kbd>⌘⌫</kbd> or <kbd>Delete</kbd> |
| New folder | <kbd>⌘⇧N</kbd> or <kbd>⌘⌥N</kbd> |
| New folder with the selection | <kbd>⌃⌘N</kbd> (<kbd>Ctrl Alt Shift N</kbd> on Windows and Linux) |
| Upload files | <kbd>⌘⌥U</kbd> |
| List / thumbnails | <kbd>⌥1</kbd> / <kbd>⌥2</kbd> |
| Search in the folder | <kbd>/</kbd> |
| Shortcut overview | <kbd>?</kbd> |

On Windows and Linux <kbd>Ctrl</kbd> takes the place of <kbd>⌘</kbd> and <kbd>Alt</kbd> that of
<kbd>⌥</kbd>. Browsers that keep a shortcut for themselves win — Chrome opens a private window on <kbd>⌘⇧N</kbd> —
which is why new folder also answers to <kbd>⌘⌥N</kbd>.
Typing the first letters of a name selects it.

## Testing

The package tests run on their own, with Orchestra Testbench and an in-memory SQLite database:

```bash
composer install
composer test
```

The text recognition test is skipped where `tesseract` is not installed.

## License

The MIT License (MIT). See [LICENSE](LICENSE.md) for more information.
