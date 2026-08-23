<?php

namespace App\Repositories;

use PDO;

class TenantSettingRepository extends BaseRepository
{
    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->table = 'tenant_settings';
    }

    public function get(string $key, $default = null)
    {
        $stmt = $this->db->prepare("SELECT setting_value FROM tenant_settings WHERE tenant_id = :tenant_id AND setting_key = :key LIMIT 1");
        $stmt->execute([
            'tenant_id' => $this->tenantId,
            'key' => $key
        ]);
        $row = $stmt->fetch();
        return $row ? $row['setting_value'] : $default;
    }

    public function set(string $key, $value): void
    {
        $stmt = $this->db->prepare("SELECT id FROM tenant_settings WHERE tenant_id = :tenant_id AND setting_key = :key");
        $stmt->execute([
            'tenant_id' => $this->tenantId,
            'key' => $key
        ]);
        
        if ($stmt->fetch()) {
            $update = $this->db->prepare("UPDATE tenant_settings SET setting_value = :value WHERE tenant_id = :tenant_id AND setting_key = :key");
            $update->execute([
                'tenant_id' => $this->tenantId,
                'key' => $key,
                'value' => $value
            ]);
        } else {
            $insert = $this->db->prepare("INSERT INTO tenant_settings (tenant_id, setting_key, setting_value) VALUES (:tenant_id, :key, :value)");
            $insert->execute([
                'tenant_id' => $this->tenantId,
                'key' => $key,
                'value' => $value
            ]);
        }
    }

    public function getAll(array $options = []): array
    {
        $stmt = $this->db->prepare("SELECT setting_key, setting_value FROM tenant_settings WHERE tenant_id = :tenant_id");
        $stmt->execute(['tenant_id' => $this->tenantId]);
        $settings = [];
        while ($row = $stmt->fetch()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        return $settings;
    }
}
