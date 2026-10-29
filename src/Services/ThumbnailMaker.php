<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager\Services;

use Aaix\LaravelIslandsFilemanager\Models\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Imagick;
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

    /**
     * @return array{width?: int, height?: int, taken_at?: string, camera?: string}
     */
    public function inspect(File $file, string $localSource): array
    {
        if (! $file->isImage()) {
            return [];
        }

        try {
            $image = new Imagick;
            $image->pingImage($localSource);
        } catch (Throwable) {
            return [];
        }

        $width = $image->getImageWidth();
        $height = $image->getImageHeight();
        $turned = in_array($image->getImageOrientation(), [Imagick::ORIENTATION_LEFTTOP, Imagick::ORIENTATION_RIGHTTOP, Imagick::ORIENTATION_RIGHTBOTTOM, Imagick::ORIENTATION_LEFTBOTTOM], true);
        $exif = $image->getImageProperties('exif:*');

        return array_filter([
            'width' => $turned ? $height : $width,
            'height' => $turned ? $width : $height,
            'taken_at' => $this->takenAt($exif['exif:DateTimeOriginal'] ?? $exif['exif:DateTime'] ?? null, $exif['exif:OffsetTimeOriginal'] ?? null),
            'camera' => $this->camera($exif['exif:Make'] ?? null, $exif['exif:Model'] ?? null),
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function takenAt(?string $stamp, ?string $offset): ?string
    {
        if ($stamp === null || preg_match('/^(\d{4}):(\d{2}):(\d{2}) (\d{2}:\d{2}:\d{2})/', trim($stamp), $parts) !== 1 || $parts[1] === '0000') {
            return null;
        }

        $offset = $offset !== null && preg_match('/^[+-]\d{2}:\d{2}$/', trim($offset)) === 1 ? trim($offset) : '';

        return "{$parts[1]}-{$parts[2]}-{$parts[3]}T{$parts[4]}{$offset}";
    }

    private function camera(?string $make, ?string $model): ?string
    {
        $make = trim((string) $make);
        $model = trim((string) $model);

        if ($model === '') {
            return $make === '' ? null : $make;
        }

        return $make === '' || str_starts_with(mb_strtolower($model), mb_strtolower(strtok($make, ' '))) ? $model : "{$make} {$model}";
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
