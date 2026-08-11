<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Manejo de archivos estáticos (logos, firmas, pdfs) replicando FileService de lab-results-api.
 * Los archivos se guardan bajo public/static/ y las rutas guardadas en BD son relativas
 * (ej: "upload/logos/xxxx.png", "upload/firmas/xxxx.png", "images/none.jpg").
 */
class FileService
{
    private function staticDir(): string
    {
        return dirname(__DIR__, 2) . '/public/static';
    }

    /**
     * Guarda un archivo subido en public/static/<folder>/ con nombre único.
     * Valida que sea imagen o PDF (MIME real) y el tamaño máximo.
     * Devuelve la ruta relativa tipo "upload/logos/<uuid>.<ext>".
     */
    public function saveUpload(UploadedFile $file, string $folder = 'upload', int $maxBytes = 5 * 1024 * 1024): string
    {
        if ($file->getSize() > $maxBytes) {
            throw new \InvalidArgumentException('El archivo supera el tamaño máximo permitido (5 MB)');
        }
        // MIME real mediante finfo (getMimeType() requiere symfony/mime, no instalado).
        $mime = 'application/octet-stream';
        if ($file->getError() === UPLOAD_ERR_OK && function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $realMime = $finfo ? (string) finfo_file($finfo, $file->getPathname()) : '';
            if ($finfo) {
                finfo_close($finfo);
            }
            if ($realMime !== '') {
                $mime = $realMime;
            }
        }
        if (!str_starts_with($mime, 'image/') && $mime !== 'application/pdf') {
            throw new \InvalidArgumentException('Solo se permiten archivos de imagen o PDF');
        }

        $folder = trim($folder, '/');
        $dir = $this->staticDir() . '/' . $folder;
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $extension = strtolower($file->getClientOriginalExtension() ?: 'png');
        $safeExt = in_array($extension, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'pdf'], true) ? $extension : 'png';
        $name = bin2hex(random_bytes(8)) . '.' . $safeExt;
        $file->move($dir, $name);
        return $folder . '/' . $name;
    }

    /**
     * Resuelve una ruta relativa de BD (con o sin prefijo static/) al path real.
     * Solo se sirven archivos dentro de public/static (evita path traversal).
     */
    public function findFile(string $path): ?string
    {
        $path = str_replace('\\', '/', $path);
        $path = ltrim($path, '/');
        if ($path === '' || str_contains($path, '..')) {
            return null;
        }
        $staticRoot = realpath($this->staticDir()) ?: $this->staticDir();
        $candidates = [
            $this->staticDir() . '/' . $path,
            dirname(__DIR__, 2) . '/public/' . $path,
        ];
        foreach ($candidates as $candidate) {
            $real = realpath($candidate);
            if ($real !== false && is_file($real)) {
                if ($real === $staticRoot || str_starts_with($real, rtrim($staticRoot, '/\\') . DIRECTORY_SEPARATOR)) {
                    return $real;
                }
            }
        }
        return null;
    }

    public function deleteByPath(string $path): void
    {
        $file = $this->findFile($path);
        if ($file) {
            @unlink($file);
        }
    }
}
