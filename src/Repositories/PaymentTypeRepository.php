<?php

namespace App\Repositories;

class PaymentTypeRepository extends BaseRepository
{
    protected string $table = 'payment_types';

    public function create(array $data): array
    {
        $stmt = $this->db->prepare("
            INSERT INTO {$this->table} (tenant_id, name)
            VALUES (:tenant_id, :name)
        ");
        
        $insertData = [
            'tenant_id' => $this->getTenantId(),
            'name' => $data['name']
        ];
        
        $stmt->execute($insertData);
        $insertData['id'] = $this->db->lastInsertId();
        return $insertData;
    }
    
    public function update(int $id, array $data): bool
    {
        $stmt = $this->db->prepare("
            UPDATE {$this->table} 
            SET name = :name
            WHERE id = :id AND tenant_id = :tenant_id
        ");
        
        return $stmt->execute([
            'id' => $id,
            'tenant_id' => $this->getTenantId(),
            'name' => $data['name']
        ]);
    }
}
