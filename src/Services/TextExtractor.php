<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager\Services;

use Aaix\LaravelIslandsFilemanager\Models\File;
use Illuminate\Support\Facades\Process;

class TextExtractor
{
    private const int MAX_PLAIN_TEXT_BYTES = 5_000_000;

    public function supports(File $file): bool
    {
        return $file->isPdf() || str_starts_with($file->mime_type, 'text/');
    }

    public function extract(File $file): ?string
    {
        if ($file->path === null || ! $this->supports($file)) {
            return null;
        }

        $path = $file->mediaDisk()->path($file->path);

        $text = $file->isPdf()
            ? $this->fromPdf($path)
            : (string) file_get_contents($path, length: self::MAX_PLAIN_TEXT_BYTES);

        $text = trim((string) preg_replace('/\s+/u', ' ', mb_convert_encoding($text ?? '', 'UTF-8', 'UTF-8')));

        return $text === '' ? null : $text;
    }

    private function fromPdf(string $path): ?string
    {
        $result = Process::timeout(120)->run(['pdftotext', '-enc', 'UTF-8', '-q', $path, '-']);

        return $result->successful() ? $result->output() : null;
    }
}
