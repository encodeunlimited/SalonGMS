<?php

namespace App\Repositories;

use PDO;

class AppointmentRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getAllForTenant(int $tenantId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, customer_name as customer, service, stylist, apt_date as date, apt_time as time, status
            FROM appointments
            WHERE tenant_id = :tenant_id
            ORDER BY apt_date DESC, apt_time DESC
        ");
        $stmt->execute(['tenant_id' => $tenantId]);
        return $stmt->fetchAll();
    }

    public function create(int $tenantId, array $data): array
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO appointments (tenant_id, customer_name, service, stylist, apt_date, apt_time, status)
            VALUES (:tenant_id, :customer, :service, :stylist, :date, :time, :status)
        ");
        
        $insertData = [
            'tenant_id' => $tenantId,
            'customer' => $data['customer_name'] ?? 'Unknown',
            'service' => $data['service'] ?? 'Unknown',
            'stylist' => $data['stylist'] ?? 'Unknown',
            'date' => $data['date'] ?? date('Y-m-d'),
            'time' => $data['time'] ?? '12:00 PM',
            'status' => 'pending'
        ];
        
        $stmt->execute($insertData);
        
        $insertData['id'] = $this->pdo->lastInsertId();
        return $insertData;
    }
}
