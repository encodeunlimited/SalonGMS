<?php

namespace App\Repositories;

use PDO;

class InvoiceRepository extends BaseRepository
{
    protected string $table = 'invoices';

    public function create(array $data): array
    {
        $stmt = $this->db->prepare("
            INSERT INTO {$this->table} (tenant_id, appointment_id, customer_id, total_amount, status, payment_method)
            VALUES (:tenant_id, :appointment_id, :customer_id, :total_amount, :status, :payment_method)
        ");
        
        $insertData = [
            'tenant_id' => $this->getTenantId(),
            'appointment_id' => $data['appointment_id'] ?? null,
            'customer_id' => $data['customer_id'] ?? null,
            'total_amount' => $data['total_amount'] ?? 0.00,
            'status' => $data['status'] ?? 'unpaid',
            'payment_method' => $data['payment_method'] ?? null
        ];
        
        $stmt->execute($insertData);
        $insertData['id'] = $this->db->lastInsertId();
        return $insertData;
    }

    public function getDistinctPaymentMethods(): array
    {
        $stmt = $this->db->prepare("SELECT DISTINCT payment_method FROM {$this->table} WHERE tenant_id = :tenant_id AND payment_method IS NOT NULL AND payment_method != ''");
        $stmt->execute(['tenant_id' => $this->getTenantId()]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    public function getByCustomerId(int $customerId): array
    {
        $stmt = $this->db->prepare("
            SELECT i.id, i.total_amount, i.status, i.payment_method, i.created_at, a.service, a.apt_date
            FROM {$this->table} i
            LEFT JOIN appointments a ON i.appointment_id = a.id
            WHERE i.tenant_id = :tenant_id AND i.customer_id = :customer_id
            ORDER BY i.created_at DESC
        ");
        $stmt->execute([
            'tenant_id' => $this->getTenantId(),
            'customer_id' => $customerId
        ]);
        return $stmt->fetchAll();
    }
}
