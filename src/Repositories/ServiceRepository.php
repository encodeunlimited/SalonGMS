<?php

namespace App\Repositories;

class ServiceRepository extends BaseRepository
{
    protected string $table = 'services';

    protected function getSearchableFields(): array { return ['name', 'description']; }
    protected function getSortableFields(): array { return ['id', 'name', 'duration_minutes', 'price']; }

    public function getById(int $id): ?array
    {
        $service = parent::getById($id);
        if ($service && isset($service['images']) && is_string($service['images'])) {
            $service['images'] = json_decode($service['images'], true);
        }
        return $service;
    }

    public function getAll(array $options = []): array
    {
        $services = parent::getAll($options);
        foreach ($services as &$service) {
            if (isset($service['images']) && is_string($service['images'])) {
                $service['images'] = json_decode($service['images'], true);
            }
        }
        return $services;
    }

    public function create(array $data): array
    {
        $stmt = $this->db->prepare("
            INSERT INTO {$this->table} (tenant_id, name, arabic_name, category, description, images, duration_minutes, price, arabic_price)
            VALUES (:tenant_id, :name, :arabic_name, :category, :description, :images, :duration_minutes, :price, :arabic_price)
        ");
        
        $imagesJson = null;
        if (!empty($data['images']) && is_array($data['images'])) {
            $imagesJson = json_encode($data['images']);
        }
        
        $insertData = [
            'tenant_id' => $this->getTenantId(),
            'name' => $data['name'],
            'arabic_name' => $data['arabic_name'] ?? null,
            'category' => $data['category'] ?? null,
            'description' => $data['description'] ?? null,
            'images' => $imagesJson,
            'duration_minutes' => $data['duration_minutes'] ?? 30,
            'price' => $data['price'] ?? 0.00,
            'arabic_price' => isset($data['arabic_price']) && $data['arabic_price'] !== '' ? (float)$data['arabic_price'] : null
        ];
        
        $stmt->execute($insertData);
        $insertData['id'] = $this->db->lastInsertId();
        return $insertData;
    }

    public function update(int $id, array $data): array
    {
        $sql = "UPDATE {$this->table} SET name = :name, arabic_name = :arabic_name, description = :description, duration_minutes = :duration_minutes, price = :price, arabic_price = :arabic_price";
        
        $updateData = [
            'id' => $id,
            'tenant_id' => $this->getTenantId(),
            'name' => $data['name'],
            'arabic_name' => $data['arabic_name'] ?? null,
            'description' => $data['description'] ?? null,
            'duration_minutes' => $data['duration_minutes'] ?? 30,
            'price' => $data['price'] ?? 0.00,
            'arabic_price' => isset($data['arabic_price']) && $data['arabic_price'] !== '' ? (float)$data['arabic_price'] : null
        ];
        
        if (array_key_exists('images', $data)) {
            $sql .= ", images = :images";
            $updateData['images'] = !empty($data['images']) ? json_encode($data['images']) : null;
        }

        $sql .= " WHERE id = :id AND tenant_id = :tenant_id";
        
        $stmt = $this->db->prepare($sql);
        
        $stmt->execute($updateData);
        return $this->getById($id);
    }
}
