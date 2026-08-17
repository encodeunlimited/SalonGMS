<?php

namespace App\Repositories;

use PDO;

class PackageRepository extends BaseRepository
{
    protected string $table = 'packages';

    public function create(array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO {$this->table} (tenant_id, name, description, price, active, validity_months)
            VALUES (:tenant_id, :name, :description, :price, :active, :validity_months)
        ");
        
        $stmt->execute([
            'tenant_id' => $this->getTenantId(),
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'price' => $data['price'],
            'active' => $data['active'] ?? 1,
            'validity_months' => !empty($data['validity_months']) ? (int)$data['validity_months'] : null
        ]);

        $packageId = (int)$this->db->lastInsertId();
        
        if (!empty($data['service_ids']) && is_array($data['service_ids'])) {
            $this->syncServices($packageId, $data['service_ids']);
        }

        return $packageId;
    }

    public function update(int $id, array $data): bool
    {
        $stmt = $this->db->prepare("
            UPDATE {$this->table}
            SET name = :name,
                description = :description,
                price = :price,
                active = :active,
                validity_months = :validity_months
            WHERE id = :id AND tenant_id = :tenant_id
        ");
        
        $result = $stmt->execute([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'price' => $data['price'],
            'active' => $data['active'] ?? 1,
            'validity_months' => !empty($data['validity_months']) ? (int)$data['validity_months'] : null,
            'id' => $id,
            'tenant_id' => $this->getTenantId()
        ]);

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
