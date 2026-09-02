<?php

namespace App\Services;

use PDO;
use Exception;
use App\Repositories\CustomerRepository;
use App\Repositories\TenantSettingRepository;

class LoyaltyService
{
    private PDO $db;
    private CustomerRepository $customerRepo;
    private TenantSettingRepository $settingsRepo;
    private ?int $tenantId = null;

    public function __construct(PDO $db, CustomerRepository $customerRepo, TenantSettingRepository $settingsRepo)
    {
        $this->db = $db;
        $this->customerRepo = $customerRepo;
        $this->settingsRepo = $settingsRepo;
    }

    public function setTenantId(int $tenantId): self
    {
        $this->tenantId = $tenantId;
        $this->customerRepo->setTenantId($tenantId);
        $this->settingsRepo->setTenantId($tenantId);
        return $this;
    }

    public function getPointsPerCurrency(): float
    {
        return (float)$this->settingsRepo->get('loyalty_points_per_currency', 0.1);
    }

    public function getCurrencyPerPoint(): float
    {
        return (float)$this->settingsRepo->get('loyalty_currency_per_point', 0.001);
    }

    public function awardPoints(int $customerId, int $invoiceId, float $invoiceTotal): void
    {
        if (!$this->tenantId) {
            throw new Exception("Tenant ID not set for LoyaltyService.");
        }

        $pointsEarned = (int)floor($invoiceTotal * $this->getPointsPerCurrency());

        if ($pointsEarned > 0) {
            // Update customer balance
            $stmt = $this->db->prepare("UPDATE customers SET loyalty_points = loyalty_points + :points WHERE id = :id AND tenant_id = :tenant_id");
            $stmt->execute([
                'points' => $pointsEarned,
                'id' => $customerId,
                'tenant_id' => $this->tenantId
            ]);

            // Record transaction
            $stmt = $this->db->prepare("
                INSERT INTO loyalty_transactions (tenant_id, customer_id, invoice_id, points_earned, description)
                VALUES (:tenant_id, :customer_id, :invoice_id, :points, :desc)
            ");
            $stmt->execute([
                'tenant_id' => $this->tenantId,
                'customer_id' => $customerId,
                'invoice_id' => $invoiceId,
                'points' => $pointsEarned,
                'desc' => "Earned points for Invoice #$invoiceId"
            ]);
        }
    }

    public function redeemPoints(int $customerId, int $invoiceId, int $pointsToRedeem): float
    {
        if (!$this->tenantId) {
            throw new Exception("Tenant ID not set for LoyaltyService.");
        }

        if ($pointsToRedeem <= 0) {
            return 0.00;
        }

        // Verify balance
        $customer = $this->customerRepo->getById($customerId);
        if (!$customer || $customer['loyalty_points'] < $pointsToRedeem) {
            throw new Exception("Insufficient loyalty points balance.");
        }

        $discountAmount = $pointsToRedeem * $this->getCurrencyPerPoint();

        // Update customer balance
        $stmt = $this->db->prepare("UPDATE customers SET loyalty_points = loyalty_points - :points WHERE id = :id AND tenant_id = :tenant_id");
        $stmt->execute([
            'points' => $pointsToRedeem,
            'id' => $customerId,
            'tenant_id' => $this->tenantId
        ]);

        // Record transaction
        $stmt = $this->db->prepare("
            INSERT INTO loyalty_transactions (tenant_id, customer_id, invoice_id, points_spent, description)
            VALUES (:tenant_id, :customer_id, :invoice_id, :points, :desc)
        ");
        $stmt->execute([
            'tenant_id' => $this->tenantId,
            'customer_id' => $customerId,
            'invoice_id' => $invoiceId,
            'points' => $pointsToRedeem,
            'desc' => "Redeemed points for Invoice #$invoiceId discount"
        ]);

        return $discountAmount;
    }

    public function getCustomerPoints(int $customerId): int
    {
        if (!$this->tenantId) {
            return 0;
        }
        $customer = $this->customerRepo->getById($customerId);
        return $customer ? (int)$customer['loyalty_points'] : 0;
    }

    public function getCustomerTransactions(int $customerId): array
    {
        if (!$this->tenantId) {
            return [];
        }
        $stmt = $this->db->prepare("
            SELECT * FROM loyalty_transactions 
            WHERE customer_id = :customer_id AND tenant_id = :tenant_id
            ORDER BY created_at DESC
        ");
        $stmt->execute([
            'customer_id' => $customerId,
            'tenant_id' => $this->tenantId
        ]);
        return $stmt->fetchAll();
    }

    public function getCustomerTransactionsPaginated(int $customerId, string $search = '', string $sort = 'created_at', string $dir = 'desc', int $limit = 10, int $offset = 0): array
    {
        if (!$this->tenantId) {
            return [];
        }

        $allowedSorts = ['id' => 'id', 'created_at' => 'created_at', 'points_earned' => 'points_earned', 'points_spent' => 'points_spent'];
        $sortColumn = $allowedSorts[$sort] ?? 'created_at';
        $direction = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';

        $where = "tenant_id = :tenant_id AND customer_id = :customer_id";
        $params = [':tenant_id' => $this->tenantId, ':customer_id' => $customerId, ':limit' => $limit, ':offset' => $offset];

        if ($search) {
            $where .= " AND description LIKE :search";
            $params[':search'] = "%{$search}%";
        }

        $stmt = $this->db->prepare("
            SELECT * FROM loyalty_transactions 
            WHERE {$where}
            ORDER BY {$sortColumn} {$direction}
            LIMIT :limit OFFSET :offset
        ");
        
        foreach ($params as $key => $value) {
            $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue($key, $value, $type);
        }
        
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }

    public function getCustomerTransactionsCount(int $customerId, string $search = ''): int
    {
        if (!$this->tenantId) {
            return 0;
        }

        $where = "tenant_id = :tenant_id AND customer_id = :customer_id";
        $params = [':tenant_id' => $this->tenantId, ':customer_id' => $customerId];

        if ($search) {
            $where .= " AND description LIKE :search";
            $params[':search'] = "%{$search}%";
        }

        $stmt = $this->db->prepare("
            SELECT COUNT(id) FROM loyalty_transactions 
            WHERE {$where}
        ");
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }
    public function payWithPoints(int $customerId, int $invoiceId, float $amountToPay): void
    {
        if (!$this->tenantId) {
            throw new Exception("Tenant ID not set for LoyaltyService.");
        }

        if ($amountToPay <= 0) {
            return;
        }

        $pointsNeeded = (int)ceil($amountToPay / $this->getCurrencyPerPoint());

        // Verify balance
        $customer = $this->customerRepo->getById($customerId);
        if (!$customer || $customer['loyalty_points'] < $pointsNeeded) {
            throw new Exception("Insufficient loyalty points balance for payment.");
        }

        // Update customer balance
        $stmt = $this->db->prepare("UPDATE customers SET loyalty_points = loyalty_points - :points WHERE id = :id AND tenant_id = :tenant_id");
        $stmt->execute([
            'points' => $pointsNeeded,
            'id' => $customerId,
            'tenant_id' => $this->tenantId
        ]);

        // Record transaction
        $stmt = $this->db->prepare("
            INSERT INTO loyalty_transactions (tenant_id, customer_id, invoice_id, points_spent, description)
            VALUES (:tenant_id, :customer_id, :invoice_id, :points, :desc)
        ");
        $stmt->execute([
            'tenant_id' => $this->tenantId,
            'customer_id' => $customerId,
            'invoice_id' => $invoiceId,
            'points' => $pointsNeeded,
            'desc' => "Paid QAR {$amountToPay} using points for Invoice #$invoiceId"
        ]);
    }
}
