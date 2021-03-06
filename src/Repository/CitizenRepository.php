<?php

namespace App\Repository;

use App\Domain\SpectrumIdentification;
use App\Entity\Citizen;
use App\Entity\CitizenOrganization;
use App\Entity\Fleet;
use App\Entity\Organization;
use App\Entity\Ship;
use App\Entity\User;
use App\Service\Dto\ShipFamilyFilter;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\ORM\Query\ResultSetMappingBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Ramsey\Uuid\UuidInterface;

class CitizenRepository extends ServiceEntityRepository
{
    use CitizenStatisticsRepositoryTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Citizen::class);
    }

    public function findSomeHandlesWithLastFleet(array $handles): array
    {
        return $this->createQueryBuilder('c')
            ->addSelect('fleet')
            ->leftJoin('c.lastFleet', 'fleet')
            ->where('LOWER(c.actualHandle) IN (:handles)')
            ->setParameter('handles', $handles)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Citizen[]
     */
    public function findPublics(): array
    {
        return $this->createQueryBuilder('c')
            ->addSelect('fleet')
            ->innerJoin('App:User', 'u', 'WITH', 'u.citizen = c.id')
            ->leftJoin('c.lastFleet', 'fleet')
            ->where('u.publicChoice = :publicVisibility')
            ->setParameter('publicVisibility', User::PUBLIC_CHOICE_PUBLIC)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return iterable|Citizen[]
     */
    public function getByOrganization(SpectrumIdentification $organizationId): iterable
    {
        $dql = '
            SELECT c, f, s FROM App\Entity\Citizen c
            INNER JOIN c.organizations citizenOrga
            INNER JOIN citizenOrga.organization orga WITH orga.organizationSid = :sid
            LEFT JOIN c.fleets f
            LEFT JOIN f.ships s
        ';
        $query = $this->_em->createQuery($dql);
        $query->setParameter('sid', $organizationId->getSid());
        $query->enableResultCache(30);

        return $query->getResult();
    }

    /**
     * @param Citizen|null $viewerCitizen the logged citizen
     *
     * @return Citizen[]
     */
    public function findVisiblesByOrganization(string $organizationId, ?Citizen $viewerCitizen, bool $withShips = false): array
    {
        $citizenMetadata = $this->_em->getClassMetadata(Citizen::class);
        $citizenOrgaMetadata = $this->_em->getClassMetadata(CitizenOrganization::class);
        $orgaMetadata = $this->_em->getClassMetadata(Organization::class);
        $userMetadata = $this->_em->getClassMetadata(User::class);
        $fleetMetadata = $this->_em->getClassMetadata(Fleet::class);
        $shipMetadata = $this->_em->getClassMetadata(Ship::class);

        $sql = <<<EOT
                SELECT c.*, c.id AS citizenId
            EOT;
        if ($withShips) {
            $sql .= ', f.*, f.id AS fleetId, s.*, s.id AS shipId ';
        }
        $sql .= <<<EOT
                FROM {$orgaMetadata->getTableName()} orga
                INNER JOIN {$citizenOrgaMetadata->getTableName()} citizenOrga ON orga.id = citizenOrga.organization_id AND orga.organization_sid = :sid
                INNER JOIN {$citizenMetadata->getTableName()} c ON citizenOrga.citizen_id = c.id
                INNER JOIN {$userMetadata->getTableName()} u ON u.citizen_id = c.id
            EOT;
        if ($withShips) {
            $sql .= <<<EOT
                    INNER JOIN {$fleetMetadata->getTableName()} f ON f.id = c.last_fleet_id
                    INNER JOIN {$shipMetadata->getTableName()} s ON f.id = s.fleet_id
                EOT;
        }
        $sql .= <<<EOT
                WHERE (
                    u.public_choice = :userPublicChoicePublic
                    OR (u.public_choice = :userPublicChoiceOrga AND (
                            # visibility ORGA : visible by everyone
                            # visibility ADMIN : visible only by highest orga rank
                            # visibility PRIVATE : visible by anybody
                            citizenOrga.visibility = :visibilityOrga
                            OR (citizenOrga.visibility = :visibilityAdmin AND :viewerCitizenId IS NOT NULL AND :viewerCitizenId IN (
                                    # select highest ranks of this orga
                                    SELECT co.citizen_id
                                    FROM {$orgaMetadata->getTableName()} o
                                    INNER JOIN {$citizenOrgaMetadata->getTableName()} co ON co.organization_id = o.id AND o.organization_sid = :sid
                                    WHERE co.rank = (
                                        SELECT max(co.rank) AS maxRank
                                        FROM {$orgaMetadata->getTableName()} o
                                        INNER JOIN {$citizenOrgaMetadata->getTableName()} co ON co.organization_id = o.id AND o.organization_sid = :sid
                                    )
                                )
                            )
                        )
                    )
                )
            EOT;

        $rsm = new ResultSetMappingBuilder($this->_em);
        $rsm->addRootEntityFromClassMetadata(Citizen::class, 'c', ['id' => 'citizenId']);
        if ($withShips) {
            $rsm->addJoinedEntityFromClassMetadata(Fleet::class, 'f', 'c', 'fleets', ['id' => 'fleetId']);
            $rsm->addJoinedEntityFromClassMetadata(Ship::class, 's', 'f', 'ships', ['id' => 'shipId']);
        }

        $stmt = $this->_em->createNativeQuery($sql, $rsm);
        $stmt->setParameters([
            'sid' => $organizationId,
            'visibilityOrga' => CitizenOrganization::VISIBILITY_ORGA,
            'visibilityAdmin' => CitizenOrganization::VISIBILITY_ADMIN,
            'userPublicChoicePublic' => User::PUBLIC_CHOICE_PUBLIC,
            'userPublicChoiceOrga' => User::PUBLIC_CHOICE_ORGANIZATION,
            'viewerCitizenId' => $viewerCitizen !== null ? $viewerCitizen->getId()->toString() : null,
        ]);
        $stmt->enableResultCache(30);

        return $stmt->getResult();
    }

    /**
     * @return Citizen[]
     */
    public function findAdminByOrganization(string $organizationSid): array
    {
        $citizenMetadata = $this->_em->getClassMetadata(Citizen::class);
        $citizenOrgaMetadata = $this->_em->getClassMetadata(CitizenOrganization::class);
        $orgaMetadata = $this->_em->getClassMetadata(Organization::class);

        $sql = <<<EOT
                SELECT c.*, c.id AS citizenId, co.*, co.id AS citizenOrgaId, co.organization_sid AS coOrgaId, o.*, o.id AS orgaId, o.avatar_url AS orgaAvatarUrl, o.last_refresh AS orgaLastRefresh
                FROM {$orgaMetadata->getTableName()} o
                INNER JOIN {$citizenOrgaMetadata->getTableName()} co ON co.organization_id = o.id AND o.organization_sid = :sid
                INNER JOIN {$citizenMetadata->getTableName()} c ON c.id = co.citizen_id
                WHERE co.rank = (
                    SELECT max(co.rank) AS maxRank
                    FROM {$orgaMetadata->getTableName()} o
                    INNER JOIN {$citizenOrgaMetadata->getTableName()} co ON co.organization_id = o.id AND o.organization_sid = :sid
                )
            EOT;

        $rsm = new ResultSetMappingBuilder($this->_em);
        $rsm->addRootEntityFromClassMetadata(Citizen::class, 'c', ['id' => 'citizenId']);
        $rsm->addJoinedEntityFromClassMetadata(CitizenOrganization::class, 'co', 'c', 'organizations', ['id' => 'citizenOrgaId', 'organization_sid' => 'coOrgaId']);
        $rsm->addJoinedEntityFromClassMetadata(Organization::class, 'o', 'co', 'organization', ['id' => 'orgaId', 'avatar_url' => 'orgaAvatarUrl', 'last_refresh' => 'orgaLastRefresh']);

        $stmt = $this->_em->createNativeQuery($sql, $rsm);
        $stmt->setParameters([
            'sid' => $organizationSid,
        ]);
        $stmt->enableResultCache(30);

        return $stmt->getResult();
    }

    /**
     * @return Ship[]
     */
    public function getOrganizationShips(SpectrumIdentification $organizationId, ShipFamilyFilter $filter): array
    {
        $citizenMetadata = $this->getClassMetadata();
        $fleetMetadata = $this->_em->getClassMetadata(Fleet::class);
        $shipMetadata = $this->_em->getClassMetadata(Ship::class);
        $citizenOrgaMetadata = $this->_em->getClassMetadata(CitizenOrganization::class);
        $orgaMetadata = $this->_em->getClassMetadata(Organization::class);

        $sql = <<<EOT
                SELECT s.*, s.id AS shipId FROM {$citizenMetadata->getTableName()} c
                INNER JOIN {$citizenOrgaMetadata->getTableName()} citizenOrga ON citizenOrga.citizen_id = c.id
                INNER JOIN {$orgaMetadata->getTableName()} orga ON orga.id = citizenOrga.organization_id AND orga.organization_sid = :sid
                INNER JOIN {$fleetMetadata->getTableName()} f ON f.id = c.last_fleet_id
                INNER JOIN {$shipMetadata->getTableName()} s ON f.id = s.fleet_id
            EOT;
        // filtering
        if (count($filter->shipGalaxyIds) > 0) {
            $sql .= ' AND (';
            foreach ($filter->shipGalaxyIds as $i => $shipGalaxyId) {
                $sql .= sprintf(' s.galaxy_id = :shipGalaxyId_%d OR ', $i);
            }
            $sql .= ' 1=0) ';
        }
        if (count($filter->citizenIds) > 0) {
            $sql .= ' AND (';
            foreach ($filter->citizenIds as $i => $filterCitizenId) {
                $sql .= sprintf(' c.id = :citizenId_%d OR ', $i);
            }
            $sql .= ' 1=0) ';
        }

        $rsm = new ResultSetMappingBuilder($this->_em);
        $rsm->addRootEntityFromClassMetadata(Ship::class, 's', ['id' => 'shipId']);

        $stmt = $this->_em->createNativeQuery($sql, $rsm);
        $stmt->setParameter('sid', $organizationId->getSid());
        foreach ($filter->shipGalaxyIds as $i => $shipGalaxyId) {
            $stmt->setParameter('shipGalaxyId_'.$i, $shipGalaxyId);
        }
        foreach ($filter->citizenIds as $i => $filterCitizenId) {
            $stmt->setParameter('citizenId_'.$i, $filterCitizenId);
        }

        return $stmt->getResult();
    }

    public function countShipOwnedByOrga(string $organizationId, UuidInterface $shipGalaxyId, ShipFamilyFilter $filter): array
    {
        $citizenMetadata = $this->getClassMetadata();
        $fleetMetadata = $this->_em->getClassMetadata(Fleet::class);
        $shipMetadata = $this->_em->getClassMetadata(Ship::class);
        $citizenOrgaMetadata = $this->_em->getClassMetadata(CitizenOrganization::class);
        $orgaMetadata = $this->_em->getClassMetadata(Organization::class);

        $sql = <<<SQL
                SELECT COUNT(*) AS countShips FROM {$citizenMetadata->getTableName()} c
                INNER JOIN {$citizenOrgaMetadata->getTableName()} citizenOrga ON citizenOrga.citizen_id = c.id
                INNER JOIN {$orgaMetadata->getTableName()} orga ON orga.id = citizenOrga.organization_id AND orga.organization_sid = :sid
                INNER JOIN {$fleetMetadata->getTableName()} f ON f.id = c.last_fleet_id
                INNER JOIN {$shipMetadata->getTableName()} s ON f.id = s.fleet_id and s.galaxy_id = :galaxyId
            SQL;
        // filtering
        if (count($filter->citizenIds) > 0) {
            $sql .= ' AND (';
            foreach ($filter->citizenIds as $i => $filterCitizenId) {
                $sql .= sprintf(' c.id = :citizenId_%d OR ', $i);
            }
            $sql .= ' 1=0) ';
        }

        $rsm = new ResultSetMapping();
        $rsm->addScalarResult('countShips', 'countShips');
        $stmt = $this->_em->createNativeQuery($sql, $rsm);
        $stmt->setParameters([
            'sid' => $organizationId,
            'galaxyId' => $shipGalaxyId,
        ]);
        foreach ($filter->citizenIds as $i => $filterCitizenId) {
            $stmt->setParameter('citizenId_'.$i, $filterCitizenId);
        }
        $stmt->enableResultCache(60);

        return $stmt->getScalarResult();
    }

    /**
     * @param Citizen|null $viewerCitizen the logged citizen
     *
     * @return User[]
     */
    public function getOwnersOfShip(string $organizationId, UuidInterface $shipGalaxyId, ?Citizen $viewerCitizen, ShipFamilyFilter $filter, int $page = null, int $itemsPerPage = 10): array
    {
        $userMetadata = $this->_em->getClassMetadata(User::class);
        $citizenMetadata = $this->_em->getClassMetadata(Citizen::class);
        $fleetMetadata = $this->_em->getClassMetadata(Fleet::class);
        $shipMetadata = $this->_em->getClassMetadata(Ship::class);
        $citizenOrgaMetadata = $this->_em->getClassMetadata(CitizenOrganization::class);
        $orgaMetadata = $this->_em->getClassMetadata(Organization::class);

        $sql = <<<SQL
                SELECT u.*, u.id AS userId,
                       c.*, c.nickname AS citizenNickname, c.id AS citizenId,
                       COUNT(s.id) AS countShips
                FROM {$orgaMetadata->getTableName()} orga
                INNER JOIN {$citizenOrgaMetadata->getTableName()} citizenOrga ON orga.id = citizenOrga.organization_id AND orga.organization_sid = :sid
                INNER JOIN {$citizenMetadata->getTableName()} c ON citizenOrga.citizen_id = c.id
                INNER JOIN {$userMetadata->getTableName()} u ON u.citizen_id = c.id
                INNER JOIN {$fleetMetadata->getTableName()} f ON f.id = c.last_fleet_id
                INNER JOIN {$shipMetadata->getTableName()} s ON s.fleet_id = f.id and s.galaxy_id = :shipId
                WHERE (
                    u.public_choice = :userPublicChoicePublic
                    OR (u.public_choice = :userPublicChoiceOrga AND (
                            # visibility ORGA : visible by everyone
                            # visibility ADMIN : visible only by highest orga rank
                            # visibility PRIVATE : visible by anybody
                            citizenOrga.visibility = :visibilityOrga
                            OR (citizenOrga.visibility = :visibilityAdmin AND :viewerCitizenId IS NOT NULL AND :viewerCitizenId IN (
                                    # select highest ranks of this orga
                                    SELECT co.citizen_id
                                    FROM {$orgaMetadata->getTableName()} o
                                    INNER JOIN {$citizenOrgaMetadata->getTableName()} co ON co.organization_id = o.id AND o.organization_sid = :sid
                                    WHERE co.rank = (
                                        SELECT max(co.rank) AS maxRank
                                        FROM {$orgaMetadata->getTableName()} o
                                        INNER JOIN {$citizenOrgaMetadata->getTableName()} co ON co.organization_id = o.id AND o.organization_sid = :sid
                                    )
                                )
                            )
                        )
                    )
                )
            SQL;

        // filtering
        if (count($filter->citizenIds) > 0) {
            $sql .= ' AND (';
            foreach ($filter->citizenIds as $i => $filterCitizenId) {
                $sql .= sprintf(' c.id = :citizenId_%d OR ', $i);
            }
            $sql .= ' 1=0) ';
        }
        $sql .= <<<EOT
                GROUP BY u.id, c.id, citizenOrga.id
                ORDER BY countShips DESC
            EOT;
        // pagination
        if ($page !== null) {
            $sql .= "\nLIMIT :first, :countItems\n";
        }

        $rsm = new ResultSetMappingBuilder($this->_em);
        $rsm->addRootEntityFromClassMetadata(User::class, 'u', ['id' => 'userId']);
        $rsm->addJoinedEntityFromClassMetadata(Citizen::class, 'c', 'u', 'citizen', ['id' => 'citizenId', 'nickname' => 'citizenNickname']);
        $rsm->addScalarResult('countShips', 'countShips');

        $stmt = $this->_em->createNativeQuery($sql, $rsm);
        $stmt->setParameters([
            'sid' => $organizationId,
            'shipId' => $shipGalaxyId,
            'visibilityOrga' => CitizenOrganization::VISIBILITY_ORGA,
            'visibilityAdmin' => CitizenOrganization::VISIBILITY_ADMIN,
            'userPublicChoicePublic' => User::PUBLIC_CHOICE_PUBLIC,
            'userPublicChoiceOrga' => User::PUBLIC_CHOICE_ORGANIZATION,
            'viewerCitizenId' => $viewerCitizen !== null ? $viewerCitizen->getId()->toString() : null,
        ]);
        if ($page !== null) {
            $page = $page < 1 ? 1 : $page;
            $stmt->setParameter('first', ($page - 1) * $itemsPerPage);
            $stmt->setParameter('countItems', $itemsPerPage);
        }
        foreach ($filter->citizenIds as $i => $filterCitizenId) {
            $stmt->setParameter('citizenId_'.$i, $filterCitizenId);
        }

        return $stmt->getResult();
    }

    /**
     * @param Citizen|null $viewerCitizen the logged citizen
     */
    public function countOwnersOfShip(string $organizationId, UuidInterface $shipGalaxyId, ?Citizen $viewerCitizen, ShipFamilyFilter $filter): int
    {
        $userMetadata = $this->_em->getClassMetadata(User::class);
        $citizenMetadata = $this->_em->getClassMetadata(Citizen::class);
        $fleetMetadata = $this->_em->getClassMetadata(Fleet::class);
        $shipMetadata = $this->_em->getClassMetadata(Ship::class);
        $citizenOrgaMetadata = $this->_em->getClassMetadata(CitizenOrganization::class);
        $orgaMetadata = $this->_em->getClassMetadata(Organization::class);

        $sql = <<<SQL
                SELECT COUNT(DISTINCT c.id) AS total
                FROM {$orgaMetadata->getTableName()} orga
                INNER JOIN {$citizenOrgaMetadata->getTableName()} citizenOrga ON orga.id = citizenOrga.organization_id AND orga.organization_sid = :sid
                INNER JOIN {$citizenMetadata->getTableName()} c ON citizenOrga.citizen_id = c.id
                INNER JOIN {$userMetadata->getTableName()} u ON u.citizen_id = c.id
                INNER JOIN {$fleetMetadata->getTableName()} f ON f.id = c.last_fleet_id
                INNER JOIN {$shipMetadata->getTableName()} s ON s.fleet_id = f.id and s.galaxy_id = :shipId
                WHERE (
                    u.public_choice = :userPublicChoicePublic
                    OR (u.public_choice = :userPublicChoiceOrga AND (
                            # visibility ORGA : visible by everyone
                            # visibility ADMIN : visible only by highest orga rank
                            # visibility PRIVATE : visible by anybody
                            citizenOrga.visibility = :visibilityOrga
                            OR (citizenOrga.visibility = :visibilityAdmin AND :viewerCitizenId IS NOT NULL AND :viewerCitizenId IN (
                                    # select highest ranks of this orga
                                    SELECT co.citizen_id
                                    FROM {$orgaMetadata->getTableName()} o
                                    INNER JOIN {$citizenOrgaMetadata->getTableName()} co ON co.organization_id = o.id AND o.organization_sid = :sid
                                    WHERE co.rank = (
                                        SELECT max(co.rank) AS maxRank
                                        FROM {$orgaMetadata->getTableName()} o
                                        INNER JOIN {$citizenOrgaMetadata->getTableName()} co ON co.organization_id = o.id AND o.organization_sid = :sid
                                    )
                                )
                            )
                        )
                    )
                )
            SQL;

        // filtering
        if (count($filter->citizenIds) > 0) {
            $sql .= ' AND (';
            foreach ($filter->citizenIds as $i => $filterCitizenId) {
                $sql .= sprintf(' c.id = :citizenId_%d OR ', $i);
            }
            $sql .= ' 1=0) ';
        }

        $rsm = new ResultSetMappingBuilder($this->_em);
        $rsm->addScalarResult('total', 'total');

        $stmt = $this->_em->createNativeQuery($sql, $rsm);
        $stmt->setParameters([
            'sid' => $organizationId,
            'shipId' => $shipGalaxyId,
            'visibilityOrga' => CitizenOrganization::VISIBILITY_ORGA,
            'visibilityAdmin' => CitizenOrganization::VISIBILITY_ADMIN,
            'userPublicChoicePublic' => User::PUBLIC_CHOICE_PUBLIC,
            'userPublicChoiceOrga' => User::PUBLIC_CHOICE_ORGANIZATION,
            'viewerCitizenId' => $viewerCitizen !== null ? $viewerCitizen->getId()->toString() : null,
        ]);
        foreach ($filter->citizenIds as $i => $filterCitizenId) {
            $stmt->setParameter('citizenId_'.$i, $filterCitizenId);
        }

        return $stmt->getSingleScalarResult();
    }

    /**
     * @param Citizen|null $viewerCitizen the logged citizen
     */
    public function countHiddenOwnersOfShip(string $organizationId, UuidInterface $shipGalaxyId, ?Citizen $viewerCitizen): int
    {
        $userMetadata = $this->_em->getClassMetadata(User::class);
        $citizenMetadata = $this->_em->getClassMetadata(Citizen::class);
        $fleetMetadata = $this->_em->getClassMetadata(Fleet::class);
        $shipMetadata = $this->_em->getClassMetadata(Ship::class);
        $citizenOrgaMetadata = $this->_em->getClassMetadata(CitizenOrganization::class);
        $orgaMetadata = $this->_em->getClassMetadata(Organization::class);

        $sql = <<<EOT
                SELECT COUNT(DISTINCT c.id) AS total
                FROM {$orgaMetadata->getTableName()} orga
                INNER JOIN {$citizenOrgaMetadata->getTableName()} citizenOrga ON orga.id = citizenOrga.organization_id AND orga.organization_sid = :sid
                INNER JOIN {$citizenMetadata->getTableName()} c ON citizenOrga.citizen_id = c.id
                INNER JOIN {$userMetadata->getTableName()} u ON u.citizen_id = c.id
                INNER JOIN {$fleetMetadata->getTableName()} f ON f.id = c.last_fleet_id
                INNER JOIN {$shipMetadata->getTableName()} s ON s.fleet_id = f.id and s.galaxy_id = :shipId
                # notice the NOT to inverse the normal condition
                WHERE NOT (
                    u.public_choice = :userPublicChoicePublic
                    OR (u.public_choice = :userPublicChoiceOrga AND (
                            # visibility ORGA : visible by everyone
                            # visibility ADMIN : visible only by highest orga rank
                            # visibility PRIVATE : visible by anybody
                            citizenOrga.visibility = :visibilityOrga
                            OR (citizenOrga.visibility = :visibilityAdmin AND :viewerCitizenId IS NOT NULL AND :viewerCitizenId IN (
                                    # select highest ranks of this orga
                                    SELECT co.citizen_id
                                    FROM {$orgaMetadata->getTableName()} o
                                    INNER JOIN {$citizenOrgaMetadata->getTableName()} co ON co.organization_id = o.id AND o.organization_sid = :sid
                                    WHERE co.rank = (
                                        SELECT max(co.rank) AS maxRank
                                        FROM {$orgaMetadata->getTableName()} o
                                        INNER JOIN {$citizenOrgaMetadata->getTableName()} co ON co.organization_id = o.id AND o.organization_sid = :sid
                                    )
                                )
                            )
                        )
                    )
                )
            EOT;

        $rsm = new ResultSetMappingBuilder($this->_em);
        $rsm->addScalarResult('total', 'total');

        $stmt = $this->_em->createNativeQuery($sql, $rsm);
        $stmt->setParameters([
            'sid' => $organizationId,
            'shipId' => $shipGalaxyId,
            'visibilityOrga' => CitizenOrganization::VISIBILITY_ORGA,
            'visibilityAdmin' => CitizenOrganization::VISIBILITY_ADMIN,
            'userPublicChoicePublic' => User::PUBLIC_CHOICE_PUBLIC,
            'userPublicChoiceOrga' => User::PUBLIC_CHOICE_ORGANIZATION,
            'viewerCitizenId' => $viewerCitizen !== null ? $viewerCitizen->getId()->toString() : null,
        ]);

        return $stmt->getSingleScalarResult();
    }
}
