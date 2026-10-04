<?php

namespace App\Repositories;

use PDO;

class TenantSettingRepository extends BaseRepository
{
    private static array $runtimeCache = [];

    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->table = 'tenant_settings';
    }

    public function get(string $key, $default = null)
    {
        $tenantId = $this->tenantId;
        if (isset(self::$runtimeCache[$tenantId]) && array_key_exists($key, self::$runtimeCache[$tenantId])) {
            return self::$runtimeCache[$tenantId][$key];
        }

        $stmt = $this->db->prepare("SELECT setting_value FROM tenant_settings WHERE tenant_id = :tenant_id AND setting_key = :key LIMIT 1");
        $stmt->execute([
            'tenant_id' => $tenantId,
            'key' => $key
        ]);
        $row = $stmt->fetch();
        $val = $row ? $row['setting_value'] : $default;

        self::$runtimeCache[$tenantId][$key] = $val;
        return $val;
    }

    public function set(string $key, $value): void
    {
        $tenantId = $this->tenantId;
        $stmt = $this->db->prepare("SELECT id FROM tenant_settings WHERE tenant_id = :tenant_id AND setting_key = :key");
        $stmt->execute([
            'tenant_id' => $tenantId,
            'key' => $key
        ]);
        
        if ($stmt->fetch()) {
            $update = $this->db->prepare("UPDATE tenant_settings SET setting_value = :value WHERE tenant_id = :tenant_id AND setting_key = :key");
            $update->execute([
                'tenant_id' => $tenantId,
                'key' => $key,
                'value' => $value
            ]);
        } else {
            $insert = $this->db->prepare("INSERT INTO tenant_settings (tenant_id, setting_key, setting_value) VALUES (:tenant_id, :key, :value)");
            $insert->execute([
                'tenant_id' => $tenantId,
                'key' => $key,
                'value' => $value
            ]);
        }

        self::$runtimeCache[$tenantId][$key] = $value;
    }

    public function getAll(array $options = []): array
    {
        $tenantId = $this->tenantId;
        if (!isset(self::$runtimeCache[$tenantId]['_all_loaded'])) {
            $stmt = $this->db->prepare("SELECT setting_key, setting_value FROM tenant_settings WHERE tenant_id = :tenant_id");
            $stmt->execute(['tenant_id' => $tenantId]);
            $settings = [];
            while ($row = $stmt->fetch()) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
            self::$runtimeCache[$tenantId] = array_merge(self::$runtimeCache[$tenantId] ?? [], $settings);
            self::$runtimeCache[$tenantId]['_all_loaded'] = true;
        }

        $all = self::$runtimeCache[$tenantId];
        unset($all['_all_loaded']);
        return $all;
    }

    public static function clearRuntimeCache(): void
    {
        self::$runtimeCache = [];
    }
}
