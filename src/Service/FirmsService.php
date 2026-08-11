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
        return null;
    }
}
