<?php

namespace App\Repositories;

class ServiceRepository extends BaseRepository
{
    protected string $table = 'services';

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
}
