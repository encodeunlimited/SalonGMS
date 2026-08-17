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
        // Since we don't have a paid_date, we just check if created_at is today and status is paid.
        // For cross-db compatibility (SQLite/MySQL), we'll use LIKE for the date in SQLite or standard >= <=
        $stmt = $this->db->prepare("SELECT SUM(total_amount) FROM invoices WHERE tenant_id = ? AND status = 'paid' AND created_at >= ? AND created_at <= ?");
        $stmt->execute([$tenantId, $today . ' 00:00:00', $today . ' 23:59:59']);
        $revenueToday = $stmt->fetchColumn() ?: 0.00;

        // Appointments today
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM appointments WHERE tenant_id = ? AND apt_date = ?");
        $stmt->execute([$tenantId, $today]);
        $appointmentsToday = $stmt->fetchColumn() ?: 0;

        // Active Stylists (Users with role stylist)
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM users WHERE tenant_id = ? AND role = 'stylist'");
        $stmt->execute([$tenantId]);
        $activeStylists = $stmt->fetchColumn() ?: 0;

        // New Customers (Created this month)
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM customers WHERE tenant_id = ? AND created_at >= ? AND created_at <= ?");
        $stmt->execute([$tenantId, $startOfMonth, $endOfMonth]);
        $newCustomers = $stmt->fetchColumn() ?: 0;

        return [
            'revenue_today' => $revenueToday,
            'appointments_today' => $appointmentsToday,
            'active_stylists' => $activeStylists,
            'new_customers' => $newCustomers
        ];
    }
    
    public function getWeeklyRevenueData(): array
    {
        $tenantId = $this->getTenantId();
        $revenueData = [];
        
        $labels = [];
        
        // Loop through the last 7 days (including today)
        for ($i = 6; $i >= 0; $i--) {
            $dateStr = strtotime("-$i days");
            $date = date('Y-m-d', $dateStr);
            $labels[] = date('D', $dateStr); // e.g., 'Mon', 'Tue'
            
            $stmt = $this->db->prepare("SELECT SUM(total_amount) FROM invoices WHERE tenant_id = ? AND status = 'paid' AND created_at >= ? AND created_at <= ?");
            $stmt->execute([$tenantId, $date . ' 00:00:00', $date . ' 23:59:59']);
            $total = $stmt->fetchColumn();
            $revenueData[] = (float)($total ?: 0.00);
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
            SELECT s.name, COUNT(ii.id) as item_count 
            FROM invoice_items ii 
            JOIN services s ON ii.service_id = s.id 
            JOIN invoices i ON ii.invoice_id = i.id
            JOIN appointments a ON i.appointment_id = a.id
            WHERE i.tenant_id = ? AND a.user_id = ? 
            GROUP BY s.name 
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
        $revenueData = [];
        $labels = [];
        
        for ($i = 6; $i >= 0; $i--) {
            $dateStr = strtotime("-$i days");
            $date = date('Y-m-d', $dateStr);
            $labels[] = date('D', $dateStr);
            
            $stmt = $this->db->prepare("SELECT SUM(amount) FROM commissions WHERE tenant_id = ? AND user_id = ? AND created_at >= ? AND created_at <= ?");
            $stmt->execute([$tenantId, $userId, $date . ' 00:00:00', $date . ' 23:59:59']);
            $total = $stmt->fetchColumn();
            $revenueData[] = (float)($total ?: 0.00);
        }
        
        return [
            'labels' => $labels,
            'series' => $revenueData
        ];
    }
}
