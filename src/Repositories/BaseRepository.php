<?php

namespace App\Repositories;

use PDO;
use Exception;

abstract class BaseRepository
{
    protected PDO $db;
    protected ?int $tenantId = null;
    protected string $table = '';

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

    /**
     * Fetch all records for the current tenant, with optional search, sort, and filter.
     */
    public function getAll(array $options = []): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE tenant_id = :tenant_id";
        $params = ['tenant_id' => $this->getTenantId()];

        // Search logic
        if (!empty($options['search'])) {
            $search = '%' . $options['search'] . '%';
            $searchableFields = $this->getSearchableFields();
            if (!empty($searchableFields)) {
                $searchConditions = [];
                foreach ($searchableFields as $field) {
                    $searchConditions[] = "$field LIKE :search";
                }
                $sql .= " AND (" . implode(" OR ", $searchConditions) . ")";
                $params['search'] = $search;
            }
        }

        // Filter logic
        if (!empty($options['filters'])) {
            foreach ($options['filters'] as $field => $value) {
                if ($value !== '') {
                    $sql .= " AND $field = :$field";
                    $params[$field] = $value;
                }
            }
        }

        // Sort logic
        $sort = $options['sort'] ?? 'id';
        $dir = strtoupper($options['dir'] ?? 'DESC');
        if (!in_array($dir, ['ASC', 'DESC'])) $dir = 'DESC';
        
        $allowedSortColumns = $this->getSortableFields();
        if (!empty($allowedSortColumns) && in_array($sort, $allowedSortColumns)) {
            $sql .= " ORDER BY $sort $dir";
        } else {
            $sql .= " ORDER BY id $dir";
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    protected function getSearchableFields(): array { return []; }
    protected function getSortableFields(): array { return ['id']; }

    /**
     * Fetch a specific record for the current tenant.
     */
    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE id = :id AND tenant_id = :tenant_id LIMIT 1");
        $stmt->execute(['id' => $id, 'tenant_id' => $this->getTenantId()]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Delete a record securely by verifying tenant ownership.
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM {$this->table} WHERE id = :id AND tenant_id = :tenant_id");
        return $stmt->execute(['id' => $id, 'tenant_id' => $this->getTenantId()]);
    }
}
