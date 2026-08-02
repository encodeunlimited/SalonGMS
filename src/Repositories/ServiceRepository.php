<?php

namespace App\Repositories;

class ServiceRepository extends BaseRepository
{
    protected string $table = 'services';

    protected function getSearchableFields(): array { return ['name', 'description']; }
    protected function getSortableFields(): array { return ['id', 'name', 'duration_minutes', 'price']; }

    public function create(array $data): array
    {
        $stmt = $this->db->prepare("
            INSERT INTO {$this->table} (tenant_id, name, description, duration_minutes, price)
            VALUES (:tenant_id, :name, :description, :duration_minutes, :price)
        ");
        
        $insertData = [
            'tenant_id' => $this->getTenantId(),
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'duration_minutes' => $data['duration_minutes'] ?? 30,
            'price' => $data['price'] ?? 0.00
        ];
        
        $stmt->execute($insertData);
        $insertData['id'] = $this->db->lastInsertId();
        return $insertData;
    }

    public function update(int $id, array $data): array
    {
        $stmt = $this->db->prepare("
            UPDATE {$this->table} 
            SET name = :name, description = :description, duration_minutes = :duration_minutes, price = :price
            WHERE id = :id AND tenant_id = :tenant_id
        ");
        
        $updateData = [
            'id' => $id,
            'tenant_id' => $this->getTenantId(),
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'duration_minutes' => $data['duration_minutes'] ?? 30,
            'price' => $data['price'] ?? 0.00
        ];
        
        $stmt->execute($updateData);
        return $updateData;
    }
}
