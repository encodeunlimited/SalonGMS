<?php

namespace App\Repositories;

use PDO;

class ServiceCategoryRepository
{
    private PDO $db;
    private int $tenantId;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function setTenantId(int $tenantId): void
    {
        $this->tenantId = $tenantId;
    }

    public function getAll(): array
    {
        $stmt = $this->db->prepare("SELECT * FROM service_categories WHERE tenant_id = ? ORDER BY name ASC");
        $stmt->execute([$this->tenantId]);
        return $stmt->fetchAll();
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM service_categories WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$id, $this->tenantId]);
        return $stmt->fetch() ?: null;
    }

    public function create(array $data): array
    {
        $stmt = $this->db->prepare("INSERT INTO service_categories (tenant_id, name) VALUES (?, ?)");
        $stmt->execute([$this->tenantId, $data['name']]);
        
        $id = $this->db->lastInsertId();
        return $this->getById((int)$id);
    }

    public function update(int $id, array $data): bool
    {
        $stmt = $this->db->prepare("UPDATE service_categories SET name = ? WHERE id = ? AND tenant_id = ?");
        return $stmt->execute([$data['name'], $id, $this->tenantId]);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM service_categories WHERE id = ? AND tenant_id = ?");
        return $stmt->execute([$id, $this->tenantId]);
    }
}
