<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager\Http;

use Aaix\LaravelIslandsFilemanager\FileManager;
use Aaix\LaravelIslandsFilemanager\Island\FileManagerProps;
use Aaix\LaravelIslandsFilemanager\Location;
use Aaix\LaravelIslandsFilemanager\Models\File;
use Aaix\LaravelIslandsFilemanager\Models\Folder;
use Aaix\LaravelIslandsFilemanager\Services\DirectScanner;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class PageController
{
    public function index(Request $request, FileManagerProps $props, DirectScanner $scanner): View
    {
        if (! FileManager::allows($request->user())) {
            throw new AccessDeniedHttpException;
        }

        $request->validate(['volume' => ['nullable', 'string', 'max:64'], 'folder' => ['nullable', 'integer'], 'file' => ['nullable', 'integer'], 'preview' => ['nullable', 'boolean']]);

        $volumes = FileManager::volumesFor($request->user());
        $volume = $volumes[$request->input('volume')] ?? reset($volumes) ?: throw new AccessDeniedHttpException;
        $file = $request->filled('file') ? File::query()->whereIn('volume', array_keys($volumes))->find($request->integer('file')) : null;

        if ($file !== null) {
            $volume = $file->volume();
        }

        $folder = $file?->folder ?? ($request->filled('folder') ? Folder::query()->where('volume', $volume->key)->find($request->integer('folder')) : null);

        return view(config('filemanager.page.view'), [
            'title' => __(config('filemanager.page.title')),
            'island' => 'FileManager',
            'flush' => true,
            'islandProps' => $props->build($request->user(), $this->scanned($scanner, new Location($volume, $folder)), $file, $request->boolean('preview')),
        ]);
    }

    private function scanned(DirectScanner $scanner, Location $at): Location
    {
        $scanner->scanFolder($at);

        return $at;
    }
}
