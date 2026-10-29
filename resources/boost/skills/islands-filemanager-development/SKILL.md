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

- **The folder tree is virtual.** Folders exist only in the database; each file has its own hashed
  directory, and the stored `path` is authoritative. Never build paths by hand and never move files
  on disk when moving them in the tree.
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
