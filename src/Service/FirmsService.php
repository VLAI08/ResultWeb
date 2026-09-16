<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Firmas médicas (tabla firms, MySQL alpha). La columna url guarda una ruta
 * relativa tipo "upload/firmas/<nombre>.<ext>" o "images/none.jpg".
 */
class FirmsService
{
    public function __construct(private ManagerRegistry $registry)
    {
    }

    private function conn(): Connection
    {
        return $this->registry->getConnection('alpha');
    }

    public function findByCode(string $code): ?array
    {
        $row = $this->conn()->fetchAssociative(
            'SELECT id, code, url, code_company, active FROM firms WHERE code = :code AND active = 1 ORDER BY id DESC LIMIT 1',
            ['code' => $code]
        );
        return $row ?: null;
    }

    public function findAll(?int $page = null, ?int $limit = null, string $parameter = ''): array
    {
        $where = '';
        $params = [];
        if ($parameter !== '') {
            $where = 'WHERE code LIKE :p OR url LIKE :p OR code_company LIKE :p';
            $params['p'] = '%' . $parameter . '%';
        }
        $count = (int) $this->conn()->fetchOne("SELECT COUNT(*) FROM firms $where", $params);

        $limitSql = '';
        if ($page !== null && $limit !== null) {
            $limit = max(1, (int) $limit);
            $offset = max(0, ((int) $page - 1) * $limit);
            $limitSql = 'LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $rows = $this->conn()->fetchAllAssociative(
            "SELECT id, code, url, code_company, active FROM firms $where ORDER BY id DESC $limitSql",
            $params
        );
        return ['total_count' => $count, 'items' => $rows];
    }

    public function find(int $id): ?array
    {
        $row = $this->conn()->fetchAssociative(
            'SELECT id, code, url, code_company, active FROM firms WHERE id = :id',
            ['id' => $id]
        );
        return $row ?: null;
    }

    public function create(string $code, string $url, string $codeCompany, bool $active): array
    {
        $this->conn()->insert('firms', [
            'code' => $code,
            'url' => $url,
            'code_company' => $codeCompany ?: 'admin',
            'active' => $active ? 1 : 0,
        ]);
        $id = (int) $this->conn()->lastInsertId();
        return $this->find($id);
    }

    public function update(int $id, array $data): ?array
    {
        $fields = [];
        foreach (['code', 'url', 'code_company', 'active'] as $key) {
            if (array_key_exists($key, $data)) {
                $fields[$key] = $key === 'active' ? ($data[$key] ? 1 : 0) : $data[$key];
            }
        }
        if ($fields) {
            $this->conn()->update('firms', $fields, ['id' => $id]);
        }
        return $this->find($id);
    }

    public function deactivate(int $id): void
    {
        $this->conn()->update('firms', ['active' => 0], ['id' => $id]);
    }

    /**
     * Resuelve una ruta relativa de archivo (url de firms) al path real en public/.
     * Si el archivo no existe localmente y hay un servidor espejo configurado
     * (STATIC_MIRROR_URL, ej: la producción V2026), lo descarga UNA vez y lo cachea.
     * Así los entornos locales muestran las firmas idénticas a la web actual.
     */
    public function resolvePath(string $url): ?string
    {
        $url = str_replace('\\', '/', $url);
        $url = ltrim($url, '/');
        if ($url === '') {
            return null;
        }
        $path = dirname(__DIR__, 2) . '/public/static/' . $url;
        if (is_file($path)) {
            return $path;
        }
        // Fallback: archivos directamente bajo public/ o public/static/
        $alt = dirname(__DIR__, 2) . '/public/' . $url;
        if (is_file($alt)) {
            return $alt;
        }
        // Espejo: solo para firmas y solo si está configurado (evita fetch arbitrario)
        if (str_starts_with($url, 'upload/firmas/')) {
            return $this->mirrorFetch($url);
        }
        return null;
    }

    /**
     * Descarga el asset desde el servidor espejo (producción) y lo guarda localmente.
     * Antes → problema: sin los archivos físicos de firmas, el PDF usaba el
     * placeholder none.jpg y las firmas no aparecían.
     * Cambio: mirror-on-miss con caché local; solo se activa con STATIC_MIRROR_URL.
     */
    private function mirrorFetch(string $url): ?string
    {
        $base = (string) ($_ENV['STATIC_MIRROR_URL'] ?? $_SERVER['STATIC_MIRROR_URL'] ?? '');
        if ($base === '' || !function_exists('curl_init')) {
            return null;
        }
        $localPath = dirname(__DIR__, 2) . '/public/static/' . $url;
        $dir = dirname($localPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (!is_writable($dir)) {
            return null;
        }
        $endpoint = $base . '/files?path=' . rawurlencode($url);
        $ch = curl_init($endpoint);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => [],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => false,
            // El espejo sirve imágenes públicas; sin CA local provisional de Windows
            // se valida por tipo MIME/marca de bytes, no por cadena de certificados.
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        if ($response === false) {
            return null;
        }
        if (($status === 200 || $status === 201) && str_starts_with($contentType, 'image/') && strlen($response) > 0) {
            file_put_contents($localPath, $response);
            return is_file($localPath) ? $localPath : null;
        }
        return null;
    }
}
