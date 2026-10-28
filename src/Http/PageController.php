<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager\Http;

use Aaix\LaravelIslandsFilemanager\FileManager;
use Aaix\LaravelIslandsFilemanager\Island\FileManagerProps;
use Aaix\LaravelIslandsFilemanager\Models\File;
use Aaix\LaravelIslandsFilemanager\Models\Folder;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class PageController
{
    public function index(Request $request, FileManagerProps $props): View
    {
        if (! FileManager::allows($request->user())) {
            throw new AccessDeniedHttpException;
        }

        $request->validate(['folder' => ['nullable', 'integer'], 'file' => ['nullable', 'integer'], 'preview' => ['nullable', 'boolean']]);

        $file = $request->filled('file') ? File::query()->find($request->integer('file')) : null;
        $folder = $file?->folder ?? ($request->filled('folder') ? Folder::query()->find($request->integer('folder')) : null);

        return view(config('filemanager.page.view'), [
            'title' => __(config('filemanager.page.title')),
            'island' => 'FileManager',
            'flush' => true,
            'islandProps' => $props->build($request->user(), $folder, $file, $request->boolean('preview')),
        ]);
    }
}
