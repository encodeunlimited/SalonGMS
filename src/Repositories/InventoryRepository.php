<?php

namespace App\Repositories;

class InventoryRepository extends BaseRepository
{
    protected string $table = 'inventory_items';

    protected function getSearchableFields(): array
    {
        return ['name', 'sku', 'description'];
    }

    protected function getSortableFields(): array
    {
        return ['id', 'name', 'sku', 'quantity', 'price', 'created_at', 'updated_at'];
    }

    public function create(array $data): array
    {
        $tenantId = $this->getTenantId();
        
        $sql = "INSERT INTO {$this->table} (tenant_id, name, sku, description, quantity, price, expiry_date, low_stock_limit) 
                VALUES (:tenant_id, :name, :sku, :description, :quantity, :price, :expiry_date, :low_stock_limit)";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'tenant_id' => $tenantId,
            'name' => $data['name'],
            'sku' => $data['sku'] ?? null,
            'description' => $data['description'] ?? null,
            'quantity' => (int)($data['quantity'] ?? 0),
            'price' => (float)($data['price'] ?? 0.0),
            'expiry_date' => $data['expiry_date'] ?? null,
            'low_stock_limit' => (int)($data['low_stock_limit'] ?? 5)
        ]);

        $id = (int) $this->db->lastInsertId();
        return $this->getById($id);
    }

    public function update(int $id, array $data): array
    {
        $tenantId = $this->getTenantId();
        
        $sql = "UPDATE {$this->table} SET 
                name = :name,
                sku = :sku,
                description = :description,
                quantity = :quantity,
                price = :price,
                expiry_date = :expiry_date,
                low_stock_limit = :low_stock_limit,
                updated_at = CURRENT_TIMESTAMP
                WHERE id = :id AND tenant_id = :tenant_id";
                
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'id' => $id,
            'tenant_id' => $tenantId,
            'name' => $data['name'],
            'sku' => $data['sku'] ?? null,
            'description' => $data['description'] ?? null,
            'quantity' => (int)($data['quantity'] ?? 0),
            'price' => (float)($data['price'] ?? 0.0),
            'expiry_date' => array_key_exists('expiry_date', $data) ? $data['expiry_date'] : null,
            'low_stock_limit' => (int)($data['low_stock_limit'] ?? 5)
        ]);

        return $this->getById($id);
    }
}
