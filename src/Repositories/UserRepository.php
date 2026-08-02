<?php

namespace App\Repositories;

class UserRepository extends BaseRepository
{
    protected string $table = 'users';

    protected function getSearchableFields(): array { return ['name', 'email', 'role']; }
    protected function getSortableFields(): array { return ['id', 'name', 'email', 'role', 'commission_rate', 'created_at']; }

    public function getByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE email = :email AND tenant_id = :tenant_id LIMIT 1");
        $stmt->execute(['email' => $email, 'tenant_id' => $this->getTenantId()]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function getByEmailGlobal(string $email): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE email = :email LIMIT 1");
        $stmt->execute(['email' => $email]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function create(array $data): array
    {
        $stmt = $this->db->prepare("
            INSERT INTO {$this->table} (tenant_id, role, name, email, password, commission_rate, profile_image)
            VALUES (:tenant_id, :role, :name, :email, :password, :commission_rate, :profile_image)
        ");
        
        $insertData = [
            'tenant_id' => $this->getTenantId(),
            'role' => $data['role'] ?? 'stylist',
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => password_hash($data['password'], PASSWORD_DEFAULT),
            'commission_rate' => $data['commission_rate'] ?? 0.00,
            'profile_image' => $data['profile_image'] ?? null
        ];
        
        $stmt->execute($insertData);
        $insertData['id'] = $this->db->lastInsertId();
        unset($insertData['password']); // Never return password
        return $insertData;
    }

    public function update(int $id, array $data): array
    {
        $sql = "UPDATE {$this->table} SET role = :role, name = :name, email = :email, commission_rate = :commission_rate";
        
        $updateData = [
            'id' => $id,
            'tenant_id' => $this->getTenantId(),
            'role' => $data['role'] ?? 'stylist',
            'name' => $data['name'],
            'email' => $data['email'],
            'commission_rate' => $data['commission_rate'] ?? 0.00
        ];

        if (array_key_exists('profile_image', $data) && $data['profile_image'] !== null) {
            $sql .= ", profile_image = :profile_image";
            $updateData['profile_image'] = $data['profile_image'];
        }

        if (!empty($data['password'])) {
            $sql .= ", password = :password";
            $updateData['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
        }

        $sql .= " WHERE id = :id AND tenant_id = :tenant_id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($updateData);
        
        return $this->getById($id);
    }
}
