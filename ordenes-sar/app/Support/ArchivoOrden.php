<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ArchivoOrden
{
    private const PUBLIC_DIR = 'storage/archivos';

    public static function store(UploadedFile $file, string $prefix = 'archivo'): string
    {
        $destination = public_path(self::PUBLIC_DIR);
        File::ensureDirectoryExists($destination);

        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $baseName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeBaseName = Str::slug($baseName) ?: $prefix;
        $fileName = now()->format('Ymd_His') . '_' . Str::random(8) . '_' . $safeBaseName . '.' . $extension;

        $file->move($destination, $fileName);

        return $fileName;
    }

    public static function url(?string $fileName): ?string
    {
        if (!$fileName) {
            return null;
        }

        if (preg_match('/^https?:\/\//i', $fileName)) {
            return $fileName;
        }

        $normalized = str_replace('\\', '/', ltrim($fileName, '/'));
        $normalized = preg_replace('#^(public/)?storage/archivos/#', '', $normalized);
        $segments = array_filter(explode('/', $normalized), fn ($segment) => !in_array($segment, ['', '.', '..'], true));
        $segments = array_map('rawurlencode', $segments);

        return asset(self::PUBLIC_DIR . '/' . implode('/', $segments));
    }
}
