<?php

namespace App\Repositories;

use PDO;

class ReportRepository extends BaseRepository
{
    protected string $table = 'invoices';

    public function getRevenueOverTime(string $startDate, string $endDate): array
    {
        $stmt = $this->db->prepare("
            SELECT DATE(created_at) as date, SUM(total_amount) as revenue
            FROM invoices 
            WHERE tenant_id = :tenant_id 
              AND status = 'paid'
              AND created_at >= :start_date 
              AND created_at <= :end_date
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ");
        $stmt->execute([
            'tenant_id' => $this->getTenantId(),
            'start_date' => $startDate . ' 00:00:00',
            'end_date' => $endDate . ' 23:59:59'
        ]);
        return $stmt->fetchAll();
    }

    public function getRevenueByPaymentMethod(string $startDate, string $endDate): array
    {
        $stmt = $this->db->prepare("
            SELECT payment_method, SUM(total_amount) as revenue
            FROM invoices 
            WHERE tenant_id = :tenant_id 
              AND status = 'paid'
              AND created_at >= :start_date 
              AND created_at <= :end_date
              AND payment_method IS NOT NULL
            GROUP BY payment_method
        ");
        $stmt->execute([
            'tenant_id' => $this->getTenantId(),
            'start_date' => $startDate . ' 00:00:00',
            'end_date' => $endDate . ' 23:59:59'
        ]);
        return $stmt->fetchAll();
    }

    public function getTopServices(string $startDate, string $endDate, int $limit = 5): array
    {
        $stmt = $this->db->prepare("
            SELECT ii.description as service_name, COUNT(ii.id) as count, SUM(ii.subtotal) as revenue
            FROM invoice_items ii
            JOIN invoices i ON ii.invoice_id = i.id
            WHERE i.tenant_id = :tenant_id 
              AND i.status = 'paid'
              AND i.created_at >= :start_date 
              AND i.created_at <= :end_date
            GROUP BY ii.description
            ORDER BY revenue DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':tenant_id', $this->getTenantId());
        $stmt->bindValue(':start_date', $startDate . ' 00:00:00');
        $stmt->bindValue(':end_date', $endDate . ' 23:59:59');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getStylistPerformance(string $startDate, string $endDate): array
    {
        $stmt = $this->db->prepare("
            SELECT u.id, u.name, 
                   COUNT(a.id) as total_appointments,
                   SUM(i.total_amount) as total_revenue
            FROM users u
            LEFT JOIN appointments a ON a.user_id = u.id AND a.status IN ('done', 'paid') AND a.apt_date >= :start_date AND a.apt_date <= :end_date
            LEFT JOIN invoices i ON a.invoice_id = i.id AND i.status = 'paid'
            WHERE u.tenant_id = :tenant_id AND u.role = 'stylist'
            GROUP BY u.id, u.name
            ORDER BY total_revenue DESC
        ");
        $stmt->execute([
            'tenant_id' => $this->getTenantId(),
            'start_date' => $startDate,
            'end_date' => $endDate
        ]);
        $performance = $stmt->fetchAll();
        
        $stmt2 = $this->db->prepare("
            SELECT user_id, SUM(amount) as total_commission
            FROM commissions
            WHERE tenant_id = :tenant_id AND created_at >= :start_date AND created_at <= :end_date
            GROUP BY user_id
        ");
        $stmt2->execute([
            'tenant_id' => $this->getTenantId(),
            'start_date' => $startDate . ' 00:00:00',
            'end_date' => $endDate . ' 23:59:59'
        ]);
        $commissions = [];
        foreach ($stmt2->fetchAll() as $row) {
            $commissions[$row['user_id']] = $row['total_commission'];
        }

        foreach ($performance as &$perf) {
            $perf['total_commission'] = $commissions[$perf['id']] ?? 0.00;
        }

        return $performance;
    }

    public function getTopCustomers(string $startDate, string $endDate, int $limit = 5): array
    {
        $stmt = $this->db->prepare("
            SELECT c.id, c.name, COUNT(i.id) as visits, SUM(i.total_amount) as total_spent
            FROM customers c
            JOIN invoices i ON i.customer_id = c.id
            WHERE c.tenant_id = :tenant_id 
              AND i.status = 'paid'
              AND i.created_at >= :start_date 
              AND i.created_at <= :end_date
            GROUP BY c.id, c.name
            ORDER BY total_spent DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':tenant_id', $this->getTenantId());
        $stmt->bindValue(':start_date', $startDate . ' 00:00:00');
        $stmt->bindValue(':end_date', $endDate . ' 23:59:59');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getAppointmentsSummary(string $startDate, string $endDate): array
    {
        $stmt = $this->db->prepare("
            SELECT status, COUNT(*) as count
            FROM appointments
            WHERE tenant_id = :tenant_id 
              AND apt_date >= :start_date 
              AND apt_date <= :end_date
            GROUP BY status
        ");
        $stmt->execute([
            'tenant_id' => $this->getTenantId(),
            'start_date' => $startDate,
            'end_date' => $endDate
        ]);
        return $stmt->fetchAll();
    }
}
