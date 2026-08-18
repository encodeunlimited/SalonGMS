<?php

namespace App\Repositories;

class CustomerRepository extends BaseRepository
{
    protected string $table = 'customers';

    protected function getSearchableFields(): array { return ['name', 'email', 'phone', 'notes']; }
    protected function getSortableFields(): array { return ['id', 'name', 'email', 'phone', 'created_at']; }

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
            INSERT INTO {$this->table} (tenant_id, name, phone, email, password_hash, notes, profile_image, date_of_birth)
            VALUES (:tenant_id, :name, :phone, :email, :password_hash, :notes, :profile_image, :date_of_birth)
        ");
        
        $insertData = [
            'tenant_id' => $this->getTenantId(),
            'name' => $data['name'],
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'password_hash' => !empty($data['password']) ? password_hash($data['password'], PASSWORD_DEFAULT) : null,
            'notes' => $data['notes'] ?? null,
            'profile_image' => $data['profile_image'] ?? null,
            'date_of_birth' => !empty($data['date_of_birth']) ? $data['date_of_birth'] : null
        ];
        
        $stmt->execute($insertData);
        $insertData['id'] = $this->db->lastInsertId();
        unset($insertData['password_hash']);
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

        if (array_key_exists('date_of_birth', $data)) {
            $sql .= ", date_of_birth = :date_of_birth";
            $updateData['date_of_birth'] = !empty($data['date_of_birth']) ? $data['date_of_birth'] : null;
        }

        if (!empty($data['password'])) {
            $sql .= ", password_hash = :password_hash";
            $updateData['password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
        }
        
        $sql .= " WHERE id = :id AND tenant_id = :tenant_id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($updateData);
        
        return $this->getById($id);
    }

    /**
     * Get customers whose birthday is today (month and day match).
     * Much faster than fetching all customers and filtering in PHP.
     */
    public function getTodaysBirthdays(): array
    {
        $tenantId = $this->getTenantId();
        $todayMonthDay = date('m-d'); // e.g., "08-18"
        
        // SQLite: strftime('%m-%d', date_of_birth)
        // Works with 'YYYY-MM-DD' stored dates
        $stmt = $this->db->prepare("
            SELECT id, name, phone, email, date_of_birth, profile_image
            FROM {$this->table}
            WHERE tenant_id = :tenant_id
              AND date_of_birth IS NOT NULL
              AND strftime('%m-%d', date_of_birth) = :today_md
        ");
        $stmt->execute([
            'tenant_id' => $tenantId,
            'today_md'  => $todayMonthDay
        ]);
        return $stmt->fetchAll();
    }
}
