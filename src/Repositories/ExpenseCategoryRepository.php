<?php

namespace App\Repositories;

use PDO;

class ExpenseCategoryRepository
{
    protected PDO $db;
    protected ?int $tenantId = null;

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
        if (!$this->tenantId) return [];
        $stmt = $this->db->prepare("SELECT * FROM expense_categories WHERE tenant_id = ? ORDER BY name ASC");
        $stmt->execute([$this->tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById(int $id): ?array
    {
        if (!$this->tenantId) return null;
        $stmt = $this->db->prepare("SELECT * FROM expense_categories WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$id, $this->tenantId]);
        $category = $stmt->fetch(PDO::FETCH_ASSOC);
        return $category ?: null;
    }

    public function create(array $data): array
    {
        if (!$this->tenantId) throw new \Exception("Tenant ID not set");
        
        $sql = "INSERT INTO expense_categories (tenant_id, name) VALUES (?, ?)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $this->tenantId,
            $data['name']
        ]);
        
        $id = (int)$this->db->lastInsertId();
        return $this->getById($id);
    }

    public function update(int $id, array $data): bool
    {
        if (!$this->tenantId) throw new \Exception("Tenant ID not set");
        
        $sql = "UPDATE expense_categories SET name = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND tenant_id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            $data['name'],
            $id,
            $this->tenantId
        ]);
    }

    public function delete(int $id): bool
    {
        if (!$this->tenantId) throw new \Exception("Tenant ID not set");
        
        $sql = "DELETE FROM expense_categories WHERE id = ? AND tenant_id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$id, $this->tenantId]);
    }
}
