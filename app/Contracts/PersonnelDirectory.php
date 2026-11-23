<?php

namespace App\Contracts;

use App\Data\PersonData;

/**
 * Where people (inspectors, end-users, property custodians) come from.
 * Today: LocalPersonnelDirectory (the dummy `personnel` table). Phase 13: a UMIS
 * implementation. Nothing else in the app may read the `personnel` table directly.
 */
interface PersonnelDirectory
{
    public const ROLE_INSPECTOR = 'inspector';

    public const ROLE_END_USER = 'end-user';

    public const ROLE_PROPERTY_CUSTODIAN = 'property-custodian';

    /** The role names every implementation must understand. */
    public const ROLES = [
        self::ROLE_INSPECTOR,
        self::ROLE_END_USER,
        self::ROLE_PROPERTY_CUSTODIAN,
    ];

    public const DEFAULT_LIMIT = 20;

    public const MAX_LIMIT = 50;

    /**
     * Active people only, ordered by name. $term matches name (and employee no.).
     * An unknown $role returns nobody (it never widens the search).
     *
     * @return list<PersonData>
     */
    public function search(?string $term = null, ?string $role = null, int $limit = self::DEFAULT_LIMIT): array;

    /**
     * One person by id, active or not (a past signatory must still be readable).
     */
    public function find(int|string $id): ?PersonData;
}