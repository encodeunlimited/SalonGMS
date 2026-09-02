<?php

namespace App\Repositories;

use PDO;

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

    /**
     * Get total commissions across all users within a date range.
     */
    public function getTotalCommissionsByDateRange(string $startDate, string $endDate): float
    {
        $sql = "SELECT SUM(amount) as total 
                FROM {$this->table} 
                WHERE tenant_id = :tenant_id 
                  AND created_at >= :start_date 
                  AND created_at <= :end_date";
                  
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'tenant_id' => $this->getTenantId(),
            'start_date' => $startDate . ' 00:00:00',
            'end_date' => $endDate . ' 23:59:59'
        ]);
        
        $result = $stmt->fetch();
        return (float) ($result['total'] ?? 0.00);
    }

    public function getPaginatedCommissions(array $options = []): array
    {
        $baseSql = "FROM {$this->table} c
                JOIN invoices i ON c.invoice_id = i.id
                WHERE c.tenant_id = :tenant_id";
        
        $params = ['tenant_id' => $this->getTenantId()];

        if (!empty($options['filters']['user_id'])) {
            $baseSql .= " AND c.user_id = :user_id";
            $params['user_id'] = $options['filters']['user_id'];
        }

        if (!empty($options['search'])) {
            $baseSql .= " AND (c.invoice_id LIKE :search OR c.amount LIKE :search)";
            $params['search'] = "%{$options['search']}%";
        }

        $countSql = "SELECT COUNT(*) " . $baseSql;
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $page = (int)($options['page'] ?? 1);
        if ($page < 1) $page = 1;
        $limit = (int)($options['limit'] ?? 10);
        if ($limit < 1) $limit = 10;
        
        $offset = ($page - 1) * $limit;
        $totalPages = ceil($total / $limit);
        
        $allowedSorts = [
            'id' => 'c.id', 
            'invoice_id' => 'c.invoice_id',
            'amount' => 'c.amount',
            'invoice_date' => 'i.created_at',
            'created_at' => 'c.created_at'
        ];
        $sort = $options['sort'] ?? 'created_at';
        $sortColumn = $allowedSorts[$sort] ?? 'c.created_at';
        
        $dir = strtoupper($options['dir'] ?? 'DESC');
        if (!in_array($dir, ['ASC', 'DESC'])) $dir = 'DESC';
        
        $orderSql = "ORDER BY {$sortColumn} {$dir}";

        $dataSql = "SELECT c.*, i.created_at as invoice_date " . $baseSql . " " . $orderSql . " LIMIT :limit OFFSET :offset";
        
        $stmt = $this->db->prepare($dataSql);
        foreach ($params as $key => $val) {
            $stmt->bindValue(":$key", $val);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        
        return [
            'data' => $stmt->fetchAll(),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_pages' => (int)$totalPages
        ];
    }
}
