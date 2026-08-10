<?php

namespace App\Services;

use App\Repositories\InvoiceRepository;
use App\Repositories\InvoiceItemRepository;
use App\Repositories\CommissionRepository;
use App\Repositories\UserRepository;
use Exception;

class InvoiceService extends BaseService
{
    private InvoiceRepository $invoiceRepo;
    private InvoiceItemRepository $itemRepo;
    private CommissionRepository $commissionRepo;
    private UserRepository $userRepo;

    public function __construct(
        InvoiceRepository $invoiceRepo, 
        InvoiceItemRepository $itemRepo,
        CommissionRepository $commissionRepo,
        UserRepository $userRepo
    ) {
        $this->invoiceRepo = $invoiceRepo;
        $this->itemRepo = $itemRepo;
        $this->commissionRepo = $commissionRepo;
        $this->userRepo = $userRepo;
    }

    public function setTenantId(int $tenantId): self
    {
        parent::setTenantId($tenantId);
        $this->invoiceRepo->setTenantId($tenantId);
        $this->itemRepo->setTenantId($tenantId);
        $this->commissionRepo->setTenantId($tenantId);
        $this->userRepo->setTenantId($tenantId);
        return $this;
    }

    /**
     * Processes a new POS Checkout transaction.
     */
    public function checkout(array $data): array
    {
        // 1. Validate items
        if (empty($data['items']) || !is_array($data['items'])) {
            throw new Exception("Cannot process an invoice without items.");
        }

        // 2. Calculate totals (never trust client total)
        $totalAmount = 0.00;
        $processedItems = [];
        
        foreach ($data['items'] as $item) {
            $qty = (int)($item['quantity'] ?? 1);
            $price = (float)($item['price'] ?? 0);
            $subtotal = $qty * $price;
            $totalAmount += $subtotal;
            
            $processedItems[] = [
                'description' => $item['name'] ?? 'Service',
                'quantity' => $qty,
                'unit_price' => $price,
                'subtotal' => $subtotal
            ];
        }

        // 3. Create Invoice
        $invoice = $this->invoiceRepo->create([
            'customer_id' => $data['customer_id'] ?? null,
            'total_amount' => $totalAmount,
            'status' => 'paid',
            'payment_method' => $data['payment_method'] ?? 'cash',
            'tender_amount' => isset($data['tender_amount']) ? (float)$data['tender_amount'] : null,
            'change_amount' => isset($data['change_amount']) ? (float)$data['change_amount'] : null,
            'split_details' => !empty($data['split_details']) ? json_encode($data['split_details']) : null
        ]);

        // 4. Create Items
        foreach ($processedItems as $pItem) {
            $pItem['invoice_id'] = $invoice['id'];
            $this->itemRepo->create($pItem);
        }

        // 5. Calculate and Save Commission
        if (!empty($data['employee_id'])) {
            $employeeId = (int)$data['employee_id'];
            $user = $this->userRepo->getById($employeeId);
            if ($user && isset($user['commission_rate']) && $user['commission_rate'] > 0) {
                $commissionAmount = $totalAmount * ($user['commission_rate'] / 100);
                
                $this->commissionRepo->create([
                    'user_id' => $employeeId,
                    'invoice_id' => $invoice['id'],
                    'amount' => $commissionAmount
                ]);
            }
        }

        return $invoice;
    }
}
