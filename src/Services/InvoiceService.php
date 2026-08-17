<?php

namespace App\Services;

use App\Repositories\InvoiceRepository;
use App\Repositories\InvoiceItemRepository;
use App\Repositories\CommissionRepository;
use App\Repositories\UserRepository;
use App\Repositories\AppointmentRepository;
use App\Repositories\PackageRepository;
use App\Repositories\CustomerPackageRepository;
use App\Services\LoyaltyService;
use Exception;

class InvoiceService extends BaseService
{
    private InvoiceRepository $invoiceRepo;
    private InvoiceItemRepository $itemRepo;
    private CommissionRepository $commissionRepo;
    private UserRepository $userRepo;
    private AppointmentRepository $appointmentRepo;
    private PackageRepository $packageRepo;
    private CustomerPackageRepository $customerPackageRepo;
    private LoyaltyService $loyaltyService;

    public function __construct(
        InvoiceRepository $invoiceRepo, 
        InvoiceItemRepository $itemRepo,
        CommissionRepository $commissionRepo,
        UserRepository $userRepo,
        AppointmentRepository $appointmentRepo,
        PackageRepository $packageRepo,
        CustomerPackageRepository $customerPackageRepo,
        LoyaltyService $loyaltyService
    ) {
        $this->invoiceRepo = $invoiceRepo;
        $this->itemRepo = $itemRepo;
        $this->commissionRepo = $commissionRepo;
        $this->userRepo = $userRepo;
        $this->appointmentRepo = $appointmentRepo;
        $this->packageRepo = $packageRepo;
        $this->customerPackageRepo = $customerPackageRepo;
        $this->loyaltyService = $loyaltyService;
    }

    public function setTenantId(int $tenantId): self
    {
        parent::setTenantId($tenantId);
        $this->invoiceRepo->setTenantId($tenantId);
        $this->itemRepo->setTenantId($tenantId);
        $this->commissionRepo->setTenantId($tenantId);
        $this->userRepo->setTenantId($tenantId);
        $this->appointmentRepo->setTenantId($tenantId);
        $this->packageRepo->setTenantId($tenantId);
        $this->customerPackageRepo->setTenantId($tenantId);
        $this->loyaltyService->setTenantId($tenantId);
        return $this;
    }

    public function getInvoicePublic(int $invoiceId): ?array
    {
        $invoice = $this->invoiceRepo->getByIdPublic($invoiceId);
        if (!$invoice) {
            return null;
        }
        
        $items = $this->itemRepo->getByInvoiceId($invoiceId);
        $invoice['items'] = $items;
        
        return $invoice;
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
                'type' => $item['type'] ?? 'service',
                'item_id' => $item['id'] ?? null,
                'description' => $item['name'] ?? 'Service',
                'quantity' => $qty,
                'unit_price' => $price,
                'subtotal' => $subtotal
            ];
        }

        // Apply loyalty discount if requested
        $discountAmount = 0.00;
        $customerId = !empty($data['customer_id']) ? (int)$data['customer_id'] : null;
        $redeemPoints = !empty($data['redeem_points']) ? (int)$data['redeem_points'] : 0;

        // 3. Create Invoice
        $invoice = $this->invoiceRepo->create([
            'customer_id' => $customerId,
            'total_amount' => $totalAmount, // We store original total, discount applied later or as an item? Let's keep original total for commission, but tender will be lower. Actually, let's adjust total.
            'status' => 'paid',
            'payment_method' => $data['payment_method'] ?? 'cash',
            'tender_amount' => isset($data['tender_amount']) ? (float)$data['tender_amount'] : null,
            'change_amount' => isset($data['change_amount']) ? (float)$data['change_amount'] : null,
            'split_details' => !empty($data['split_details']) ? json_encode($data['split_details']) : null
        ]);

        if ($customerId && $redeemPoints > 0) {
            $discountAmount = $this->loyaltyService->redeemPoints($customerId, $invoice['id'], $redeemPoints);
            if ($discountAmount > 0) {
                // Adjust total amount in DB
                $totalAmount = max(0, $totalAmount - $discountAmount);
                $this->invoiceRepo->update($invoice['id'], ['total_amount' => $totalAmount]);
            }
        }
        
        $customDiscount = isset($data['custom_discount']) ? (float)$data['custom_discount'] : 0.00;
        if ($customDiscount > 0) {
            $totalAmount = max(0, $totalAmount - $customDiscount);
            $this->invoiceRepo->update($invoice['id'], ['total_amount' => $totalAmount]);
        }

        // 4. Create Items
        foreach ($processedItems as $pItem) {
            $itemType = $pItem['type'];
            $itemId = $pItem['item_id'];
            
            unset($pItem['type'], $pItem['item_id']);
            $pItem['invoice_id'] = $invoice['id'];
            
            if ($itemType === 'service' && $itemId) {
                $pItem['service_id'] = $itemId;
            }
            
            $this->itemRepo->create($pItem);
            
            // Provision package if purchased by a customer
            if ($customerId && $itemType === 'package' && $itemId) {
                $package = $this->packageRepo->getById($itemId);
                if ($package && !empty($package['services'])) {
                    // Create customer package for each quantity
                    for ($i = 0; $i < $pItem['quantity']; $i++) {
                        $cp = $this->customerPackageRepo->create([
                            'customer_id' => $customerId,
                            'package_id' => $itemId,
                            'status' => 'active'
                        ]);
                        
                        foreach ($package['services'] as $pkgService) {
                            // Defaulting quantity to 1 for each service in the package bundle
                            $this->customerPackageRepo->addService($cp['id'], $pkgService['id'], 1);
                        }
                    }
                }
            }
        }

        if ($discountAmount > 0) {
            $this->itemRepo->create([
                'invoice_id' => $invoice['id'],
                'description' => 'Loyalty Points Discount',
                'quantity' => 1,
                'unit_price' => -$discountAmount,
                'subtotal' => -$discountAmount
            ]);
        }

        if ($customDiscount > 0) {
            $this->itemRepo->create([
                'invoice_id' => $invoice['id'],
                'description' => 'Special Discount',
                'quantity' => 1,
                'unit_price' => -$customDiscount,
                'subtotal' => -$customDiscount
            ]);
        }

        // Award points on the FINAL paid amount (after discount)
        if ($customerId) {
            $this->loyaltyService->awardPoints($customerId, $invoice['id'], $totalAmount);
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

    /**
     * Mark an existing invoice as paid.
     */
    public function payInvoice(int $invoiceId, array $data): array
    {
        $invoice = $this->invoiceRepo->getById($invoiceId);
        if (!$invoice) {
            throw new Exception("Invoice not found.");
        }
        if ($invoice['status'] === 'paid') {
            throw new Exception("Invoice is already paid.");
        }

        $updateData = [
            'status' => 'paid',
            'payment_method' => $data['payment_method'] ?? 'cash',
            'tender_amount' => isset($data['tender_amount']) ? (float)$data['tender_amount'] : null,
            'change_amount' => isset($data['change_amount']) ? (float)$data['change_amount'] : null,
            'split_details' => !empty($data['split_details']) ? json_encode($data['split_details']) : null
        ];

        $this->invoiceRepo->update($invoiceId, $updateData);
        
        // Award points
        if (!empty($invoice['customer_id'])) {
            $this->loyaltyService->awardPoints((int)$invoice['customer_id'], $invoiceId, (float)$invoice['total_amount']);
        }

        // Return updated invoice
        return array_merge($invoice, $updateData);
    }

    /**
     * Create a bulk invoice for multiple appointments.
     */
    public function createBulkInvoice(int $customerId, array $appointmentIds): array
    {
        if (empty($appointmentIds)) {
            throw new Exception("No appointments selected for bulk invoice.");
        }

        $totalAmount = 0.00;
        $processedItems = [];

        foreach ($appointmentIds as $aptId) {
            $appointment = $this->appointmentRepo->getAppointmentDetails($aptId);
            if (!$appointment) {
                continue;
            }
            if ($appointment['customer_id'] !== $customerId) {
                throw new Exception("Appointment $aptId does not belong to this customer.");
            }
            if ($appointment['invoice_id']) {
                throw new Exception("Appointment $aptId is already invoiced.");
            }

            $price = (float)($appointment['service_price'] ?? 0);
            $totalAmount += $price;

            $processedItems[] = [
                'appointment_id' => $aptId,
                'description' => $appointment['service'] . ' (' . date('M d, Y', strtotime($appointment['date'])) . ')',
                'quantity' => 1,
                'unit_price' => $price,
                'subtotal' => $price,
                'employee_id' => $appointment['user_id'] ?? null
            ];
        }

        if (empty($processedItems)) {
            throw new Exception("No valid appointments found to invoice.");
        }

        // Create Invoice
        $invoice = $this->invoiceRepo->create([
            'customer_id' => $customerId,
            'total_amount' => $totalAmount,
            'status' => 'unpaid',
            'payment_method' => null,
            'appointment_id' => null // Null because it's a bulk invoice
        ]);

        // Create Items and Update Appointments
        foreach ($processedItems as $pItem) {
            $aptId = $pItem['appointment_id'];
            unset($pItem['appointment_id']);
            $employeeId = $pItem['employee_id'];
            unset($pItem['employee_id']);

            $pItem['invoice_id'] = $invoice['id'];
            $this->itemRepo->create($pItem);

            $this->appointmentRepo->setInvoiceId($aptId, $invoice['id']);

            // Calculate Commission
            if ($employeeId) {
                $user = $this->userRepo->getById($employeeId);
                if ($user && isset($user['commission_rate']) && $user['commission_rate'] > 0) {
                    $commissionAmount = $pItem['subtotal'] * ($user['commission_rate'] / 100);
                    $this->commissionRepo->create([
                        'user_id' => $employeeId,
                        'invoice_id' => $invoice['id'],
                        'amount' => $commissionAmount
                    ]);
                }
            }
        }

        return $invoice;
    }

    public function payAllUnpaidInvoices(int $customerId, array $paymentData): void
    {
        $invoices = $this->invoiceRepo->getByCustomerId($customerId);
        $totalPaid = 0;
        
        foreach ($invoices as $invoice) {
            if ($invoice['status'] === 'unpaid') {
                $this->invoiceRepo->update($invoice['id'], [
                    'status' => 'paid',
                    'payment_method' => $paymentData['payment_method'] ?? 'Cash',
                    'tender_amount' => $invoice['total_amount'],
                    'change_amount' => 0
                ]);
                
                if (!empty($invoice['appointment_id'])) {
                    $this->appointmentRepo->updateStatus($invoice['appointment_id'], 'paid');
                }
                
                // Award points
                $this->loyaltyService->awardPoints($customerId, $invoice['id'], (float)$invoice['total_amount']);
            }
        }
    }
}
