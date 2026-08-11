<?php

namespace App\Service;

/**
 * Descarga de PDFs de resultados publicados desde el servidor FTP (WinsisLab).
 * Replica FtpService de lab-results-api: la URL del archivo (pe.archivo) es una
 * ruta UNC tipo "\\10.0.0.242\Upload\resultados\Solicitud_xxxx.pdf".
 */
class FtpService
{
    public function __construct(
        private string $ftpHost,
        private string $ftpUser,
        private string $ftpPassword,
        private int $ftpPort = 21,
        private int $ftpTimeout = 30,
    ) {
    }

    /**
     * Descarga el PDF de una solicitud desde el FTP.
     * Devuelve ['content' => string, 'filename' => string] o null si no es posible.
     */
    public function downloadPdf(string $requestCode, string $rawUrl): ?array
    {
        $url = $this->normalizeUrl($rawUrl);
        if (!$url) {
            return null;
        }
        if (!function_exists('ftp_connect')) {
            return null;
        }

        $conn = @ftp_connect($this->ftpHost, $this->ftpPort, $this->ftpTimeout);
        if (!$conn) {
            return null;
        }
        $ok = @ftp_login($conn, $this->ftpUser, $this->ftpPassword);
        if (!$ok) {
            @ftp_close($conn);
            return null;
        }
        @ftp_pasv($conn, true);

        $tmp = tempnam(sys_get_temp_dir(), 'res_pdf_');
        if (!$tmp) {
            @ftp_close($conn);
            return null;
        }
        $ok = @ftp_get($conn, $tmp, $url, FTP_BINARY);
        @ftp_close($conn);

        if (!$ok || !is_file($tmp)) {
            @unlink($tmp);
            return null;
        }
        $content = (string) file_get_contents($tmp);
        @unlink($tmp);

        if ($content === '') {
            return null;
        }
        return ['content' => $content, 'filename' => $requestCode . '.pdf'];
    }

    /**
     * Normaliza la URL UNC hacia una ruta relativa al raíz del FTP.
     */
    private function normalizeUrl(string $rawUrl): string
    {
        $url = trim($rawUrl);
        if ($url === '' || strtolower($url) === 'no') {
            return '';
        }
        // \\host\path o //host/path o host/path
        $url = str_replace('\\', '/', $url);
        if (preg_match('#^/{2,}[^/]+/(.+)$#', $url, $m)) {
            $url = $m[1];
        }
        return ltrim($url, '/');
    }
}
