<?php

namespace App\Repositories;

class AnalyticsRepository extends BaseRepository
{
    // No specific table since this aggregates data.
    protected string $table = '';

    public function getDashboardKPIs(): array
    {
        $tenantId = $this->getTenantId();
        $today = date('Y-m-d');
        
        $startOfMonth = date('Y-m-01 00:00:00');
        $endOfMonth = date('Y-m-t 23:59:59');

        // Revenue today (from invoices paid today)
        $stmt = $this->db->prepare("SELECT SUM(total_amount) FROM invoices WHERE tenant_id = ? AND status = 'paid' AND created_at >= ? AND created_at <= ?");
        $stmt->execute([$tenantId, $today . ' 00:00:00', $today . ' 23:59:59']);
        $revenueToday = (float)($stmt->fetchColumn() ?: 0.00);

        // Cash today
        $stmt = $this->db->prepare("SELECT SUM(total_amount) FROM invoices WHERE tenant_id = ? AND status = 'paid' AND (LOWER(payment_method) = 'cash' OR payment_method IS NULL OR payment_method = '') AND created_at >= ? AND created_at <= ?");
        $stmt->execute([$tenantId, $today . ' 00:00:00', $today . ' 23:59:59']);
        $cashToday = (float)($stmt->fetchColumn() ?: 0.00);

        // Card today
        $stmt = $this->db->prepare("SELECT SUM(total_amount) FROM invoices WHERE tenant_id = ? AND status = 'paid' AND LOWER(payment_method) = 'card' AND created_at >= ? AND created_at <= ?");
        $stmt->execute([$tenantId, $today . ' 00:00:00', $today . ' 23:59:59']);
        $cardToday = (float)($stmt->fetchColumn() ?: 0.00);

        // Appointments today
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM appointments WHERE tenant_id = ? AND apt_date = ?");
        $stmt->execute([$tenantId, $today]);
        $appointmentsToday = (int)($stmt->fetchColumn() ?: 0);

        // Active Stylists
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM users WHERE tenant_id = ? AND role = 'stylist'");
        $stmt->execute([$tenantId]);
        $activeStylists = (int)($stmt->fetchColumn() ?: 0);

        // New Customers
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM customers WHERE tenant_id = ? AND created_at >= ? AND created_at <= ?");
        $stmt->execute([$tenantId, $startOfMonth, $endOfMonth]);
        $newCustomers = (int)($stmt->fetchColumn() ?: 0);

        // Expenses today
        $stmt = $this->db->prepare("SELECT SUM(amount) FROM expenses WHERE tenant_id = ? AND expense_date = ?");
        $stmt->execute([$tenantId, $today]);
        $expensesToday = (float)($stmt->fetchColumn() ?: 0.00);

        return [
            'revenue_today' => $revenueToday,
            'cash_today' => $cashToday,
            'card_today' => $cardToday,
            'appointments_today' => $appointmentsToday,
            'active_stylists' => $activeStylists,
            'new_customers' => $newCustomers,
            'expenses_today' => $expensesToday
        ];
    }
    
    public function getWeeklyRevenueData(): array
    {
        $tenantId = $this->getTenantId();
        
        // Build date range for last 7 days
        $dates = [];
        $labels = [];
        for ($i = 6; $i >= 0; $i--) {
            $dateStr = strtotime("-$i days");
            $date = date('Y-m-d', $dateStr);
            $dates[] = $date;
            $labels[] = date('D', $dateStr);
        }
        $startDate = $dates[0] . ' 00:00:00';
        $endDate   = end($dates) . ' 23:59:59';
        
        // Single query — group by date
        $stmt = $this->db->prepare("
            SELECT DATE(created_at) as day, SUM(total_amount) as total
            FROM invoices
            WHERE tenant_id = ? AND status = 'paid'
              AND created_at >= ? AND created_at <= ?
            GROUP BY DATE(created_at)
        ");
        $stmt->execute([$tenantId, $startDate, $endDate]);
        $rows = $stmt->fetchAll();
        
        // Map results to date index
        $revenueByDate = [];
        foreach ($rows as $row) {
            $revenueByDate[$row['day']] = (float)$row['total'];
        }
        
        $revenueData = [];
        foreach ($dates as $date) {
            $revenueData[] = $revenueByDate[$date] ?? 0.00;
        }
        
        return [
            'labels' => $labels,
            'series' => $revenueData
        ];
    }

    public function getServicesBreakdown(): array
    {
        $tenantId = $this->getTenantId();
        
        // Count services based on invoice items
        $stmt = $this->db->prepare("
            SELECT s.category, COUNT(ii.id) as item_count 
            FROM invoice_items ii 
            JOIN services s ON ii.service_id = s.id 
            WHERE ii.tenant_id = ? 
            GROUP BY s.category 
            ORDER BY item_count DESC 
            LIMIT 5
        ");
        $stmt->execute([$tenantId]);
        $results = $stmt->fetchAll();
        
        $labels = [];
        $series = [];
        
        foreach ($results as $row) {
            $labels[] = $row['category'] ?: 'Uncategorized';
            $series[] = (int)$row['item_count'];
        }
        
        // If there is no data, return empty arrays or a default to prevent chart errors
        if (empty($labels)) {
            $labels = ['No Data'];
            $series = [0];
        }
        
        return [
            'labels' => $labels,
            'series' => $series
        ];
    }

    public function getStylistCommissionThisMonth(int $userId): float
    {
        $tenantId = $this->getTenantId();
        $startOfMonth = date('Y-m-01 00:00:00');
        $endOfMonth = date('Y-m-t 23:59:59');

        $stmt = $this->db->prepare("
            SELECT SUM(amount) 
            FROM commissions 
            WHERE tenant_id = ? 
              AND user_id = ? 
              AND created_at >= ? 
              AND created_at <= ?
        ");
        $stmt->execute([$tenantId, $userId, $startOfMonth, $endOfMonth]);
        return (float)($stmt->fetchColumn() ?: 0.00);
    }

    public function getAppointmentsByStatus(?int $userId = null): array
    {
        $tenantId = $this->getTenantId();
        $today = date('Y-m-d');
        
        $sql = "SELECT status, COUNT(*) as count FROM appointments WHERE tenant_id = ? AND apt_date = ?";
        $params = [$tenantId, $today];
        
        if ($userId) {
            $sql .= " AND user_id = ?";
            $params[] = $userId;
        }
        
        $sql .= " GROUP BY status";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll();
        
        $labels = [];
        $series = [];
        
        foreach ($results as $row) {
            $labels[] = ucfirst($row['status']);
            $series[] = (int)$row['count'];
        }
        
        if (empty($labels)) {
            $labels = ['No Appointments'];
            $series = [1]; // Prevent chart error
        }
        
        return [
            'labels' => $labels,
            'series' => $series
        ];
    }

    public function getStylistServicesBreakdown(int $userId): array
    {
        $tenantId = $this->getTenantId();
        
        $stmt = $this->db->prepare("
            SELECT service as name, COUNT(id) as item_count 
            FROM appointments 
            WHERE tenant_id = ? AND user_id = ? AND status IN ('done', 'paid', 'completed')
            GROUP BY service 
            ORDER BY item_count DESC 
            LIMIT 5
        ");
        $stmt->execute([$tenantId, $userId]);
        $results = $stmt->fetchAll();
        
        $labels = [];
        $series = [];
        
        foreach ($results as $row) {
            $labels[] = $row['name'] ?: 'Unknown';
            $series[] = (int)$row['item_count'];
        }
        
        if (empty($labels)) {
            $labels = ['No Data'];
            $series = [1];
        }
        
        return [
            'labels' => $labels,
            'series' => $series
        ];
    }

    public function getStylistCommissionTrend(int $userId): array
    {
        $tenantId = $this->getTenantId();
        
        $dates = [];
        $labels = [];
        for ($i = 6; $i >= 0; $i--) {
            $dateStr = strtotime("-$i days");
            $date = date('Y-m-d', $dateStr);
            $dates[] = $date;
            $labels[] = date('D', $dateStr);
        }
        $startDate = $dates[0] . ' 00:00:00';
        $endDate   = end($dates) . ' 23:59:59';
        
        // Single query — group by date
        $stmt = $this->db->prepare("
            SELECT DATE(created_at) as day, SUM(amount) as total
            FROM commissions
            WHERE tenant_id = ? AND user_id = ?
              AND created_at >= ? AND created_at <= ?
            GROUP BY DATE(created_at)
        ");
        $stmt->execute([$tenantId, $userId, $startDate, $endDate]);
        $rows = $stmt->fetchAll();
        
        $byDate = [];
        foreach ($rows as $row) {
            $byDate[$row['day']] = (float)$row['total'];
        }
        
        $revenueData = [];
        foreach ($dates as $date) {
            $revenueData[] = $byDate[$date] ?? 0.00;
        }
        
        return [
            'labels' => $labels,
            'series' => $revenueData
        ];
    }
}
