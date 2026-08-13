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
}
