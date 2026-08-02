<?php

namespace App\Repositories;

class UserRepository extends BaseRepository
{
    protected string $table = 'users';

    public function getByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE email = :email AND tenant_id = :tenant_id LIMIT 1");
        $stmt->execute(['email' => $email, 'tenant_id' => $this->getTenantId()]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function create(array $data): array
    {
        $stmt = $this->db->prepare("
            INSERT INTO {$this->table} (tenant_id, role, name, email, password, commission_rate)
            VALUES (:tenant_id, :role, :name, :email, :password, :commission_rate)
        ");
        
        $insertData = [
            'tenant_id' => $this->getTenantId(),
            'role' => $data['role'] ?? 'stylist',
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => password_hash($data['password'], PASSWORD_DEFAULT),
            'commission_rate' => $data['commission_rate'] ?? 0.00
        ];
        
        $stmt->execute($insertData);
        $insertData['id'] = $this->db->lastInsertId();
        unset($insertData['password']); // Never return password
        return $insertData;
    }
}
