<?php

namespace App\Repository;

use App\Entity\Domains;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Domains>
 */
class DomainsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Domains::class);
    }

    /**
     * @return Domains[]
     */
    public function findActiveByName(string $nombre): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.estado = :estado')
            ->andWhere('d.nombre = :nombre')
            ->setParameter('estado', true)
            ->setParameter('nombre', $nombre)
            ->orderBy('d.titulo', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
