<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager\Http;

use Aaix\LaravelIslandsFilemanager\FileManager;
use Aaix\LaravelIslandsFilemanager\Island\Presenters\ItemPresenter;
use Aaix\LaravelIslandsFilemanager\Island\Queries\FolderContentsQuery;
use Aaix\LaravelIslandsFilemanager\Island\State\PreferenceStore;
use Aaix\LaravelIslandsFilemanager\Island\State\ViewSettingStore;
use Aaix\LaravelIslandsFilemanager\Island\Writers\FolderWriter;
use Aaix\LaravelIslandsFilemanager\Island\Writers\ItemsWriter;
use Aaix\LaravelIslandsFilemanager\Location;
use Aaix\LaravelIslandsFilemanager\Models\Comment;
use Aaix\LaravelIslandsFilemanager\Models\File;
use Aaix\LaravelIslandsFilemanager\Models\Folder;
use Aaix\LaravelIslandsFilemanager\Services\DirectScanner;
use Aaix\LaravelIslandsFilemanager\Services\FilePlacement;
use Aaix\LaravelIslandsFilemanager\Services\FileStore;
use Aaix\LaravelIslandsFilemanager\Services\FolderPathResolver;
use Aaix\LaravelIslandsFilemanager\Services\FolderTree;
use Aaix\LaravelIslandsFilemanager\Services\ItemCopier;
use Aaix\LaravelIslandsFilemanager\Services\ZipBuilder;
use Aaix\LaravelIslandsFilemanager\Volume;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class FileManagerController extends Controller
{
    public function __construct(
        private readonly FolderContentsQuery $contents,
        private readonly ItemPresenter $presenter,
    ) {}

    // ----- Browsing -----

    public function contents(Request $request, DirectScanner $scanner): JsonResponse
    {
        $user = $this->authorizeAccess($request);
        $location = $this->location($request, $user);
        $scanner->scanFolder($location);

        return response()->json(['data' => $this->contents->contents($location, $user)]);
    }

    public function search(Request $request): JsonResponse
    {
        $user = $this->authorizeAccess($request);
        $validated = $request->validate(['q' => ['required', 'string', 'max:255']]);

        return response()->json(['data' => $this->contents->search($this->location($request, $user), trim($validated['q']))]);
    }

    public function saveView(Request $request, ViewSettingStore $views): JsonResponse
    {
        $user = $this->authorizeAccess($request);

        $validated = $request->validate([
            'mode' => ['required', Rule::in(ViewSettingStore::MODES)],
            'tile_size' => ['required', 'integer', 'between:'.ViewSettingStore::MIN_TILE.','.ViewSettingStore::MAX_TILE],
            'sort_by' => ['required', Rule::in(ViewSettingStore::SORTS)],
            'sort_direction' => ['required', Rule::in(ViewSettingStore::DIRECTIONS)],
        ]);

        $views->save($user, $this->location($request, $user, folderKey: 'folder_id'), [
            'mode' => $validated['mode'],
            'tile_size' => $validated['tile_size'],
            'sort_by' => $validated['sort_by'],
            'sort_direction' => $validated['sort_direction'],
        ]);

        return response()->json(['data' => ['saved' => true]]);
    }

    public function savePreferences(Request $request, PreferenceStore $preferences): JsonResponse
    {
        $user = $this->authorizeAccess($request);

        $validated = $request->validate([
            'info_width' => ['sometimes', 'integer', 'between:'.PreferenceStore::MIN_INFO_WIDTH.','.PreferenceStore::MAX_INFO_WIDTH],
            'tree_width' => ['sometimes', 'integer', 'between:'.PreferenceStore::MIN_TREE_WIDTH.','.PreferenceStore::MAX_TREE_WIDTH],
            'tabs' => ['sometimes', 'array', 'min:1', 'max:'.PreferenceStore::MAX_TABS],
            'tabs.*.volume' => ['required', 'string', 'max:64'],
            'tabs.*.folder_id' => ['nullable', 'integer'],
            'active_tab' => ['required_with:tabs', 'integer', 'min:0'],
        ]);

        if (isset($validated['tabs'])) {
            $preferences->saveTabs($user, array_values($validated['tabs']), $validated['active_tab']);
        }

        $widths = array_intersect_key($validated, array_flip(['info_width', 'tree_width']));

        if ($widths !== []) {
            $preferences->saveWidths($user, $widths);
        }

        return response()->json(['data' => ['saved' => true]]);
    }

    public function folders(Request $request): JsonResponse
    {
        $user = $this->authorizeAccess($request);

        return response()->json(['data' => ['volumes' => $this->contents->tree($user)]]);
    }

    // ----- Folders -----

    public function storeFolder(Request $request, FolderWriter $writer): JsonResponse
    {
        $user = $this->authorizeAccess($request);

        $validated = $request->validate(['name' => ['nullable', 'string', 'max:255']]);

        $location = $this->location($request, $user, folderKey: 'parent_id');
        $this->authorizeWrite($user, $location);
        $folder = $writer->create($validated['name'] ?? null, $location, $user);

        return response()->json(['data' => ['folder' => $this->presenter->folder($folder)]]);
    }

    public function storeFolderWithItems(Request $request, ItemsWriter $writer): JsonResponse
    {
        $user = $this->authorizeAccess($request);
        [$folders, $files] = $this->selectedItems($request, $user);

        $location = $this->location($request, $user, folderKey: 'parent_id');
        $this->authorizeTransfer($user, $folders, $files, $location);
        $folder = $writer->moveIntoNewFolder($folders, $files, $location, $user);

        return response()->json(['data' => ['folder' => $this->presenter->folder($folder)]]);
    }

    public function renameFolder(Request $request, Folder $folder, FolderWriter $writer): JsonResponse
    {
        $this->authorizeWrite($this->authorizeAccess($request), $folder);
        $validated = $request->validate(['name' => ['required', 'string', 'max:255']]);

        $writer->rename($folder, trim($validated['name']));

        return response()->json(['data' => ['folder' => $this->presenter->folder($folder)]]);
    }

    // ----- Several items -----

    public function moveItems(Request $request, ItemsWriter $writer): JsonResponse
    {
        $user = $this->authorizeAccess($request);
        [$folders, $files] = $this->selectedItems($request, $user);

        $target = $this->location($request, $user, 'target_volume', 'target_id');
        $this->authorizeTransfer($user, $folders, $files, $target);
        $writer->move($folders, $files, $target);

        return response()->json(['data' => ['moved' => $folders->count() + $files->count()]]);
    }

    public function estimate(Request $request, FolderTree $tree): JsonResponse
    {
        $user = $this->authorizeAccess($request);
        $validated = $request->validate(['action' => ['required', Rule::in(['move', 'copy', 'rename'])]]);
        [$folders, $files] = $this->selectedItems($request, $user);
        $target = $validated['action'] === 'rename' ? null : $this->location($request, $user, 'target_volume', 'target_id');

        $affected = $files->concat(File::query()->whereIn('folder_id', $folders->flatMap(fn (Folder $folder) => $tree->descendantIds($folder)))->get())
            ->filter(fn (File $file): bool => match ($validated['action']) {
                'copy' => true,
                'move' => $target->volume->key !== $file->getAttribute('volume') || $file->volume()->isDirect(),
                'rename' => $file->volume()->isDirect(),
            });

        $volumes = collect([$target?->volume])->concat($folders->map->volume())->concat($files->map->volume())->filter();

        return response()->json(['data' => [
            'files' => $affected->count(),
            'bytes' => (int) $affected->sum('size'),
            'remote' => $volumes->contains(fn (Volume $volume): bool => ! $volume->isLocal()),
        ]]);
    }

    public function copyItems(Request $request, ItemCopier $copier): JsonResponse
    {
        $user = $this->authorizeAccess($request);
        [$folders, $files] = $this->selectedItems($request, $user);
        $target = $this->location($request, $user, 'target_volume', 'target_id');
        $this->authorizeTransfer($user, $folders, $files, $target, copying: true);

        $copier->copy($folders, $files, $target, $user);

        return response()->json(['data' => ['copied' => $folders->count() + $files->count()]]);
    }

    public function destroyItems(Request $request, ItemsWriter $writer): JsonResponse
    {
        $user = $this->authorizeAccess($request);
        [$folders, $files] = $this->selectedItems($request, $user);
        $this->authorizeWrite($user, ...$folders, ...$files);

        $writer->delete($folders, $files);

        return response()->json(['data' => ['deleted' => $folders->count() + $files->count()]]);
    }

    public function zip(Request $request, ZipBuilder $builder): BinaryFileResponse
    {
        $user = $this->authorizeAccess($request);
        [$folders, $files] = $this->selectedItems($request, $user);

        $name = $folders->count() === 1 && $files->isEmpty() ? $folders->first()->name : __('Files');

        return response()->download($builder->build($folders, $files), $name.'.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    // ----- Files -----

    public function storeFiles(Request $request, FileStore $store, FolderPathResolver $folders): JsonResponse
    {
        $user = $this->authorizeAccess($request);

        $validated = $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:50'],
            'files.*' => ['file', 'max:'.config('filemanager.max_upload_kilobytes')],
            'paths' => ['array'],
            'paths.*' => ['nullable', 'string', 'max:1000'],
        ]);

        $location = $this->location($request, $user, folderKey: 'folder_id');
        $this->authorizeWrite($user, $location);

        foreach ($validated['files'] as $index => $upload) {
            $store->store($upload, $folders->resolve($location, $validated['paths'][$index] ?? '', $user), $user);
        }

        return response()->json(['data' => ['uploaded' => count($validated['files'])]]);
    }

    public function showFile(Request $request, File $file): JsonResponse
    {
        $user = $this->authorizeAccess($request);
        $this->authorizeVolume($user, $file);

        return response()->json(['data' => ['file' => $this->presenter->detail($file, $user)]]);
    }

    public function updateFile(Request $request, File $file, FilePlacement $placement): JsonResponse
    {
        $user = $this->authorizeAccess($request);
        $this->authorizeWrite($user, $file);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:20000'],
        ]);

        if (array_key_exists('notes', $validated)) {
            $file->update(['notes' => $validated['notes'] === null ? null : trim($validated['notes'])]);
        }

        if (isset($validated['name']) && trim($validated['name']) !== $file->name) {
            $placement->moveFile($file, new Location($file->volume(), $file->folder), trim($validated['name']));
        }

        return response()->json(['data' => ['file' => $this->presenter->detail($file, $user)]]);
    }

    public function content(Request $request, File $file): StreamedResponse
    {
        $this->authorizeVolume($this->authorizeAccess($request), $file);

        return $file->mediaDisk()->response($file->path, $file->name, [
            'Content-Type' => $file->mime_type,
            'Cache-Control' => 'private, max-age=3600',
        ], $request->boolean('download') ? 'attachment' : 'inline');
    }

    public function thumbnail(Request $request, File $file): StreamedResponse
    {
        $this->authorizeVolume($this->authorizeAccess($request), $file);

        if ($file->thumbnail_path === null) {
            throw new NotFoundHttpException;
        }

        return $file->mediaDisk()->response($file->thumbnail_path, 'thumbnail.webp', [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'private, max-age=604800',
        ]);
    }

    // ----- Comments -----

    public function storeComment(Request $request, File $file): JsonResponse
    {
        $user = $this->authorizeAccess($request);
        $this->authorizeWrite($user, $file);
        $validated = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        $file->comments()->create(['user_id' => $user->getAuthIdentifier(), 'body' => trim($validated['body'])]);

        return response()->json(['data' => ['file' => $this->presenter->detail($file->unsetRelations(), $user)]]);
    }

    public function destroyComment(Request $request, Comment $comment): JsonResponse
    {
        $user = $this->authorizeAccess($request);

        $this->authorizeWrite($user, $comment->file);

        if ($comment->user_id !== $user->getAuthIdentifier()) {
            throw new AccessDeniedHttpException;
        }

        $comment->delete();

        return response()->json(['data' => ['file' => $this->presenter->detail($comment->file, $user)]]);
    }

    // ----- Support -----

    private function location(Request $request, Authenticatable $user, string $volumeKey = 'volume', string $folderKey = 'folder'): Location
    {
        $validated = $request->validate([$volumeKey => ['nullable', 'string', 'max:64'], $folderKey => ['nullable', 'integer']]);
        $volumes = FileManager::volumesFor($user);
        $volume = $volumes[$validated[$volumeKey] ?? array_key_first($volumes)] ?? throw new NotFoundHttpException;

        if (blank($validated[$folderKey] ?? null)) {
            return new Location($volume);
        }

        return new Location($volume, Folder::query()->where('volume', $volume->key)->findOrFail((int) $validated[$folderKey]));
    }

    /**
     * @return array{0: Collection<int, Folder>, 1: Collection<int, File>}
     */
    private function selectedItems(Request $request, Authenticatable $user): array
    {
        $validated = $request->validate([
            'folders' => ['array'],
            'folders.*' => ['integer'],
            'files' => ['array'],
            'files.*' => ['integer'],
        ]);

        $volumes = array_keys(FileManager::volumesFor($user));
        $folders = Folder::query()->whereIn('volume', $volumes)->whereKey($validated['folders'] ?? [])->orderBy('name')->get();
        $files = File::query()->whereIn('volume', $volumes)->whereKey($validated['files'] ?? [])->orderBy('name')->get();

        if ($folders->isEmpty() && $files->isEmpty()) {
            throw new NotFoundHttpException;
        }

        return [$folders, $files];
    }

    private function authorizeWrite(Authenticatable $user, Location|File|Folder ...$targets): void
    {
        foreach ($targets as $target) {
            $volume = $target instanceof Location ? $target->volume : $target->volume();

            if (! $volume->isWritableBy($user)) {
                throw new AccessDeniedHttpException;
            }
        }
    }

    /**
     * @param  Collection<int, Folder>  $folders
     * @param  Collection<int, File>  $files
     */
    private function authorizeTransfer(Authenticatable $user, Collection $folders, Collection $files, Location $target, bool $copying = false): void
    {
        $this->authorizeWrite($user, $target);

        if (! $copying) {
            $this->authorizeWrite($user, ...$folders, ...$files);
        }

        $leavesVolume = $folders->concat($files)->contains(fn (File|Folder $item): bool => ! $target->isSameVolume($item->getAttribute('volume')));

        if ($leavesVolume && ! FileManager::mayTransfer($user)) {
            throw new AccessDeniedHttpException;
        }
    }

    private function authorizeVolume(Authenticatable $user, File|Folder $item): void
    {
        if (! array_key_exists($item->getAttribute('volume'), FileManager::volumesFor($user))) {
            throw new AccessDeniedHttpException;
        }
    }

    private function authorizeAccess(Request $request): Authenticatable
    {
        $user = $request->user();

        if (! FileManager::allows($user)) {
            throw new AccessDeniedHttpException;
        }

        return $user;
    }
}
