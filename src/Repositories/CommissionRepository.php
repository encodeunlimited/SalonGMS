<?php

namespace App\Repositories;

class CommissionRepository extends BaseRepository
{
    protected string $table = 'commissions';

    protected function getSearchableFields(): array
    {
        return [];
    }

    protected function getSortableFields(): array
    {
        return ['id', 'user_id', 'invoice_id', 'amount', 'created_at'];
    }

    /**
     * Create a new commission record.
     */
    public function create(array $data): array
    {
        $stmt = $this->db->prepare("
            INSERT INTO {$this->table} (tenant_id, user_id, invoice_id, amount)
            VALUES (:tenant_id, :user_id, :invoice_id, :amount)
        ");
        
        $insertData = [
            'tenant_id' => $this->getTenantId(),
            'user_id' => $data['user_id'],
            'invoice_id' => $data['invoice_id'],
            'amount' => $data['amount']
        ];
        
        $stmt->execute($insertData);
        $insertData['id'] = $this->db->lastInsertId();
        return $insertData;
    }

    /**
     * Get all commissions for a specific user.
     */
    public function getByUserId(int $userId): array
    {
        $sql = "SELECT c.*, i.created_at as invoice_date 
                FROM {$this->table} c
                JOIN invoices i ON c.invoice_id = i.id
                WHERE c.tenant_id = :tenant_id AND c.user_id = :user_id
                ORDER BY c.created_at DESC";
                
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'tenant_id' => $this->getTenantId(),
            'user_id' => $userId
        ]);
        return $stmt->fetchAll();
    }

    /**
     * Get total commission amount for a specific user.
     */
    public function getTotalByUserId(int $userId): float
    {
        $sql = "SELECT SUM(amount) as total 
                FROM {$this->table} 
                WHERE tenant_id = :tenant_id AND user_id = :user_id";
                
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'tenant_id' => $this->getTenantId(),
            'user_id' => $userId
        ]);
        
        $result = $stmt->fetch();
        return (float) ($result['total'] ?? 0.00);
    }
}
