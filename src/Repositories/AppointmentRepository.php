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
            SELECT a.id, a.user_id, a.customer_name as customer, a.service, a.stylist, a.apt_date as date, a.apt_time as time, a.apt_end_time as end_time, a.status,
                   c.profile_image as customer_image,
                   u.profile_image as stylist_image
            FROM {$this->table} a
            LEFT JOIN customers c ON a.customer_id = c.id
            LEFT JOIN users u ON a.user_id = u.id
            WHERE a.tenant_id = :tenant_id
            ORDER BY a.apt_date DESC, a.apt_time DESC
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
            INSERT INTO {$this->table} (tenant_id, customer_id, user_id, service_id, customer_name, service, stylist, apt_date, apt_time, apt_end_time, status)
            VALUES (:tenant_id, :customer_id, :user_id, :service_id, :customer_name, :service, :stylist, :date, :time, :end_time, :status)
        ");
        
        $insertData = [
            'tenant_id' => $this->getTenantId(),
            'customer_id' => !empty($data['customer_id']) ? (int)$data['customer_id'] : null,
            'user_id' => !empty($data['stylist_id']) ? (int)$data['stylist_id'] : null,
            'service_id' => !empty($data['service_id']) ? (int)$data['service_id'] : null,
            'customer_name' => $data['customer_name'] ?? 'Unknown',
            'service' => $data['service_name'] ?? 'Unknown',
            'stylist' => $data['stylist_name'] ?? 'Unknown',
            'date' => $data['date'] ?? date('Y-m-d'),
            'time' => $data['time'] ?? '12:00',
            'end_time' => $data['end_time'] ?? date('H:i', strtotime(($data['time'] ?? '12:00') . ' +1 hour')),
            'status' => 'scheduled'
        ];
        
        $stmt->execute($insertData);
        
        $insertData['id'] = $this->db->lastInsertId();
        return $insertData;
    }
}
