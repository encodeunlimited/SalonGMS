<?php

namespace App\Repositories;

class CustomerRepository extends BaseRepository
{
    protected string $table = 'customers';

    protected function getSearchableFields(): array { return ['name', 'email', 'phone', 'notes']; }
    protected function getSortableFields(): array { return ['id', 'name', 'email', 'phone', 'created_at']; }

    public function create(array $data): array
    {
        $stmt = $this->db->prepare("
            INSERT INTO {$this->table} (tenant_id, name, phone, email, notes, profile_image)
            VALUES (:tenant_id, :name, :phone, :email, :notes, :profile_image)
        ");
        
        $insertData = [
            'tenant_id' => $this->getTenantId(),
            'name' => $data['name'],
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'notes' => $data['notes'] ?? null,
            'profile_image' => $data['profile_image'] ?? null
        ];
        
        $stmt->execute($insertData);
        $insertData['id'] = $this->db->lastInsertId();
        return $insertData;
    }

    public function update(int $id, array $data): array
    {
        $sql = "UPDATE {$this->table} SET name = :name, phone = :phone, email = :email, notes = :notes";
        
        $updateData = [
            'id' => $id,
            'tenant_id' => $this->getTenantId(),
            'name' => $data['name'],
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'notes' => $data['notes'] ?? null
        ];

        if (array_key_exists('profile_image', $data) && $data['profile_image'] !== null) {
            $sql .= ", profile_image = :profile_image";
            $updateData['profile_image'] = $data['profile_image'];
        }
        
        $sql .= " WHERE id = :id AND tenant_id = :tenant_id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($updateData);
        
        return $this->getById($id);
    }
}
