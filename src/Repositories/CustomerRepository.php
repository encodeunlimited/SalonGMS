<?php

namespace App\Repositories;

class CustomerRepository extends BaseRepository
{
    protected string $table = 'customers';

    public function create(array $data): array
    {
        $stmt = $this->db->prepare("
            INSERT INTO {$this->table} (tenant_id, name, phone, email, notes)
            VALUES (:tenant_id, :name, :phone, :email, :notes)
        ");
        
        $insertData = [
            'tenant_id' => $this->getTenantId(),
            'name' => $data['name'],
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'notes' => $data['notes'] ?? null
        ];
        
        $stmt->execute($insertData);
        $insertData['id'] = $this->db->lastInsertId();
        return $insertData;
    }
}
