<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager\Services;

use Aaix\LaravelIslandsFilemanager\Models\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Intervention\Image\Drivers\Imagick\Driver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Spatie\TemporaryDirectory\TemporaryDirectory;
use Throwable;

class ThumbnailMaker
{
    private const int EDGE = 640;

    public function make(File $file, string $localSource): ?string
    {
        if (! $file->isImage() && ! $file->isPdf()) {
            return null;
        }

        $temporary = (new TemporaryDirectory)->create();

        try {
            $source = $file->isPdf() ? $this->firstPdfPage($localSource, $temporary) : $localSource;

            if ($source === null) {
                return null;
            }

            $encoded = ImageManager::usingDriver(Driver::class)
                ->decodePath($source)
                ->orient()
                ->scaleDown(self::EDGE, self::EDGE)
                ->encode(new WebpEncoder(quality: 80));

            $path = $file->volume()->helperPath('thumbnails', $file->mediaHash(), 'webp');
            $file->mediaDisk()->put($path, (string) $encoded);

            return $path;
        } catch (Throwable $exception) {
            Log::warning('File manager: no thumbnail for file '.$file->id, ['error' => $exception->getMessage()]);

            return null;
        } finally {
            $temporary->delete();
        }
    }

    private function firstPdfPage(string $pdf, TemporaryDirectory $temporary): ?string
    {
        $target = $temporary->path('page');

        $result = Process::timeout(30)->run([
            'pdftoppm', '-f', '1', '-l', '1', '-singlefile', '-png', '-scale-to', (string) self::EDGE, $pdf, $target,
        ]);

        return $result->successful() && is_file($target.'.png') ? $target.'.png' : null;
    }
}
