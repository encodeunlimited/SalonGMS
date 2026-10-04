<?php

namespace App\Repositories;

use PDO;
use Exception;

class QueueRepository extends BaseRepository
{
    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->table = 'queue_tickets';
    }

    /**
     * Get next sequence and ticket number for today.
     */
    public function getNextTicketInfo(int $barberId, string $prefix = 'T'): array
    {
        $tenantId = $this->getTenantId();
        $todayStart = date('Y-m-d 00:00:00');
        $todayEnd = date('Y-m-d 23:59:59');

        // Sequence number count for today across tenant
        $stmt = $this->db->prepare("
            SELECT COALESCE(MAX(sequence_num), 0) as max_seq 
            FROM queue_tickets 
            WHERE tenant_id = :tenant_id AND created_at BETWEEN :start AND :end
        ");
        $stmt->execute([
            'tenant_id' => $tenantId,
            'start' => $todayStart,
            'end' => $todayEnd
        ]);
        $row = $stmt->fetch();
        $nextSeq = (int)($row['max_seq'] ?? 0) + 1;

        // Prefix formatting: e.g. T-001 or custom
        $cleanPrefix = strtoupper(trim($prefix ?: 'T'));
        $ticketNumber = sprintf('%s-%03d', $cleanPrefix, $nextSeq);

        // Count waiting customers ahead specifically for this barber
        $stmtBarberWaiting = $this->db->prepare("
            SELECT COUNT(*) as waiting_count 
            FROM queue_tickets 
            WHERE tenant_id = :tenant_id 
              AND barber_id = :barber_id 
              AND status = 'waiting'
              AND created_at BETWEEN :start AND :end
        ");
        $stmtBarberWaiting->execute([
            'tenant_id' => $tenantId,
            'barber_id' => $barberId,
            'start' => $todayStart,
            'end' => $todayEnd
        ]);
        $barberWaitingCount = (int)($stmtBarberWaiting->fetch()['waiting_count'] ?? 0);

        return [
            'sequence_num' => $nextSeq,
            'ticket_number' => $ticketNumber,
            'barber_waiting_ahead' => $barberWaitingCount,
            'estimated_wait_time' => $barberWaitingCount * 25 // default estimate 25 mins per waiting client
        ];
    }

    /**
     * Issue a new queue ticket.
     */
    public function issueTicket(array $data): array
    {
        $tenantId = $this->getTenantId();
        $barberId = (int)$data['barber_id'];
        $prefix = $data['prefix'] ?? 'T';

        $ticketInfo = $this->getNextTicketInfo($barberId, $prefix);

        $ticketNumber = !empty($data['ticket_number']) ? $data['ticket_number'] : $ticketInfo['ticket_number'];
        $sequenceNum = !empty($data['sequence_num']) ? (int)$data['sequence_num'] : $ticketInfo['sequence_num'];
        $estimatedWait = isset($data['estimated_wait_time']) ? (int)$data['estimated_wait_time'] : $ticketInfo['estimated_wait_time'];

        $sql = "
            INSERT INTO queue_tickets (
                tenant_id, ticket_number, sequence_num, customer_id, customer_name,
                customer_phone, barber_id, service_id, service_name, status,
                estimated_wait_time, notes, created_at
            ) VALUES (
                :tenant_id, :ticket_number, :sequence_num, :customer_id, :customer_name,
                :customer_phone, :barber_id, :service_id, :service_name, 'waiting',
                :estimated_wait_time, :notes, :created_at
            )
        ";

        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'tenant_id' => $tenantId,
            'ticket_number' => $ticketNumber,
            'sequence_num' => $sequenceNum,
            'customer_id' => !empty($data['customer_id']) ? (int)$data['customer_id'] : null,
            'customer_name' => trim($data['customer_name']),
            'customer_phone' => !empty($data['customer_phone']) ? trim($data['customer_phone']) : null,
            'barber_id' => $barberId,
            'service_id' => !empty($data['service_id']) ? (int)$data['service_id'] : null,
            'service_name' => !empty($data['service_name']) ? trim($data['service_name']) : null,
            'estimated_wait_time' => $estimatedWait,
            'notes' => !empty($data['notes']) ? trim($data['notes']) : null,
            'created_at' => $now
        ]);

        $ticketId = (int)$this->db->lastInsertId();
        return $this->getTicketDetails($ticketId);
    }

    /**
     * Get ticket details with joins.
     */
    public function getTicketDetails(int $id): ?array
    {
        $tenantId = $this->getTenantId();
        $sql = "
            SELECT q.*, 
                   u.name as barber_name,
                   u.email as barber_email,
                   s.name as full_service_name,
                   s.price as service_price,
                   s.duration_minutes as service_duration,
                   c.phone as registered_customer_phone,
                   c.loyalty_points as customer_loyalty_points
            FROM queue_tickets q
            LEFT JOIN users u ON q.barber_id = u.id
            LEFT JOIN services s ON q.service_id = s.id
            LEFT JOIN customers c ON q.customer_id = c.id
            WHERE q.id = :id AND q.tenant_id = :tenant_id
            LIMIT 1
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id, 'tenant_id' => $tenantId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Get all barber lanes and their current queues for today.
     */
    public function getBarberQueues(?string $date = null, ?int $barberId = null): array
    {
        $tenantId = $this->getTenantId();
        $date = $date ?: date('Y-m-d');
        $start = $date . ' 00:00:00';
        $end = $date . ' 23:59:59';

        // 1. Fetch stylists/barbers (all or specific barber)
        $barberSql = "
            SELECT id, name, email, role, specialist_areas 
            FROM users 
            WHERE tenant_id = :tenant_id AND role IN ('stylist', 'admin', 'manager')
        ";
        $paramsBarbers = ['tenant_id' => $tenantId];
        if ($barberId !== null) {
            $barberSql .= " AND id = :barber_id";
            $paramsBarbers['barber_id'] = $barberId;
        }
        $barberSql .= " ORDER BY name ASC";
        $stmtBarbers = $this->db->prepare($barberSql);
        $stmtBarbers->execute($paramsBarbers);
        $barbers = $stmtBarbers->fetchAll();

        // If barberId was provided but not found with regular roles, fetch directly by ID
        if ($barberId !== null && empty($barbers)) {
            $stmtSingle = $this->db->prepare("SELECT id, name, email, role, specialist_areas FROM users WHERE tenant_id = :tenant_id AND id = :barber_id");
            $stmtSingle->execute(['tenant_id' => $tenantId, 'barber_id' => $barberId]);
            $barbers = $stmtSingle->fetchAll();
        }

        // 2. Fetch today's tickets for this tenant (all or filtered by barber)
        $ticketSql = "
            SELECT q.*, 
                   u.name as barber_name,
                   s.price as service_price,
                   s.duration_minutes as service_duration
            FROM queue_tickets q
            LEFT JOIN users u ON q.barber_id = u.id
            LEFT JOIN services s ON q.service_id = s.id
            WHERE q.tenant_id = :tenant_id 
              AND q.created_at BETWEEN :start AND :end
        ";
        $paramsTickets = [
            'tenant_id' => $tenantId,
            'start' => $start,
            'end' => $end
        ];
        if ($barberId !== null) {
            $ticketSql .= " AND q.barber_id = :barber_id";
            $paramsTickets['barber_id'] = $barberId;
        }
        $ticketSql .= " ORDER BY q.created_at ASC";
        $stmtTickets = $this->db->prepare($ticketSql);
        $stmtTickets->execute($paramsTickets);
        $allTickets = $stmtTickets->fetchAll();

        // Group tickets by barber_id
        $barberData = [];
        foreach ($barbers as $barber) {
            $bId = (int)$barber['id'];
            $barberData[$bId] = [
                'barber' => $barber,
                'serving' => null,
                'waiting' => [],
                'completed' => [],
                'total_waiting' => 0,
                'total_served' => 0
            ];
        }

        foreach ($allTickets as $t) {
            $bId = (int)$t['barber_id'];
            if (!isset($barberData[$bId])) {
                $barberData[$bId] = [
                    'barber' => [
                        'id' => $bId,
                        'name' => $t['barber_name'] ?: 'Barber #' . $bId,
                        'role' => 'stylist'
                    ],
                    'serving' => null,
                    'waiting' => [],
                    'completed' => [],
                    'total_waiting' => 0,
                    'total_served' => 0
                ];
            }

            if ($t['status'] === 'serving') {
                $barberData[$bId]['serving'] = $t;
            } elseif ($t['status'] === 'waiting') {
                $barberData[$bId]['waiting'][] = $t;
                $barberData[$bId]['total_waiting']++;
            } elseif ($t['status'] === 'completed') {
                $barberData[$bId]['completed'][] = $t;
                $barberData[$bId]['total_served']++;
            }
        }

        return array_values($barberData);
    }

    /**
     * Get active tickets for a single barber (waiting and serving).
     */
    public function getBarberQueue(int $barberId, ?string $date = null): array
    {
        $tenantId = $this->getTenantId();
        $date = $date ?: date('Y-m-d');
        $start = $date . ' 00:00:00';
        $end = $date . ' 23:59:59';

        $stmt = $this->db->prepare("
            SELECT q.*, 
                   u.name as barber_name,
                   s.price as service_price,
                   s.duration_minutes as service_duration
            FROM queue_tickets q
            LEFT JOIN users u ON q.barber_id = u.id
            LEFT JOIN services s ON q.service_id = s.id
            WHERE q.tenant_id = :tenant_id 
              AND q.barber_id = :barber_id
              AND q.created_at BETWEEN :start AND :end
              AND q.status IN ('waiting', 'serving')
            ORDER BY CASE WHEN q.status = 'serving' THEN 1 ELSE 2 END, q.created_at ASC
        ");
        $stmt->execute([
            'tenant_id' => $tenantId,
            'barber_id' => $barberId,
            'start' => $start,
            'end' => $end
        ]);
        return $stmt->fetchAll();
    }

    /**
     * Call next waiting ticket for a barber or specific ticket.
     */
    public function callTicket(int $ticketId): bool
    {
        $tenantId = $this->getTenantId();
        $ticket = $this->getTicketDetails($ticketId);
        if (!$ticket) return false;

        $barberId = (int)$ticket['barber_id'];
        $now = date('Y-m-d H:i:s');

        // Optional: If this barber currently has another ticket in 'serving' status,
        // we can mark that previous ticket as 'completed'
        $stmtPrev = $this->db->prepare("
            UPDATE queue_tickets 
            SET status = 'completed', completed_at = :now 
            WHERE tenant_id = :tenant_id AND barber_id = :barber_id AND status = 'serving' AND id != :id
        ");
        $stmtPrev->execute([
            'now' => $now,
            'tenant_id' => $tenantId,
            'barber_id' => $barberId,
            'id' => $ticketId
        ]);

        // Update target ticket to 'serving'
        $stmt = $this->db->prepare("
            UPDATE queue_tickets 
            SET status = 'serving', called_at = :now, served_at = :now 
            WHERE id = :id AND tenant_id = :tenant_id
        ");
        return $stmt->execute([
            'now' => $now,
            'id' => $ticketId,
            'tenant_id' => $tenantId
        ]);
    }

    /**
     * Call next waiting ticket in line for a barber.
     */
    public function callNextForBarber(int $barberId): ?array
    {
        $tenantId = $this->getTenantId();
        $todayStart = date('Y-m-d 00:00:00');
        $todayEnd = date('Y-m-d 23:59:59');

        $stmt = $this->db->prepare("
            SELECT id FROM queue_tickets 
            WHERE tenant_id = :tenant_id 
              AND barber_id = :barber_id 
              AND status = 'waiting'
              AND created_at BETWEEN :start AND :end
            ORDER BY sequence_num ASC, created_at ASC
            LIMIT 1
        ");
        $stmt->execute([
            'tenant_id' => $tenantId,
            'barber_id' => $barberId,
            'start' => $todayStart,
            'end' => $todayEnd
        ]);
        $row = $stmt->fetch();
        if ($row) {
            $this->callTicket((int)$row['id']);
            return $this->getTicketDetails((int)$row['id']);
        }
        return null;
    }

    /**
     * Mark ticket as completed.
     */
    public function completeTicket(int $ticketId, ?int $invoiceId = null): bool
    {
        $tenantId = $this->getTenantId();
        $now = date('Y-m-d H:i:s');
        $sql = "
            UPDATE queue_tickets 
            SET status = 'completed', completed_at = :now" . ($invoiceId ? ", invoice_id = :invoice_id" : "") . "
            WHERE id = :id AND tenant_id = :tenant_id
        ";
        $params = [
            'now' => $now,
            'id' => $ticketId,
            'tenant_id' => $tenantId
        ];
        if ($invoiceId) {
            $params['invoice_id'] = $invoiceId;
        }
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Cancel or mark no-show for a ticket.
     */
    public function updateTicketStatus(int $ticketId, string $status, ?string $reason = null): bool
    {
        $tenantId = $this->getTenantId();
        $allowed = ['waiting', 'serving', 'completed', 'cancelled', 'no_show'];
        if (!in_array($status, $allowed)) return false;

        $sql = "UPDATE queue_tickets SET status = :status";
        $params = ['status' => $status, 'id' => $ticketId, 'tenant_id' => $tenantId];

        if ($reason) {
            $sql .= ", notes = CASE WHEN notes IS NULL OR notes = '' THEN :reason ELSE notes || ' | ' || :reason END";
            $params['reason'] = $reason;
        }

        if ($status === 'completed') {
            $sql .= ", completed_at = :now";
            $params['now'] = date('Y-m-d H:i:s');
        }

        $sql .= " WHERE id = :id AND tenant_id = :tenant_id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Reassign ticket to another barber.
     */
    public function transferBarber(int $ticketId, int $newBarberId): bool
    {
        $tenantId = $this->getTenantId();
        $stmt = $this->db->prepare("
            UPDATE queue_tickets 
            SET barber_id = :barber_id 
            WHERE id = :id AND tenant_id = :tenant_id
        ");
        return $stmt->execute([
            'barber_id' => $newBarberId,
            'id' => $ticketId,
            'tenant_id' => $tenantId
        ]);
    }

    /**
     * Get queue statistics for today.
     */
    public function getQueueStats(?string $date = null, ?int $barberId = null): array
    {
        $tenantId = $this->getTenantId();
        $date = $date ?: date('Y-m-d');
        $start = $date . ' 00:00:00';
        $end = $date . ' 23:59:59';

        $where = "tenant_id = :tenant_id AND created_at BETWEEN :start AND :end";
        $params = [
            'tenant_id' => $tenantId,
            'start' => $start,
            'end' => $end
        ];
        if ($barberId !== null) {
            $where .= " AND barber_id = :barber_id";
            $params['barber_id'] = $barberId;
        }

        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_tickets,
                SUM(CASE WHEN status = 'waiting' THEN 1 ELSE 0 END) as waiting_count,
                SUM(CASE WHEN status = 'serving' THEN 1 ELSE 0 END) as serving_count,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count,
                SUM(CASE WHEN status IN ('cancelled', 'no_show') THEN 1 ELSE 0 END) as cancelled_count
            FROM queue_tickets
            WHERE $where
        ");
        $stmt->execute($params);
        $stats = $stmt->fetch();

        // Calculate average wait time for completed or serving tickets
        $waitWhere = "tenant_id = :tenant_id AND created_at BETWEEN :start AND :end AND called_at IS NOT NULL";
        $waitParams = [
            'tenant_id' => $tenantId,
            'start' => $start,
            'end' => $end
        ];
        if ($barberId !== null) {
            $waitWhere .= " AND barber_id = :barber_id";
            $waitParams['barber_id'] = $barberId;
        }

        $stmtWait = $this->db->prepare("
            SELECT created_at, called_at 
            FROM queue_tickets 
            WHERE $waitWhere
        ");
        $stmtWait->execute($waitParams);
        $calledTickets = $stmtWait->fetchAll();
        $totalWaitMinutes = 0;
        $count = count($calledTickets);
        foreach ($calledTickets as $ct) {
            $diff = (strtotime($ct['called_at']) - strtotime($ct['created_at'])) / 60;
            if ($diff > 0) $totalWaitMinutes += $diff;
        }
        $avgWaitTime = $count > 0 ? round($totalWaitMinutes / $count) : 0;

        return [
            'total_tickets' => (int)($stats['total_tickets'] ?? 0),
            'waiting_count' => (int)($stats['waiting_count'] ?? 0),
            'serving_count' => (int)($stats['serving_count'] ?? 0),
            'completed_count' => (int)($stats['completed_count'] ?? 0),
            'cancelled_count' => (int)($stats['cancelled_count'] ?? 0),
            'avg_wait_time' => $avgWaitTime
        ];
    }

    /**
     * Get now serving and up-next tickets for public waiting display.
     */
    public function getPublicDisplayData(): array
    {
        $tenantId = $this->getTenantId();
        $start = date('Y-m-d 00:00:00');
        $end = date('Y-m-d 23:59:59');

        // Now serving tickets
        $stmtServing = $this->db->prepare("
            SELECT q.*, u.name as barber_name, s.name as full_service_name
            FROM queue_tickets q
            LEFT JOIN users u ON q.barber_id = u.id
            LEFT JOIN services s ON q.service_id = s.id
            WHERE q.tenant_id = :tenant_id 
              AND q.created_at BETWEEN :start AND :end
              AND q.status = 'serving'
            ORDER BY q.called_at DESC
        ");
        $stmtServing->execute([
            'tenant_id' => $tenantId,
            'start' => $start,
            'end' => $end
        ]);
        $serving = $stmtServing->fetchAll();

        // Waiting tickets (top 15)
        $stmtWaiting = $this->db->prepare("
            SELECT q.*, u.name as barber_name, s.name as full_service_name
            FROM queue_tickets q
            LEFT JOIN users u ON q.barber_id = u.id
            LEFT JOIN services s ON q.service_id = s.id
            WHERE q.tenant_id = :tenant_id 
              AND q.created_at BETWEEN :start AND :end
              AND q.status = 'waiting'
            ORDER BY q.sequence_num ASC, q.created_at ASC
            LIMIT 15
        ");
        $stmtWaiting->execute([
            'tenant_id' => $tenantId,
            'start' => $start,
            'end' => $end
        ]);
        $waiting = $stmtWaiting->fetchAll();

        // Recently completed (last 5)
        $stmtCompleted = $this->db->prepare("
            SELECT q.*, u.name as barber_name
            FROM queue_tickets q
            LEFT JOIN users u ON q.barber_id = u.id
            WHERE q.tenant_id = :tenant_id 
              AND q.created_at BETWEEN :start AND :end
              AND q.status = 'completed'
            ORDER BY q.completed_at DESC
            LIMIT 5
        ");
        $stmtCompleted->execute([
            'tenant_id' => $tenantId,
            'start' => $start,
            'end' => $end
        ]);
        $completed = $stmtCompleted->fetchAll();

        return [
            'serving' => $serving,
            'waiting' => $waiting,
            'completed' => $completed
        ];
    }

    /**
     * Get paginated ticket history.
     */
    public function getHistory(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $tenantId = $this->getTenantId();
        $params = ['tenant_id' => $tenantId];
        $where = ["q.tenant_id = :tenant_id"];

        if (!empty($filters['date'])) {
            $where[] = "q.created_at BETWEEN :start AND :end";
            $params['start'] = $filters['date'] . ' 00:00:00';
            $params['end'] = $filters['date'] . ' 23:59:59';
        }

        if (!empty($filters['barber_id'])) {
            $where[] = "q.barber_id = :barber_id";
            $params['barber_id'] = (int)$filters['barber_id'];
        }

        if (!empty($filters['status'])) {
            $where[] = "q.status = :status";
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['search'])) {
            $where[] = "(q.ticket_number LIKE :search OR q.customer_name LIKE :search OR q.customer_phone LIKE :search)";
            $params['search'] = '%' . $filters['search'] . '%';
        }

        $whereClause = implode(' AND ', $where);

        $countSql = "SELECT COUNT(*) FROM queue_tickets q WHERE $whereClause";
        $stmtCount = $this->db->prepare($countSql);
        $stmtCount->execute($params);
        $total = (int)$stmtCount->fetchColumn();

        $dataSql = "
            SELECT q.*, u.name as barber_name, s.name as full_service_name, inv.total_amount as invoice_amount
            FROM queue_tickets q
            LEFT JOIN users u ON q.barber_id = u.id
            LEFT JOIN services s ON q.service_id = s.id
            LEFT JOIN invoices inv ON q.invoice_id = inv.id
            WHERE $whereClause
            ORDER BY q.created_at DESC
            LIMIT $limit OFFSET $offset
        ";
        $stmtData = $this->db->prepare($dataSql);
        $stmtData->execute($params);
        $data = $stmtData->fetchAll();

        return [
            'data' => $data,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset
        ];
    }
}
