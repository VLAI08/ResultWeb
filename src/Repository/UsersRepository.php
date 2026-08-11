<?php

namespace App\Repository;

use App\Entity\Users;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Users>
 */
class UsersRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Users::class);
    }

    public function findOneByIdentificationAndType(string $identification, ?string $identificationType, bool $isAdmin = false): ?Users
    {
        $qb = $this->createQueryBuilder('u')
            ->andWhere('u.identification = :ident')
            ->andWhere('u.isadmin = :isadmin')
            ->setParameter('ident', $identification)
            ->setParameter('isadmin', $isAdmin);
        if ($identificationType !== null) {
            $qb->andWhere('u.identificationtype = :idtype')
               ->setParameter('idtype', $identificationType);
        }
        return $qb->getQuery()->getOneOrNullResult();
    }
}
