<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager\Services;

use Aaix\LaravelIslandsFilemanager\Models\File;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;
use ZipArchive;

class TextExtractor
{
    private const int MAX_PLAIN_TEXT_BYTES = 5_000_000;

    private const int MIN_CHARACTERS_PER_PDF_PAGE = 30;

    private const int MAX_OCR_PAGES = 10;

    /** @var array<string, list<string>> */
    private const array OFFICE_PARTS = [
        'docx' => ['#^word/(document|header\d*|footer\d*|footnotes)\.xml$#'],
        'xlsx' => ['#^xl/sharedStrings\.xml$#', '#^xl/worksheets/sheet\d+\.xml$#'],
        'pptx' => ['#^ppt/slides/slide\d+\.xml$#'],
        'odt' => ['#^content\.xml$#'],
        'ods' => ['#^content\.xml$#'],
        'odp' => ['#^content\.xml$#'],
    ];

    public function supports(File $file): bool
    {
        return $file->isPdf()
            || str_starts_with($file->mime_type, 'text/')
            || isset(self::OFFICE_PARTS[$this->extension($file)])
            || ($file->isImage() && $this->canRecognise());
    }

    public function needsRecognition(File $file): bool
    {
        return ($file->isPdf() || $file->isImage()) && $this->canRecognise();
    }

    public function extract(File $file, string $path, bool $recognise = true): ?string
    {
        if (! $this->supports($file)) {
            return null;
        }

        $extension = $this->extension($file);

        $text = match (true) {
            $file->isPdf() => $this->fromPdf($path, $recognise),
            isset(self::OFFICE_PARTS[$extension]) => $this->fromOfficeDocument($path, self::OFFICE_PARTS[$extension]),
            $file->isImage() => $recognise ? $this->recognise($path) : null,
            default => (string) file_get_contents($path, length: self::MAX_PLAIN_TEXT_BYTES),
        };

        $text = trim((string) preg_replace('/\s+/u', ' ', mb_convert_encoding($text ?? '', 'UTF-8', 'UTF-8')));

        return $text === '' ? null : $text;
    }

    private function fromPdf(string $path, bool $recognise): ?string
    {
        $result = Process::timeout(120)->run(['pdftotext', '-enc', 'UTF-8', '-q', $path, '-']);
        $text = $result->successful() ? $result->output() : '';
        $pages = max(1, substr_count($text, "\f"));

        if (mb_strlen(trim($text)) >= self::MIN_CHARACTERS_PER_PDF_PAGE * $pages || ! $recognise || ! $this->canRecognise()) {
            return $text;
        }

        return trim($text.' '.$this->recognisePdf($path));
    }

    private function recognisePdf(string $path): string
    {
        $temporary = (new TemporaryDirectory)->create();

        try {
            Process::timeout(60)->run(['pdftoppm', '-r', '150', '-gray', '-png', '-l', (string) self::MAX_OCR_PAGES, $path, $temporary->path('page')]);
            $pages = glob($temporary->path('page*.png')) ?: [];
            sort($pages, SORT_NATURAL);

            return implode(' ', array_map(fn (string $page): string => $this->recognise($page) ?? '', $pages));
        } finally {
            $temporary->delete();
        }
    }

    private function recognise(string $imagePath): ?string
    {
        $result = Process::timeout(30)->run(['tesseract', $imagePath, 'stdout', '-l', config('filemanager.ocr_languages', 'eng')]);

        return $result->successful() ? $result->output() : null;
    }

    private function canRecognise(): bool
    {
        return config('filemanager.ocr', true) && Process::run(['which', 'tesseract'])->successful();
    }

    /**
     * @param  list<string>  $patterns
     */
    private function fromOfficeDocument(string $path, array $patterns): ?string
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return null;
        }

        $parts = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $name)) {
                    $parts[$name] = html_entity_decode(strip_tags(str_replace('<', ' <', (string) $zip->getFromIndex($index))), ENT_QUOTES | ENT_XML1);
                }
            }
        }

        $zip->close();
        ksort($parts, SORT_NATURAL);

        return implode(' ', $parts);
    }

    private function extension(File $file): string
    {
        return strtolower(pathinfo($file->name, PATHINFO_EXTENSION));
    }
}
