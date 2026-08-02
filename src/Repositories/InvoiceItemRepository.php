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
}
