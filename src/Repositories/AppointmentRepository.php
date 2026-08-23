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
            SELECT a.id, a.user_id, a.customer_name as customer, a.service, a.stylist, a.apt_date as date, a.apt_time as time, a.apt_end_time as end_time, a.status, a.booking_type,
                   c.profile_image as customer_image, c.phone as customer_phone,
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

    public function getPaginatedAppointments(array $options = []): array
    {
        $baseSql = "FROM {$this->table} a
            LEFT JOIN customers c ON a.customer_id = c.id
            LEFT JOIN users u ON a.user_id = u.id
            WHERE a.tenant_id = :tenant_id";
        
        $params = ['tenant_id' => $this->getTenantId()];

        // Filter by date if needed
        if (!empty($options['filters']['date'])) {
            $baseSql .= " AND a.apt_date = :date";
            $params['date'] = $options['filters']['date'];
        }

        // Filter by user_id if needed
        if (!empty($options['filters']['user_id'])) {
            $baseSql .= " AND a.user_id = :user_id";
            $params['user_id'] = $options['filters']['user_id'];
        }

        $countSql = "SELECT COUNT(*) " . $baseSql;
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $page = (int)($options['page'] ?? 1);
        if ($page < 1) $page = 1;
        $limit = (int)($options['limit'] ?? 10);
        if ($limit < 1) $limit = 10;
        
        $offset = ($page - 1) * $limit;
        $totalPages = ceil($total / $limit);

        $dataSql = "SELECT a.id, a.user_id, a.customer_name as customer, a.service, a.stylist, a.apt_date as date, a.apt_time as time, a.apt_end_time as end_time, a.status, a.booking_type,
                   c.profile_image as customer_image, c.phone as customer_phone,
                   u.profile_image as stylist_image " 
                   . $baseSql . 
                   " ORDER BY a.apt_date DESC, a.apt_time DESC LIMIT :limit OFFSET :offset";
        
        $stmt = $this->db->prepare($dataSql);
        foreach ($params as $key => $val) {
            $stmt->bindValue(":$key", $val);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        
        return [
            'data' => $stmt->fetchAll(),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_pages' => (int)$totalPages
        ];
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
            INSERT INTO {$this->table} (tenant_id, customer_id, user_id, service_id, customer_name, service, stylist, apt_date, apt_time, apt_end_time, status, booking_type, customer_package_service_id)
            VALUES (:tenant_id, :customer_id, :user_id, :service_id, :customer_name, :service, :stylist, :date, :time, :end_time, :status, :booking_type, :cps_id)
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
            'status' => $data['status'] ?? 'scheduled',
            'booking_type' => $data['booking_type'] ?? 'In Salon',
            'cps_id' => !empty($data['customer_package_service_id']) ? (int)$data['customer_package_service_id'] : null
        ];
        
        $stmt->execute($insertData);
        
        $insertData['id'] = $this->db->lastInsertId();
        return $insertData;
    }

    public function getDistinctBookingTypes(): array
    {
        $stmt = $this->db->prepare("SELECT DISTINCT booking_type FROM {$this->table} WHERE tenant_id = :tenant_id AND booking_type IS NOT NULL AND booking_type != ''");
        $stmt->execute(['tenant_id' => $this->getTenantId()]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    public function getByCustomerId(int $customerId): array
    {
        $stmt = $this->db->prepare("
            SELECT a.id, a.service, a.stylist, a.apt_date as date, a.apt_time as time, a.apt_end_time as end_time, a.status, a.booking_type, u.profile_image as stylist_image
            FROM {$this->table} a
            LEFT JOIN users u ON a.user_id = u.id
            WHERE a.tenant_id = :tenant_id AND a.customer_id = :customer_id
            ORDER BY a.apt_date DESC, a.apt_time DESC
        ");
        $stmt->execute([
            'tenant_id' => $this->getTenantId(),
            'customer_id' => $customerId
        ]);
        return $stmt->fetchAll();
    }

    public function getUnbilledDoneAppointments(int $customerId): array
    {
        $stmt = $this->db->prepare("
            SELECT a.id, a.user_id, a.service, a.stylist, a.apt_date as date, a.apt_time as time, a.status,
                   s.price as service_price
            FROM {$this->table} a
            LEFT JOIN services s ON a.service_id = s.id
            WHERE a.tenant_id = :tenant_id 
              AND a.customer_id = :customer_id 
              AND a.status = 'done' 
              AND a.invoice_id IS NULL
            ORDER BY a.apt_date DESC, a.apt_time DESC
        ");
        $stmt->execute([
            'tenant_id' => $this->getTenantId(),
            'customer_id' => $customerId
        ]);
        return $stmt->fetchAll();
    }

    public function updateStatus(int $id, string $status): bool
    {
        $stmt = $this->db->prepare("UPDATE {$this->table} SET status = :status WHERE id = :id AND tenant_id = :tenant_id");
        return $stmt->execute([
            'status' => $status,
            'id' => $id,
            'tenant_id' => $this->getTenantId()
        ]);
    }

    public function getAppointmentDetails(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT a.id, a.user_id, a.customer_id, a.invoice_id, a.customer_package_service_id, a.customer_name as customer, a.service, a.stylist, a.apt_date as date, a.apt_time as time, a.apt_end_time as end_time, a.status, a.booking_type,
                   c.profile_image as customer_image, c.phone as customer_phone,
                   u.profile_image as stylist_image,
                   s.price as service_price
            FROM {$this->table} a
            LEFT JOIN customers c ON a.customer_id = c.id
            LEFT JOIN users u ON a.user_id = u.id
            LEFT JOIN services s ON a.service_id = s.id
            WHERE a.id = :id AND a.tenant_id = :tenant_id
        ");
        $stmt->execute([
            'id' => $id,
            'tenant_id' => $this->getTenantId()
        ]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function setInvoiceId(int $appointmentId, int $invoiceId): bool
    {
        $stmt = $this->db->prepare("UPDATE {$this->table} SET invoice_id = :invoice_id WHERE id = :id AND tenant_id = :tenant_id");
        return $stmt->execute([
            'invoice_id' => $invoiceId,
            'id' => $appointmentId,
            'tenant_id' => $this->getTenantId()
        ]);
    }
}
