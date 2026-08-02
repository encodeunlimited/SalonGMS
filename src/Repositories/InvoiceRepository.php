<?php

namespace App\Repositories;

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
}
