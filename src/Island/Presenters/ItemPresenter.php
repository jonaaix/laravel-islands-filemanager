<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager\Island\Presenters;

use Aaix\LaravelIslandsFilemanager\FileManager;
use Aaix\LaravelIslandsFilemanager\Models\Comment;
use Aaix\LaravelIslandsFilemanager\Models\File;
use Aaix\LaravelIslandsFilemanager\Models\Folder;
use Illuminate\Contracts\Auth\Authenticatable;

class ItemPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function folder(Folder $folder): array
    {
        return [
            'type' => 'folder',
            'id' => $folder->id,
            'name' => $folder->name,
            'itemCount' => (int) ($folder->children_count ?? 0) + (int) ($folder->files_count ?? 0),
            'owner' => $folder->createdBy?->name,
            'ownerAvatar' => FileManager::avatarOf($folder->createdBy),
            'createdAt' => $folder->created_at?->toIso8601String(),
            'updatedAt' => $folder->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function file(File $file): array
    {
        $route = fn (string $name, array $query = []): string => FileManager::route($name, ['file' => $file->id, ...$query]);

        return [
            'type' => 'file',
            'id' => $file->id,
            'name' => $file->name,
            'extension' => strtoupper(pathinfo($file->name, PATHINFO_EXTENSION)),
            'mimeType' => $file->mime_type,
            'size' => (int) $file->size,
            'isImage' => $file->isImage(),
            'isPdf' => $file->isPdf(),
            'thumbnailUrl' => $file->thumbnail_path ? $route('files.thumbnail', ['v' => $file->updated_at?->timestamp]) : null,
            'url' => $route('files.content'),
            'downloadUrl' => $route('files.content', ['download' => 1]),
            'hasNotes' => filled($file->notes),
            'commentCount' => (int) ($file->comments_count ?? 0),
            'owner' => $file->uploadedBy?->name,
            'ownerAvatar' => FileManager::avatarOf($file->uploadedBy),
            'createdAt' => $file->created_at?->toIso8601String(),
            'updatedAt' => $file->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  list<string>  $originalPath
     * @return array<string, mixed>
     */
    public function trashEntry(Folder|File $entry, array $originalPath): array
    {
        $isFolder = $entry instanceof Folder;

        return [
            'type' => $isFolder ? 'folder' : 'file',
            'id' => $entry->id,
            'name' => $entry->name,
            'extension' => $isFolder ? null : strtoupper(pathinfo($entry->name, PATHINFO_EXTENSION)),
            'size' => $isFolder ? null : (int) $entry->size,
            'originalPath' => $originalPath,
            'deletedAt' => $entry->deleted_at?->toIso8601String(),
            'deletedBy' => $entry->deletedBy?->name,
            'deletedByAvatar' => FileManager::avatarOf($entry->deletedBy),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(File $file, Authenticatable $viewer): array
    {
        $file->loadMissing(['uploadedBy', 'comments.user'])->loadCount('comments');

        return [
            ...$this->file($file),
            'notes' => $file->notes,
            'folderId' => $file->folder_id,
            'uploadedBy' => $file->uploadedBy?->name,
            'comments' => $file->comments->sortBy('id')->values()->map(fn (Comment $comment): array => [
                'id' => $comment->id,
                'body' => $comment->body,
                'author' => $comment->user?->name ?? __('Deleted user'),
                'authorAvatar' => FileManager::avatarOf($comment->user),
                'createdAt' => $comment->created_at?->toIso8601String(),
                'canDelete' => $comment->user_id === $viewer->getAuthIdentifier(),
            ])->all(),
        ];
    }
}
