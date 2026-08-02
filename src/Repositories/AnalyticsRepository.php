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
        // For a full implementation, we'd query the DB for the last 7 days of revenue.
        // For this step, returning a mock array structure for the chart.
        return [400, 850, 1100, 900, 1500, 2100, 1250];
    }

    public function getServicesBreakdown(): array
    {
        // Mock data for the donut chart.
        return [
            'labels' => ['Haircut', 'Coloring', 'Manicure', 'Spa'],
            'series' => [44, 55, 13, 33]
        ];
    }
}
