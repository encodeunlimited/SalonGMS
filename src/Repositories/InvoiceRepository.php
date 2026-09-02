<?php

namespace App\Repositories;

use PDO;

class InvoiceRepository extends BaseRepository
{
    protected string $table = 'invoices';

    public function create(array $data): array
    {
        $stmt = $this->db->prepare("
            INSERT INTO {$this->table} (tenant_id, appointment_id, customer_id, total_amount, status, payment_method, tender_amount, change_amount, split_details)
            VALUES (:tenant_id, :appointment_id, :customer_id, :total_amount, :status, :payment_method, :tender_amount, :change_amount, :split_details)
        ");
        
        $insertData = [
            'tenant_id' => $this->getTenantId(),
            'appointment_id' => $data['appointment_id'] ?? null,
            'customer_id' => $data['customer_id'] ?? null,
            'total_amount' => $data['total_amount'] ?? 0.00,
            'status' => $data['status'] ?? 'unpaid',
            'payment_method' => $data['payment_method'] ?? null,
            'tender_amount' => $data['tender_amount'] ?? null,
            'change_amount' => $data['change_amount'] ?? null,
            'split_details' => $data['split_details'] ?? null
        ];
        
        $stmt->execute($insertData);
        $insertData['id'] = $this->db->lastInsertId();
        return $insertData;
    }

    public function update(int $id, array $data): bool
    {
        $updateFields = [];
        $updateParams = ['id' => $id, 'tenant_id' => $this->getTenantId()];
        
        $allowedFields = ['status', 'payment_method', 'tender_amount', 'change_amount', 'split_details'];
        
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updateFields[] = "$field = :$field";
                $updateParams[$field] = $data[$field];
            }
        }
        
        if (empty($updateFields)) {
            return false;
        }
        
        $sql = "UPDATE {$this->table} SET " . implode(', ', $updateFields) . " WHERE id = :id AND tenant_id = :tenant_id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($updateParams);
    }


    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE id = :id AND tenant_id = :tenant_id LIMIT 1");
        $stmt->execute(['id' => $id, 'tenant_id' => $this->getTenantId()]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Fetch a specific record globally (public view).
     */
    public function getByIdPublic(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT i.*, c.name as customer_name, c.email as customer_email, c.phone as customer_phone,
                   (SELECT GROUP_CONCAT(DISTINCT a.stylist) FROM appointments a WHERE a.invoice_id = i.id OR a.id = i.appointment_id) as stylist_name,
                   (SELECT GROUP_CONCAT(ii.description, ', ') FROM invoice_items ii WHERE ii.invoice_id = i.id) as service_names
            FROM {$this->table} i
            LEFT JOIN customers c ON i.customer_id = c.id
            WHERE i.id = :id LIMIT 1
        ");
        $stmt->execute(['id' => $id]);
        $result = $stmt->fetch();
        return $result ?: null;
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
            SELECT i.id, i.total_amount, i.status, i.payment_method, i.created_at, 
                   COALESCE(
                       (SELECT GROUP_CONCAT(ii.description, ', ') FROM invoice_items ii WHERE ii.invoice_id = i.id),
                       a.service
                   ) as service, 
                   a.apt_date
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

    public function getUnpaidInvoicesWithCustomer(): array
    {
        $stmt = $this->db->prepare("
            SELECT i.id, i.total_amount, i.created_at, i.status, 
                   c.id as customer_id, c.name as customer_name, c.profile_image, c.phone
            FROM {$this->table} i
            LEFT JOIN customers c ON i.customer_id = c.id
            WHERE i.tenant_id = :tenant_id AND i.status != 'paid'
            ORDER BY i.created_at ASC
        ");
        $stmt->execute(['tenant_id' => $this->getTenantId()]);
        return $stmt->fetchAll();
    }

    public function getHistoryPaginated(string $search = '', string $sort = 'created_at', string $dir = 'desc', int $limit = 10, int $offset = 0, ?int $customerId = null): array
    {
        $allowedSorts = ['id' => 'i.id', 'created_at' => 'i.created_at', 'customer_name' => 'c.name', 'total_amount' => 'i.total_amount', 'status' => 'i.status', 'payment_method' => 'i.payment_method'];
        $sortColumn = $allowedSorts[$sort] ?? 'i.created_at';
        $direction = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';

        $where = "i.tenant_id = :tenant_id";
        $params = [':tenant_id' => $this->getTenantId(), ':limit' => $limit, ':offset' => $offset];
        
        if ($customerId !== null) {
            $where .= " AND i.customer_id = :customer_id";
            $params[':customer_id'] = $customerId;
        }

        if ($search) {
            $where .= " AND (i.id LIKE :search OR c.name LIKE :search OR i.status LIKE :search OR i.payment_method LIKE :search)";
            $params[':search'] = "%{$search}%";
        }

        $stmt = $this->db->prepare("
            SELECT i.id, i.total_amount, i.status, i.payment_method, i.created_at, 
                   c.name as customer_name,
                   COALESCE(
                       (SELECT GROUP_CONCAT(ii.description, ', ') FROM invoice_items ii WHERE ii.invoice_id = i.id),
                       a.service
                   ) as service_names
            FROM {$this->table} i
            LEFT JOIN customers c ON i.customer_id = c.id
            LEFT JOIN appointments a ON i.appointment_id = a.id
            WHERE {$where}
            ORDER BY {$sortColumn} {$direction}
            LIMIT :limit OFFSET :offset
        ");
        
        foreach ($params as $key => $value) {
            $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue($key, $value, $type);
        }
        
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }

    public function getHistoryCount(string $search = '', ?int $customerId = null): int
    {
        $where = "i.tenant_id = :tenant_id";
        $params = [':tenant_id' => $this->getTenantId()];
        
        if ($customerId !== null) {
            $where .= " AND i.customer_id = :customer_id";
            $params[':customer_id'] = $customerId;
        }

        if ($search) {
            $where .= " AND (i.id LIKE :search OR c.name LIKE :search OR i.status LIKE :search OR i.payment_method LIKE :search)";
            $params[':search'] = "%{$search}%";
        }

        $stmt = $this->db->prepare("
            SELECT COUNT(i.id) 
            FROM {$this->table} i
            LEFT JOIN customers c ON i.customer_id = c.id
            WHERE {$where}
        ");
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }
}
