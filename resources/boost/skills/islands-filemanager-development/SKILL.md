---
name: islands-filemanager-development
description: Install, wire and adjust the file manager from aaix/laravel-islands-filemanager — access gate, hosting page, global search sources, text extraction and OCR, storage and configuration. Use whenever the file manager is installed, its page or access changes, files must become findable, or code touches the filemanager_* tables, its models or its config.
---

# Working with the File Manager

The package owns everything inside the window: models, tables (`filemanager_*`), endpoints, the
`FileManager` island and its Vue components. The host app only **wires** it. Before changing
anything, find the host's wiring: `config/filemanager.php`, the gate in a service provider, the
hosting view named in `filemanager.page.view`, and the search route if Laravel Islands Search is used.
Never copy package code into the app to change it — change the package or its config.

## Wiring a host app

1. `composer require aaix/laravel-islands-filemanager`, publish `filemanager-migrations` and
   `filemanager-config`, migrate.
2. Vite plugin `vendor/aaix/laravel-islands-filemanager/vite.js`, `filemanagerIslands` merged into
   `startVueIslands`, Tailwind `@source` on the package's `resources/js`.
3. `FILEMANAGER_HASH_SALT` — never changes once files exist (directories are derived from it).
4. Access: `Gate::define(config('filemanager.ability'), …)`. Every page and endpoint asks it; there is
   no other authorisation. Extra middleware (`verified`, area checks) goes into
   `filemanager.routes.middleware`.
5. Page: point `filemanager.page.view` at a view that renders
   `<x-island :name="$island" :props="$islandProps" />` directly in the content area — no padded
   card; the window takes the full height below its top edge (`$flush` is `true`).
6. Navigation and global search are the host's: link `route('filemanager.index')`, add
   `FileSearchSource` and `FileContentSearchSource` to `IslandsSearch::route(...)`.

## Rules that are easy to break

- **Drives are volumes.** `filemanager.volumes` lists Laravel disks; every folder, file and view
  setting carries its `volume`. Code works with a `Location` (volume + folder), never a bare folder id.
  Moving to another volume copies the bytes to the other disk (`VolumeTransfer`); copies are made by
  `ItemCopier`. A volume's `write_ability` makes it read-only for users without it, and
  `transfer_ability` guards copying/moving between volumes. Every changing endpoint checks both —
  keep that when adding one.
- **Two modes.** A `virtual` volume (default) keeps folders in the database and files under hashed
  directories; moving inside it never touches the disk. A `direct` volume mirrors a real directory
  tree below its `root`: `disk_path` on folders and `path` on files are real paths, every write goes
  through `FilePlacement`, and `DirectScanner` reconciles outside changes (on folder open and via
  `filemanager:scan`), re-linking renamed files by `fingerprint`. Never write paths or move files by
  hand — always through `FilePlacement`, which handles both modes and cross-volume moves.
- **Forget, don't delete,** records whose file vanished from a direct volume: `File::forget()` drops
  the record and its helpers without touching anything else on the disk.
- **Helper data lives in `.laravel-islands-filemanager/`** at the root of the volume's disk (thumbnails, extracted
  text), written at upload from the uploaded file — no later download is needed, on any disk.
- **Delete through the package.** Folder deletion must go through `FolderTree::delete()` so stored
  files are removed; deleting rows directly orphans files on disk.
- **Text extraction is queued.** `ExtractFileTextJob` runs after each upload and needs a worker.
  OCR uses `tesseract` if installed, at most ten pages, so the job stays below a 90 s `retry_after`.
  Backfill with `php artisan filemanager:extract-text` (`--all` re-reads everything).
- **Search matches every word**, also against folder names along the path, but one word must hit the
  item itself. Name matches and matches inside files are always separate groups — keep it that way.
- **User data**: owner and author names come from `user_model`'s `name`; pictures from
  `user_avatar_attribute` (null shows the initial).
- **Shortcuts the browser reserves** (Chrome's ⌘⇧N) cannot be taken over; keep a fallback.

## Tests

The package suite runs on Testbench (`composer test` inside the package). A host app keeps only
integration tests for its own wiring: gate mapping, route middleware, search sources.
