<?php

namespace App\Repositories;

use PDO;
use Exception;

abstract class BaseRepository
{
    protected PDO $db;
    protected ?int $tenantId = null;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Set the current tenant ID for data isolation.
     * 
     * @param int $tenantId
     * @return self
     */
    public function setTenantId(int $tenantId): self
    {
        $this->tenantId = $tenantId;
        return $this;
    }

    /**
     * Get the current tenant ID. Throws an exception if not set.
     * 
     * @return int
     * @throws Exception
     */
    protected function getTenantId(): int
    {
        if ($this->tenantId === null) {
            throw new Exception("Tenant ID not set in repository. Possible multi-tenancy violation.");
        }
        return $this->tenantId;
    }
}
