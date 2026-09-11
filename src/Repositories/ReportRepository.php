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
            SELECT total_amount, payment_method, split_details
            FROM invoices 
            WHERE tenant_id = :tenant_id 
              AND status = 'paid'
              AND created_at >= :start_date 
              AND created_at <= :end_date
              AND payment_method IS NOT NULL
        ");
        $stmt->execute([
            'tenant_id' => $this->getTenantId(),
            'start_date' => $startDate . ' 00:00:00',
            'end_date' => $endDate . ' 23:59:59'
        ]);
        
        $invoices = $stmt->fetchAll();
        $methods = [];
        
        foreach ($invoices as $inv) {
            $method = ucfirst(strtolower($inv['payment_method']));
            $amount = (float)$inv['total_amount'];
            
            if ($method === 'Split' && !empty($inv['split_details'])) {
                $splits = is_string($inv['split_details']) ? json_decode($inv['split_details'], true) : $inv['split_details'];
                if (is_array($splits)) {
                    foreach ($splits as $split) {
                        $sMethod = ucfirst(strtolower($split['method'] ?? 'Unknown'));
                        $sAmt = (float)($split['amount'] ?? 0);
                        if (!isset($methods[$sMethod])) {
                            $methods[$sMethod] = 0.0;
                        }
                        $methods[$sMethod] += $sAmt;
                    }
                }
            } else {
                if (!isset($methods[$method])) {
                    $methods[$method] = 0.0;
                }
                $methods[$method] += $amount;
            }
        }
        
        $result = [];
        foreach ($methods as $method => $revenue) {
            $result[] = ['payment_method' => $method, 'revenue' => $revenue];
        }
        
        return $result;
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
                   (SELECT COUNT(id) FROM appointments a WHERE a.user_id = u.id AND a.status IN ('done', 'paid') AND a.apt_date >= :start_date AND a.apt_date <= :end_date) as total_appointments,
                   (SELECT SUM(i.total_amount) 
                    FROM invoices i 
                    WHERE i.status = 'paid' 
                      AND i.created_at >= :start_date_rev 
                      AND i.created_at <= :end_date_rev 
                      AND i.id IN (
                          SELECT a.invoice_id FROM appointments a WHERE a.user_id = u.id AND a.invoice_id IS NOT NULL
                          UNION
                          SELECT c.invoice_id FROM commissions c WHERE c.user_id = u.id
                      )
                   ) as total_revenue
            FROM users u
            WHERE u.tenant_id = :tenant_id AND u.role = 'stylist'
            ORDER BY total_revenue DESC
        ");
        $stmt->execute([
            'tenant_id' => $this->getTenantId(),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'start_date_rev' => $startDate . ' 00:00:00',
            'end_date_rev' => $endDate . ' 23:59:59'
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

    public function getInventoryIssues(string $startDate, string $endDate): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                t.created_at as transaction_date,
                i.name as item_name,
                i.sku as sku,
                t.quantity as quantity,
                u.name as user_name,
                e.name as employee_name,
                COALESCE(t.notes, t.reference_no) as reference_no
            FROM inventory_transactions t
            JOIN inventory_items i ON t.item_id = i.id
            LEFT JOIN users u ON t.created_by = u.id
            LEFT JOIN users e ON t.employee_id = e.id
            WHERE t.tenant_id = :tenant_id 
              AND LOWER(t.type) = 'issue'
              AND t.created_at >= :start_date 
              AND t.created_at <= :end_date
            ORDER BY t.created_at DESC
        ");
        $stmt->execute([
            'tenant_id' => $this->getTenantId(),
            'start_date' => $startDate . ' 00:00:00',
            'end_date' => $endDate . ' 23:59:59'
        ]);
        return $stmt->fetchAll();
    }

    public function getCreditTracking(string $startDate, string $endDate): array
    {
        $tenantId = $this->getTenantId();
        
        // Total Outstanding Credit
        $stmt = $this->db->prepare("SELECT SUM(credit_balance) FROM customers WHERE tenant_id = ?");
        $stmt->execute([$tenantId]);
        $outstanding = (float)($stmt->fetchColumn() ?: 0.00);
        
        // Total Recovered in Period
        // Proxied via loyalty_transactions representing the payment date, compared to invoice creation date.
        $stmt2 = $this->db->prepare("
            SELECT SUM(i.total_amount) 
            FROM invoices i
            JOIN loyalty_transactions lt ON i.id = lt.invoice_id
            WHERE i.tenant_id = ? 
              AND i.status = 'paid'
              AND lt.created_at >= ? 
              AND lt.created_at <= ?
              AND DATE(i.created_at) < DATE(lt.created_at)
        ");
        $stmt2->execute([$tenantId, $startDate . ' 00:00:00', $endDate . ' 23:59:59']);
        $recovered = (float)($stmt2->fetchColumn() ?: 0.00);

        return [
            'total_outstanding_credit' => $outstanding,
            'total_recovered_in_period' => $recovered
        ];
    }
}
