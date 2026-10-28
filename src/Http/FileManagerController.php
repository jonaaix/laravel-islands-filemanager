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
use Aaix\LaravelIslandsFilemanager\Models\Comment;
use Aaix\LaravelIslandsFilemanager\Models\File;
use Aaix\LaravelIslandsFilemanager\Models\Folder;
use Aaix\LaravelIslandsFilemanager\Services\FileStore;
use Aaix\LaravelIslandsFilemanager\Services\ZipBuilder;
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

    public function contents(Request $request): JsonResponse
    {
        $user = $this->authorizeAccess($request);
        $request->validate(['folder' => ['nullable', 'integer']]);

        return response()->json(['data' => $this->contents->contents($this->folderOrNull($request->integer('folder') ?: null), $user)]);
    }

    public function search(Request $request): JsonResponse
    {
        $this->authorizeAccess($request);

        $validated = $request->validate([
            'q' => ['required', 'string', 'max:255'],
            'folder' => ['nullable', 'integer'],
        ]);

        return response()->json(['data' => $this->contents->search($this->folderOrNull($validated['folder'] ?? null), trim($validated['q']))]);
    }

    public function saveView(Request $request, ViewSettingStore $views): JsonResponse
    {
        $user = $this->authorizeAccess($request);

        $validated = $request->validate([
            'folder_id' => ['nullable', 'integer', Rule::exists('filemanager_folders', 'id')],
            'mode' => ['required', Rule::in(ViewSettingStore::MODES)],
            'tile_size' => ['required', 'integer', 'between:'.ViewSettingStore::MIN_TILE.','.ViewSettingStore::MAX_TILE],
            'sort_by' => ['required', Rule::in(ViewSettingStore::SORTS)],
            'sort_direction' => ['required', Rule::in(ViewSettingStore::DIRECTIONS)],
        ]);

        $views->save($user, $this->folderOrNull($validated['folder_id'] ?? null), [
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
            'info_width' => ['required_without:tree_width', 'integer', 'between:'.PreferenceStore::MIN_INFO_WIDTH.','.PreferenceStore::MAX_INFO_WIDTH],
            'tree_width' => ['required_without:info_width', 'integer', 'between:'.PreferenceStore::MIN_TREE_WIDTH.','.PreferenceStore::MAX_TREE_WIDTH],
        ]);

        $preferences->saveWidths($user, $validated);

        return response()->json(['data' => ['saved' => true]]);
    }

    public function folders(Request $request): JsonResponse
    {
        $this->authorizeAccess($request);

        return response()->json(['data' => ['folders' => $this->contents->tree()]]);
    }

    // ----- Folders -----

    public function storeFolder(Request $request, FolderWriter $writer): JsonResponse
    {
        $user = $this->authorizeAccess($request);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer', Rule::exists('filemanager_folders', 'id')],
        ]);

        $folder = $writer->create($validated['name'] ?? null, $this->folderOrNull($validated['parent_id'] ?? null), $user);

        return response()->json(['data' => ['folder' => $this->presenter->folder($folder)]]);
    }

    public function renameFolder(Request $request, Folder $folder, FolderWriter $writer): JsonResponse
    {
        $this->authorizeAccess($request);
        $validated = $request->validate(['name' => ['required', 'string', 'max:255']]);

        $writer->rename($folder, trim($validated['name']));

        return response()->json(['data' => ['folder' => $this->presenter->folder($folder)]]);
    }

    // ----- Several items -----

    public function moveItems(Request $request, ItemsWriter $writer): JsonResponse
    {
        $this->authorizeAccess($request);
        [$folders, $files] = $this->selectedItems($request);

        $writer->move($folders, $files, $this->targetFolder($request));

        return response()->json(['data' => ['moved' => $folders->count() + $files->count()]]);
    }

    public function destroyItems(Request $request, ItemsWriter $writer): JsonResponse
    {
        $this->authorizeAccess($request);
        [$folders, $files] = $this->selectedItems($request);

        $writer->delete($folders, $files);

        return response()->json(['data' => ['deleted' => $folders->count() + $files->count()]]);
    }

    public function zip(Request $request, ZipBuilder $builder): BinaryFileResponse
    {
        $this->authorizeAccess($request);
        [$folders, $files] = $this->selectedItems($request);

        $name = $folders->count() === 1 && $files->isEmpty() ? $folders->first()->name : __('Files');

        return response()->download($builder->build($folders, $files), $name.'.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    // ----- Files -----

    public function storeFiles(Request $request, FileStore $store): JsonResponse
    {
        $user = $this->authorizeAccess($request);

        $validated = $request->validate([
            'folder_id' => ['nullable', 'integer', Rule::exists('filemanager_folders', 'id')],
            'files' => ['required', 'array', 'min:1', 'max:50'],
            'files.*' => ['file', 'max:'.config('filemanager.max_upload_kilobytes')],
        ]);

        $folder = $this->folderOrNull($validated['folder_id'] ?? null);

        foreach ($validated['files'] as $upload) {
            $store->store($upload, $folder, $user);
        }

        return response()->json(['data' => ['uploaded' => count($validated['files'])]]);
    }

    public function showFile(Request $request, File $file): JsonResponse
    {
        $user = $this->authorizeAccess($request);

        return response()->json(['data' => ['file' => $this->presenter->detail($file, $user)]]);
    }

    public function updateFile(Request $request, File $file): JsonResponse
    {
        $user = $this->authorizeAccess($request);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:20000'],
        ]);

        $file->update(array_map(fn (?string $value): ?string => $value === null ? null : trim($value), $validated));

        return response()->json(['data' => ['file' => $this->presenter->detail($file, $user)]]);
    }

    public function content(Request $request, File $file): StreamedResponse
    {
        $this->authorizeAccess($request);

        return $file->mediaDisk()->response($file->path, $file->name, [
            'Content-Type' => $file->mime_type,
            'Cache-Control' => 'private, max-age=3600',
        ], $request->boolean('download') ? 'attachment' : 'inline');
    }

    public function thumbnail(Request $request, File $file): StreamedResponse
    {
        $this->authorizeAccess($request);

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
        $validated = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        $file->comments()->create(['user_id' => $user->getAuthIdentifier(), 'body' => trim($validated['body'])]);

        return response()->json(['data' => ['file' => $this->presenter->detail($file->unsetRelations(), $user)]]);
    }

    public function destroyComment(Request $request, Comment $comment): JsonResponse
    {
        $user = $this->authorizeAccess($request);

        if ($comment->user_id !== $user->getAuthIdentifier()) {
            throw new AccessDeniedHttpException;
        }

        $comment->delete();

        return response()->json(['data' => ['file' => $this->presenter->detail($comment->file, $user)]]);
    }

    // ----- Support -----

    private function folderOrNull(int|string|null $id): ?Folder
    {
        return blank($id) ? null : Folder::query()->findOrFail((int) $id);
    }

    /**
     * @return array{0: Collection<int, Folder>, 1: Collection<int, File>}
     */
    private function selectedItems(Request $request): array
    {
        $validated = $request->validate([
            'folders' => ['array'],
            'folders.*' => ['integer'],
            'files' => ['array'],
            'files.*' => ['integer'],
        ]);

        $folders = Folder::query()->whereKey($validated['folders'] ?? [])->orderBy('name')->get();
        $files = File::query()->whereKey($validated['files'] ?? [])->orderBy('name')->get();

        if ($folders->isEmpty() && $files->isEmpty()) {
            throw new NotFoundHttpException;
        }

        return [$folders, $files];
    }

    private function targetFolder(Request $request): ?Folder
    {
        $validated = $request->validate(['target_id' => ['nullable', 'integer', Rule::exists('filemanager_folders', 'id')]]);

        return $this->folderOrNull($validated['target_id'] ?? null);
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
