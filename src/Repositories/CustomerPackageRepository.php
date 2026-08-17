<?php

namespace App\Repositories;

use PDO;

class CustomerPackageRepository extends BaseRepository
{
    protected string $table = 'customer_packages';

    public function create(array $data): array
    {
        $stmt = $this->db->prepare("
            INSERT INTO {$this->table} (tenant_id, customer_id, package_id, status)
            VALUES (:tenant_id, :customer_id, :package_id, :status)
        ");
        
        $insertData = [
            'tenant_id' => $this->getTenantId(),
            'customer_id' => $data['customer_id'],
            'package_id' => $data['package_id'],
            'status' => $data['status'] ?? 'active'
        ];
        
        $stmt->execute($insertData);
        $insertData['id'] = $this->db->lastInsertId();

        return $insertData;
    }

    public function addService(int $customerPackageId, int $serviceId, int $quantity = 1): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO customer_package_services (tenant_id, customer_package_id, service_id, total_quantity, used_quantity)
            VALUES (:tenant_id, :customer_package_id, :service_id, :total_quantity, 0)
        ");
        
        $stmt->execute([
            'tenant_id' => $this->getTenantId(),
            'customer_package_id' => $customerPackageId,
            'service_id' => $serviceId,
            'total_quantity' => $quantity
        ]);
    }

    public function getAvailableServicesForCustomer(int $customerId): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                cps.id as customer_package_service_id,
                cps.total_quantity,
                cps.used_quantity,
                cp.id as customer_package_id,
                p.name as package_name,
                s.id as service_id,
                s.name as service_name,
                s.duration_minutes
            FROM customer_package_services cps
            JOIN customer_packages cp ON cps.customer_package_id = cp.id
            JOIN packages p ON cp.package_id = p.id
            JOIN services s ON cps.service_id = s.id
            WHERE cp.tenant_id = :tenant_id
              AND cp.customer_id = :customer_id
              AND cp.status = 'active'
              AND cps.used_quantity < cps.total_quantity
        ");
        
        $stmt->execute([
            'tenant_id' => $this->getTenantId(),
            'customer_id' => $customerId
        ]);
        
        return $stmt->fetchAll();
    }

    public function getCustomerPackageServiceById(int $cpsId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT cps.*, cp.customer_id, s.name as service_name, s.duration_minutes
            FROM customer_package_services cps
            JOIN customer_packages cp ON cps.customer_package_id = cp.id
            JOIN services s ON cps.service_id = s.id
            WHERE cps.id = :id AND cps.tenant_id = :tenant_id
        ");
        $stmt->execute([
            'id' => $cpsId,
            'tenant_id' => $this->getTenantId()
        ]);
        
        return $stmt->fetch() ?: null;
    }

    public function incrementUsedQuantity(int $cpsId): bool
    {
        $stmt = $this->db->prepare("
            UPDATE customer_package_services 
            SET used_quantity = used_quantity + 1 
            WHERE id = :id AND tenant_id = :tenant_id AND used_quantity < total_quantity
        ");
        
        return $stmt->execute([
            'id' => $cpsId,
            'tenant_id' => $this->getTenantId()
        ]);
    }

    public function decrementUsedQuantity(int $cpsId): bool
    {
        $stmt = $this->db->prepare("
            UPDATE customer_package_services 
            SET used_quantity = used_quantity - 1 
            WHERE id = :id AND tenant_id = :tenant_id AND used_quantity > 0
        ");
        
        return $stmt->execute([
            'id' => $cpsId,
            'tenant_id' => $this->getTenantId()
        ]);
    }
}
