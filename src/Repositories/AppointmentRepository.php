<?php

namespace App\Repositories;

use PDO;

class AppointmentRepository extends BaseRepository
{
    protected string $table = 'appointments';

    public function getAllForTenant(): array
    {
        // Override because we want specific columns mapped and ordering
        $stmt = $this->db->prepare("
            SELECT id, customer_name as customer, service, stylist, apt_date as date, apt_time as time, status
            FROM {$this->table}
            WHERE tenant_id = :tenant_id
            ORDER BY apt_date DESC, apt_time DESC
        ");
        $stmt->execute(['tenant_id' => $this->getTenantId()]);
        return $stmt->fetchAll();
    }

    public function getByDateAndStylist(string $date, string $stylist): array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE tenant_id = :tenant_id AND apt_date = :date AND stylist = :stylist");
        $stmt->execute([
            'tenant_id' => $this->getTenantId(),
            'date' => $date,
            'stylist' => $stylist
        ]);
        return $stmt->fetchAll();
    }

    public function create(array $data): array
    {
        $stmt = $this->db->prepare("
            INSERT INTO {$this->table} (tenant_id, customer_name, service, stylist, apt_date, apt_time, status)
            VALUES (:tenant_id, :customer, :service, :stylist, :date, :time, :status)
        ");
        
        $insertData = [
            'tenant_id' => $this->getTenantId(),
            'customer' => $data['customer_name'] ?? 'Unknown',
            'service' => $data['service'] ?? 'Unknown',
            'stylist' => $data['stylist'] ?? 'Unknown',
            'date' => $data['date'] ?? date('Y-m-d'),
            'time' => $data['time'] ?? '12:00',
            'status' => 'pending'
        ];
        
        $stmt->execute($insertData);
        
        $insertData['id'] = $this->db->lastInsertId();
        return $insertData;
    }
}
