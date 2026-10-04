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
        $stmt = $this->db->prepare("INSERT INTO service_categories (tenant_id, name, arabic_name) VALUES (?, ?, ?)");
        $stmt->execute([$this->tenantId, $data['name'], $data['arabic_name'] ?? null]);
        
        $id = $this->db->lastInsertId();
        return $this->getById((int)$id);
    }

    public function getAllWithCount(): array
    {
        $stmt = $this->db->prepare("
            SELECT sc.*, COUNT(s.id) as services_count
            FROM service_categories sc
            LEFT JOIN services s ON sc.tenant_id = s.tenant_id AND sc.name = s.category
            WHERE sc.tenant_id = ?
            GROUP BY sc.id
            ORDER BY sc.name ASC
        ");
        $stmt->execute([$this->tenantId]);
        return $stmt->fetchAll();
    }

    public function getByIdWithCount(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT sc.*, COUNT(s.id) as services_count
            FROM service_categories sc
            LEFT JOIN services s ON sc.tenant_id = s.tenant_id AND sc.name = s.category
            WHERE sc.id = ? AND sc.tenant_id = ?
            GROUP BY sc.id
        ");
        $stmt->execute([$id, $this->tenantId]);
        return $stmt->fetch() ?: null;
    }

    public function update(int $id, array $data): bool
    {
        $current = $this->getById($id);
        $stmt = $this->db->prepare("UPDATE service_categories SET name = ?, arabic_name = ? WHERE id = ? AND tenant_id = ?");
        $result = $stmt->execute([$data['name'], $data['arabic_name'] ?? null, $id, $this->tenantId]);

        // If category name changed, update existing services having this category name
        if ($result && $current && $current['name'] !== $data['name']) {
            $stmtSrv = $this->db->prepare("UPDATE services SET category = ? WHERE tenant_id = ? AND category = ?");
            $stmtSrv->execute([$data['name'], $this->tenantId, $current['name']]);
        }

        return $result;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM service_categories WHERE id = ? AND tenant_id = ?");
        return $stmt->execute([$id, $this->tenantId]);
    }
}
