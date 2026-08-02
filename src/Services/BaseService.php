<?php

namespace App\Services;

abstract class BaseService
{
    protected ?int $tenantId = null;

    /**
     * Set the current tenant ID for business logic context.
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
     * @throws \Exception
     */
    protected function getTenantId(): int
    {
        if ($this->tenantId === null) {
            throw new \Exception("Tenant ID not set in service.");
        }
        return $this->tenantId;
    }
}
