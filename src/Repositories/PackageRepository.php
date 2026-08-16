<?php

namespace App\Repositories;

use PDO;

class PackageRepository extends BaseRepository
{
    protected string $table = 'packages';

    public function create(array $data): array
    {
        $stmt = $this->db->prepare("
            INSERT INTO {$this->table} (tenant_id, name, description, price, active)
            VALUES (:tenant_id, :name, :description, :price, :active)
        ");
        
        $insertData = [
            'tenant_id' => $this->getTenantId(),
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'price' => $data['price'] ?? 0.00,
            'active' => $data['active'] ?? 1
        ];
        
        $stmt->execute($insertData);
        $insertData['id'] = $this->db->lastInsertId();
        
        if (!empty($data['service_ids']) && is_array($data['service_ids'])) {
            $this->syncServices($insertData['id'], $data['service_ids']);
        }

        return $insertData;
    }

    public function update(int $id, array $data): bool
    {
        $updateFields = [];
        $updateParams = ['id' => $id, 'tenant_id' => $this->getTenantId()];
        
        $allowedFields = ['name', 'description', 'price', 'active'];
        
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
        $result = $stmt->execute($updateParams);

        if (isset($data['service_ids']) && is_array($data['service_ids'])) {
            $this->syncServices($id, $data['service_ids']);
        }

        return $result;
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE id = :id AND tenant_id = :tenant_id LIMIT 1");
        $stmt->execute(['id' => $id, 'tenant_id' => $this->getTenantId()]);
        $package = $stmt->fetch();
        
        if ($package) {
            $package['services'] = $this->getPackageServices($package['id']);
        }
        
        return $package ?: null;
    }

    public function getAll(array $options = []): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE tenant_id = :tenant_id";
        $params = ['tenant_id' => $this->getTenantId()];

        if (isset($options['active'])) {
            $sql .= " AND active = :active";
            $params['active'] = $options['active'];
        }

        $sql .= " ORDER BY id DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $packages = $stmt->fetchAll();

        foreach ($packages as &$package) {
            $package['services'] = $this->getPackageServices($package['id']);
        }

        return $packages;
    }

    private function getPackageServices(int $packageId): array
    {
        $stmt = $this->db->prepare("
            SELECT s.* 
            FROM services s
            JOIN package_items pi ON s.id = pi.service_id
            WHERE pi.package_id = :package_id
        ");
        $stmt->execute(['package_id' => $packageId]);
        return $stmt->fetchAll();
    }

    private function syncServices(int $packageId, array $serviceIds): void
    {
        // First delete all existing items
        $stmt = $this->db->prepare("DELETE FROM package_items WHERE package_id = :package_id");
        $stmt->execute(['package_id' => $packageId]);

        // Insert new ones
        if (!empty($serviceIds)) {
            $stmt = $this->db->prepare("INSERT INTO package_items (package_id, service_id) VALUES (:package_id, :service_id)");
            foreach ($serviceIds as $serviceId) {
                $stmt->execute(['package_id' => $packageId, 'service_id' => $serviceId]);
            }
        }
    }
}
