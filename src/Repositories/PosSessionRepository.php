<?php

namespace App\Repositories;

class PosSessionRepository extends BaseRepository
{
    protected string $table = 'pos_sessions';

    public function getActiveSession(int $tenantId)
    {
        $stmt = $this->db->prepare("
            SELECT * FROM {$this->table}
            WHERE tenant_id = :tenant_id AND status = 'open'
            ORDER BY opened_at DESC LIMIT 1
        ");
        $stmt->execute(['tenant_id' => $tenantId]);
        return $stmt->fetch() ?: null;
    }

    public function openSession(int $tenantId, int $userId, float $openingBalance)
    {
        $stmt = $this->db->prepare("
            INSERT INTO {$this->table} (tenant_id, opened_by, opening_balance, status)
            VALUES (:tenant_id, :opened_by, :opening_balance, 'open')
        ");
        $stmt->execute([
            'tenant_id' => $tenantId,
            'opened_by' => $userId,
            'opening_balance' => $openingBalance
        ]);
        return $this->db->lastInsertId();
    }

    public function closeSession(int $id, int $tenantId, int $userId, float $closingBalance, float $expectedBalance)
    {
        $stmt = $this->db->prepare("
            UPDATE {$this->table}
            SET closed_by = :closed_by, 
                closed_at = CURRENT_TIMESTAMP, 
                closing_balance = :closing_balance, 
                expected_balance = :expected_balance, 
                status = 'closed'
            WHERE id = :id AND tenant_id = :tenant_id
        ");
        return $stmt->execute([
            'id' => $id,
            'tenant_id' => $tenantId,
            'closed_by' => $userId,
            'closing_balance' => $closingBalance,
            'expected_balance' => $expectedBalance
        ]);
    }

    public function getSessions(int $tenantId, string $startDate, string $endDate): array
    {
        // Get sessions in date range, joined with users for names
        $stmt = $this->db->prepare("
            SELECT s.*, 
                   uo.name as opened_by_name, 
                   uc.name as closed_by_name
            FROM {$this->table} s
            LEFT JOIN users uo ON s.opened_by = uo.id
            LEFT JOIN users uc ON s.closed_by = uc.id
            WHERE s.tenant_id = :tenant_id 
              AND DATE(s.opened_at) >= :start_date 
              AND DATE(s.opened_at) <= :end_date
            ORDER BY s.opened_at DESC
        ");
        
        $stmt->execute([
            'tenant_id' => $tenantId,
            'start_date' => $startDate,
            'end_date' => $endDate
        ]);
        
        return $stmt->fetchAll() ?: [];
    }
    
    public function getCashSalesSince(int $tenantId, string $openedAt): float
    {
        $stmt = $this->db->prepare("
            SELECT SUM(total_amount) as total 
            FROM invoices 
            WHERE tenant_id = :tenant_id 
              AND created_at >= :opened_at 
              AND payment_method = 'Cash'
        ");
        $stmt->execute(['tenant_id' => $tenantId, 'opened_at' => $openedAt]);
        return (float)$stmt->fetchColumn();
    }
}
