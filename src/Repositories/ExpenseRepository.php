<?php

namespace App\Repositories;

class ExpenseRepository extends BaseRepository
{
    protected string $table = 'expenses';

    public function getPaginatedExpenses(array $options = []): array
    {
        $baseSql = "FROM {$this->table} WHERE tenant_id = :tenant_id";
        $params = ['tenant_id' => $this->getTenantId()];

        if (!empty($options['filters']['start_date'])) {
            $baseSql .= " AND expense_date >= :start_date";
            $params['start_date'] = $options['filters']['start_date'];
        }

        if (!empty($options['filters']['end_date'])) {
            $baseSql .= " AND expense_date <= :end_date";
            $params['end_date'] = $options['filters']['end_date'];
        }

        if (!empty($options['filters']['category'])) {
            $baseSql .= " AND category = :category";
            $params['category'] = $options['filters']['category'];
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

        $dataSql = "SELECT * " . $baseSql . " ORDER BY expense_date DESC, id DESC LIMIT :limit OFFSET :offset";
        
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

    public function create(array $data): array
    {
        $stmt = $this->db->prepare("
            INSERT INTO {$this->table} (tenant_id, expense_date, category, amount, description, payment_method)
            VALUES (:tenant_id, :expense_date, :category, :amount, :description, :payment_method)
        ");
        
        $insertData = [
            'tenant_id' => $this->getTenantId(),
            'expense_date' => $data['expense_date'] ?? date('Y-m-d'),
            'category' => $data['category'],
            'amount' => $data['amount'],
            'description' => $data['description'] ?? null,
            'payment_method' => $data['payment_method'] ?? null
        ];
        
        $stmt->execute($insertData);
        $insertData['id'] = $this->db->lastInsertId();
        return $insertData;
    }

    public function update(int $id, array $data): bool
    {
        $stmt = $this->db->prepare("
            UPDATE {$this->table} 
            SET expense_date = :expense_date, category = :category, amount = :amount, description = :description, payment_method = :payment_method, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id AND tenant_id = :tenant_id
        ");
        
        return $stmt->execute([
            'expense_date' => $data['expense_date'],
            'category' => $data['category'],
            'amount' => $data['amount'],
            'description' => $data['description'] ?? null,
            'payment_method' => $data['payment_method'] ?? null,
            'id' => $id,
            'tenant_id' => $this->getTenantId()
        ]);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM {$this->table} WHERE id = :id AND tenant_id = :tenant_id");
        return $stmt->execute([
            'id' => $id,
            'tenant_id' => $this->getTenantId()
        ]);
    }

    public function getTotalExpensesByDateRange(string $startDate, string $endDate): float
    {
        $stmt = $this->db->prepare("SELECT SUM(amount) FROM {$this->table} WHERE tenant_id = :tenant_id AND expense_date >= :start_date AND expense_date <= :end_date");
        $stmt->execute([
            'tenant_id' => $this->getTenantId(),
            'start_date' => $startDate,
            'end_date' => $endDate
        ]);
        return (float)($stmt->fetchColumn() ?: 0.00);
    }
}
