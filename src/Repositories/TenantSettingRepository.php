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
        $stmt = $this->db->prepare("
            INSERT INTO tenant_settings (tenant_id, setting_key, setting_value)
            VALUES (:tenant_id, :key, :value)
            ON DUPLICATE KEY UPDATE setting_value = :value
        ");
        
        try {
            $stmt->execute([
                'tenant_id' => $this->tenantId,
                'key' => $key,
                'value' => $value
            ]);
        } catch (\PDOException $e) {
            // For SQLite fallback since ON DUPLICATE KEY UPDATE is MySQL specific
            if (str_contains($e->getMessage(), 'syntax error')) {
                $stmt = $this->db->prepare("
                    INSERT INTO tenant_settings (tenant_id, setting_key, setting_value)
                    VALUES (:tenant_id, :key, :value)
                    ON CONFLICT(tenant_id, setting_key) DO UPDATE SET setting_value = :value
                ");
                $stmt->execute([
                    'tenant_id' => $this->tenantId,
                    'key' => $key,
                    'value' => $value
                ]);
            } else {
                throw $e;
            }
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
