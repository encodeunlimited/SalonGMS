<?php

namespace App\Repositories;

class InvoiceItemRepository extends BaseRepository
{
    protected string $table = 'invoice_items';

    public function create(array $data): array
    {
        $stmt = $this->db->prepare("
            INSERT INTO {$this->table} (tenant_id, invoice_id, service_id, description, quantity, unit_price, subtotal)
            VALUES (:tenant_id, :invoice_id, :service_id, :description, :quantity, :unit_price, :subtotal)
        ");
        
        $insertData = [
            'tenant_id' => $this->getTenantId(),
            'invoice_id' => $data['invoice_id'],
            'service_id' => $data['service_id'] ?? null,
            'description' => $data['description'],
            'quantity' => $data['quantity'] ?? 1,
            'unit_price' => $data['unit_price'],
            'subtotal' => $data['subtotal']
        ];
        
        $stmt->execute($insertData);
        $insertData['id'] = $this->db->lastInsertId();
        return $insertData;
    }

    public function getByInvoiceId(int $invoiceId): array
    {
        $stmt = $this->db->prepare("
            SELECT ii.*, s.arabic_name as arabic_description 
            FROM {$this->table} ii
            LEFT JOIN services s ON ii.service_id = s.id
            WHERE ii.invoice_id = :invoice_id
        ");
        $stmt->execute(['invoice_id' => $invoiceId]);
        return $stmt->fetchAll();
    }
}
