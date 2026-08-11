<?php

namespace App\Service;

use App\Entity\Domains;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

class DomainsService
{
    public function __construct(private ManagerRegistry $registry)
    {
    }

    private function em(): EntityManagerInterface
    {
        return $this->registry->getManager('alpha');
    }

    /**
     * Devuelve los valores activos de un dominio (lista plana de códigos).
     */
    public function activeValues(string $nombre): array
    {
        $rows = $this->findActivesByName($nombre);
        return array_values(array_filter(array_map(fn ($d) => trim($d['valor']), $rows), 'strlen'));
    }

    public function findActivesByName(string $nombre): array
    {
        $conn = $this->registry->getConnection('alpha');
        return $conn->fetchAllAssociative(
            'SELECT id, titulo, valor, nombre, idioma, estado, mostrar FROM domains WHERE nombre = :nombre AND estado = 1',
            ['nombre' => $nombre]
        );
    }

    public function findOneActiveByName(string $nombre): ?array
    {
        $rows = $this->findActivesByName($nombre);
        return $rows ? $rows[0] : null;
    }

    /**
     * Lista de dominios activos para selectores (legacy HomeController y formularios).
     */
    public function listDomainsActive(string $nombre): array
    {
        return $this->findActivesByName($nombre);
    }

    /**
     * Lista paginada con filtros (compatible con el panel de configuración).
     */
    public function findAll(?int $page = null, ?int $limit = null, string $parameter = '', string $name = '', ?bool $active = null): array
    {
        $where = [];
        $params = [];
        if ($parameter !== '') {
            $where[] = '(titulo LIKE :p OR valor LIKE :p OR nombre LIKE :p)';
            $params['p'] = '%' . $parameter . '%';
        }
        if ($name !== '') {
            $where[] = 'nombre = :name';
            $params['name'] = $name;
        }
        if ($active !== null) {
            $where[] = 'estado = :estado';
            $params['estado'] = $active ? 1 : 0;
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $count = (int) $this->registry->getConnection('alpha')->fetchOne("SELECT COUNT(*) FROM domains $whereSql", $params);

        $limitSql = '';
        if ($page !== null && $limit !== null) {
            $limit = max(1, (int) $limit);
            $offset = max(0, ((int) $page - 1) * $limit);
            $limitSql = 'LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $rows = $this->registry->getConnection('alpha')->fetchAllAssociative(
            "SELECT id, titulo, valor, nombre, idioma, estado, mostrar FROM domains $whereSql ORDER BY id DESC $limitSql",
            $params
        );
        return ['total_count' => $count, 'items' => $rows];
    }

    public function find(int $id): ?array
    {
        $row = $this->registry->getConnection('alpha')->fetchAssociative(
            'SELECT id, titulo, valor, nombre, idioma, estado, mostrar FROM domains WHERE id = :id',
            ['id' => $id]
        );
        return $row ?: null;
    }

    public function create(array $data): array
    {
        $em = $this->em();
        $domain = new Domains();
        $domain->setTitulo((string) ($data['titulo'] ?? ''));
        $domain->setValor((string) ($data['valor'] ?? ''));
        $domain->setNombre((string) ($data['nombre'] ?? ''));
        $domain->setIdioma((string) ($data['idioma'] ?? 'ES'));
        $domain->setEstado((bool) ($data['estado'] ?? true));
        $domain->setMostrar((bool) ($data['mostrar'] ?? false));
        $em->persist($domain);
        $em->flush();
        return $this->find($domain->getId());
    }

    public function update(int $id, array $data): ?array
    {
        $em = $this->em();
        $domain = $em->getRepository(Domains::class)->find($id);
        if (!$domain) {
            return null;
        }
        if (array_key_exists('titulo', $data)) {
            $domain->setTitulo((string) $data['titulo']);
        }
        if (array_key_exists('valor', $data)) {
            $domain->setValor((string) $data['valor']);
        }
        if (array_key_exists('nombre', $data)) {
            $domain->setNombre((string) $data['nombre']);
        }
        if (array_key_exists('idioma', $data)) {
            $domain->setIdioma((string) $data['idioma']);
        }
        if (array_key_exists('estado', $data)) {
            $domain->setEstado((bool) $data['estado']);
        }
        if (array_key_exists('mostrar', $data)) {
            $domain->setMostrar((bool) $data['mostrar']);
        }
        $em->flush();
        return $this->find($id);
    }

    public function deactivate(int $id): void
    {
        $em = $this->em();
        $domain = $em->getRepository(Domains::class)->find($id);
        if ($domain) {
            $domain->setEstado(false);
            $em->flush();
        }
    }
}
