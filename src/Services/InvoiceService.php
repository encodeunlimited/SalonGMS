<?php

namespace App\Services;

use App\Repositories\InvoiceRepository;
use App\Repositories\InvoiceItemRepository;
use App\Repositories\CommissionRepository;
use App\Repositories\UserRepository;
use App\Repositories\AppointmentRepository;
use App\Repositories\PackageRepository;
use App\Repositories\CustomerPackageRepository;
use App\Repositories\CustomerRepository;
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
    private CustomerRepository $customerRepo;

    public function __construct(
        InvoiceRepository $invoiceRepo, 
        InvoiceItemRepository $itemRepo,
        CommissionRepository $commissionRepo,
        UserRepository $userRepo,
        AppointmentRepository $appointmentRepo,
        PackageRepository $packageRepo,
        CustomerPackageRepository $customerPackageRepo,
        LoyaltyService $loyaltyService,
        CustomerRepository $customerRepo
    ) {
        $this->invoiceRepo = $invoiceRepo;
        $this->itemRepo = $itemRepo;
        $this->commissionRepo = $commissionRepo;
        $this->userRepo = $userRepo;
        $this->appointmentRepo = $appointmentRepo;
        $this->packageRepo = $packageRepo;
        $this->customerPackageRepo = $customerPackageRepo;
        $this->loyaltyService = $loyaltyService;
        $this->customerRepo = $customerRepo;
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
        $this->customerRepo->setTenantId($tenantId);
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

        if (!empty($invoice['split_details']) && is_string($invoice['split_details'])) {
            $invoice['split_details'] = json_decode($invoice['split_details'], true);
        }
        
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
                'subtotal' => $subtotal,
                'initial_service_id' => !empty($item['initial_service_id']) ? (int)$item['initial_service_id'] : null,
                'initial_service_name' => $item['initial_service_name'] ?? null,
                'stylist_id' => !empty($item['stylist_id']) ? (int)$item['stylist_id'] : null,
                'stylist_name' => $item['stylist_name'] ?? null
            ];
        }

        // Apply loyalty discount if requested
        $discountAmount = 0.00;
        $customerId = !empty($data['customer_id']) ? (int)$data['customer_id'] : null;
        $redeemPoints = !empty($data['redeem_points']) ? (int)$data['redeem_points'] : 0;

        // Calculate final total to check points payment
        $finalTotalAmount = $totalAmount;
        if ($customerId && $redeemPoints > 0) {
            $discountAmount = $redeemPoints * $this->loyaltyService->getCurrencyPerPoint();
            $finalTotalAmount = max(0, $finalTotalAmount - $discountAmount);
        }
        $customDiscount = isset($data['custom_discount']) ? (float)$data['custom_discount'] : 0.00;
        if ($customDiscount > 0) {
            $finalTotalAmount = max(0, $finalTotalAmount - $customDiscount);
        }

        $paymentMethod = $data['payment_method'] ?? 'cash';
        $pointsPaymentAmount = 0.00;

        if ($paymentMethod === 'Points') {
            $pointsPaymentAmount = $finalTotalAmount;
        } elseif ($paymentMethod === 'Split' && !empty($data['split_details'])) {
            $splits = is_string($data['split_details']) ? json_decode($data['split_details'], true) : $data['split_details'];
            foreach ($splits as $split) {
                if (($split['method'] ?? '') === 'Points') {
                    $pointsPaymentAmount += (float)($split['amount'] ?? 0);
                }
            }
        }

        if ($pointsPaymentAmount > 0) {
            if (!$customerId) {
                throw new Exception("Customer must be selected to pay with points.");
            }
            $pointsNeeded = (int)ceil($pointsPaymentAmount / $this->loyaltyService->getCurrencyPerPoint());
            $customerPoints = $this->loyaltyService->getCustomerPoints($customerId);
            // We need to make sure they have enough for BOTH the discount redeem AND the payment
            if ($customerPoints < ($redeemPoints + $pointsNeeded)) {
                throw new Exception("Insufficient loyalty points balance for this transaction.");
            }
        }

        $creditPaymentAmount = 0.00;
        if ($paymentMethod === 'Credit') {
            $creditPaymentAmount = $finalTotalAmount;
        } elseif ($paymentMethod === 'Split' && !empty($data['split_details'])) {
            $splits = is_string($data['split_details']) ? json_decode($data['split_details'], true) : $data['split_details'];
            foreach ($splits as $split) {
                if (($split['method'] ?? '') === 'Credit') {
                    $creditPaymentAmount += (float)($split['amount'] ?? 0);
                }
            }
        }

        if ($creditPaymentAmount > 0 && !$customerId) {
            throw new Exception("Customer must be selected to pay on Credit.");
        }

        // 3. Create Invoice
        $invoiceStatus = ($paymentMethod === 'Credit') ? 'unpaid' : 'paid';
        $invoice = $this->invoiceRepo->create([
            'customer_id' => $customerId,
            'total_amount' => $totalAmount, 
            'status' => $invoiceStatus,
            'payment_method' => $paymentMethod,
            'tender_amount' => isset($data['tender_amount']) ? (float)$data['tender_amount'] : null,
            'change_amount' => isset($data['change_amount']) ? (float)$data['change_amount'] : null,
            'split_details' => !empty($data['split_details']) ? (is_string($data['split_details']) ? $data['split_details'] : json_encode($data['split_details'])) : null
        ]);

        if ($customerId && $redeemPoints > 0) {
            $actualDiscountAmount = $this->loyaltyService->redeemPoints($customerId, $invoice['id'], $redeemPoints);
            if ($actualDiscountAmount > 0) {
                $totalAmount = max(0, $totalAmount - $actualDiscountAmount);
                $this->invoiceRepo->update($invoice['id'], ['total_amount' => $totalAmount]);
            }
        }
        
        if ($customDiscount > 0) {
            $totalAmount = max(0, $totalAmount - $customDiscount);
            $this->invoiceRepo->update($invoice['id'], ['total_amount' => $totalAmount]);
        }

        if ($pointsPaymentAmount > 0) {
            $this->loyaltyService->payWithPoints($customerId, $invoice['id'], $pointsPaymentAmount);
        }

        if ($creditPaymentAmount > 0) {
            $customer = $this->customerRepo->getById($customerId);
            $newCredit = ($customer['credit_balance'] ?? 0) + $creditPaymentAmount;
            $this->customerRepo->updateCreditBalance($customerId, $newCredit);
        }

        // 4. Create Items
        foreach ($processedItems as $pItem) {
            $itemType = $pItem['type'];
            $itemId = $pItem['item_id'];
            $itemStylistId = $pItem['stylist_id'] ?? null;
            $itemStylistName = $pItem['stylist_name'] ?? null;
            
            unset($pItem['type'], $pItem['item_id'], $pItem['stylist_id'], $pItem['stylist_name']);
            $pItem['invoice_id'] = $invoice['id'];
            
            if ($itemType === 'service' && $itemId) {
                $pItem['service_id'] = $itemId;
            }
            
            $this->itemRepo->create($pItem);
            
            // Create a 'done' appointment for standalone services and redemptions (walk-in)
            if (empty($data['appointment_id']) && ($itemType === 'service' || $itemType === 'redemption')) {
                $employeeId = !empty($itemStylistId) ? (int)$itemStylistId : (!empty($data['employee_id']) ? (int)$data['employee_id'] : null);
                $stylistName = !empty($itemStylistName) ? $itemStylistName : 'Unknown';
                if (!$itemStylistName && $employeeId) {
                    $employee = $this->userRepo->getById($employeeId);
                    if ($employee) $stylistName = $employee['name'];
                }
                
                $customerName = 'Walk-in Customer';
                if ($customerId) {
                    $customer = $this->customerRepo->getById($customerId);
                    if ($customer) $customerName = $customer['name'];
                }

                for ($i = 0; $i < $pItem['quantity']; $i++) {
                    $this->appointmentRepo->create([
                        'customer_id' => $customerId,
                        'customer_name' => $customerName,
                        'stylist_id' => $employeeId,
                        'stylist_name' => $stylistName,
                        'service_id' => $itemId, // For redemption, itemId is the customer_package_service_id
                        'service_name' => $pItem['description'],
                        'date' => date('Y-m-d'),
                        'time' => date('H:i'),
                        'status' => 'done',
                        'booking_type' => 'Walk-in',
                        'invoice_id' => $invoice['id']
                    ]);
                }
            }
            
            // Provision package if purchased by a customer (skip if this came from an appointment checkout, as it was already provisioned)
            if ($customerId && $itemType === 'package' && $itemId && empty($data['appointment_id'])) {
                $package = $this->packageRepo->getById($itemId);
                if ($package && !empty($package['services'])) {
                    
                    $expiresAt = null;
                    if (!empty($package['validity_months'])) {
                        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$package['validity_months']} months"));
                    }

                    // Create customer package for each quantity
                    for ($i = 0; $i < $pItem['quantity']; $i++) {
                        $cp = $this->customerPackageRepo->create([
                            'customer_id' => $customerId,
                            'package_id' => $itemId,
                            'status' => 'active',
                            'expires_at' => $expiresAt
                        ]);
                        
                        foreach ($package['services'] as $pkgService) {
                            $quantity = $pkgService['quantity'] ?? 1; // Default to 1 if quantity not specified in package items
                            $cpsId = $this->customerPackageRepo->addService($cp['id'], $pkgService['id'], $quantity);
                            
                            // If this service was selected for immediate redemption during purchase
                            if (!empty($pItem['initial_service_id']) && $pItem['initial_service_id'] == $pkgService['id']) {
                                // Increment used quantity (deduct it)
                                $this->customerPackageRepo->incrementUsedQuantity($cpsId);
                                
                                // Get customer info and employee info
                                $customer = $this->customerRepo->getById($customerId);
                                $employeeId = !empty($data['employee_id']) ? (int)$data['employee_id'] : null;
                                $stylistName = 'Unknown';
                                if ($employeeId) {
                                    $employee = $this->userRepo->getById($employeeId);
                                    if ($employee) $stylistName = $employee['name'];
                                }
                                
                                // Create a 'done' appointment for this initial redemption
                                $this->appointmentRepo->create([
                                    'customer_id' => $customerId,
                                    'customer_name' => $customer['name'] ?? 'Unknown',
                                    'stylist_id' => $employeeId,
                                    'stylist_name' => $stylistName,
                                    'service_id' => $pkgService['id'],
                                    'service_name' => 'Package: ' . $package['name'] . ' (Package Redemption) - ' . $pkgService['name'],
                                    'date' => date('Y-m-d'),
                                    'time' => date('H:i'),
                                    'status' => 'done',
                                    'booking_type' => 'Walk-in',
                                    'invoice_id' => $invoice['id']
                                ]);
                            }
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

    public function payInvoice(int $invoiceId, array $data): array
    {
        $invoice = $this->invoiceRepo->getById($invoiceId);
        if (!$invoice) {
            throw new Exception("Invoice not found.");
        }
        if ($invoice['status'] === 'paid') {
            throw new Exception("Invoice is already paid.");
        }

        $paymentMethod = $data['payment_method'] ?? 'cash';
        $pointsPaymentAmount = 0.00;

        if ($paymentMethod === 'Points') {
            $pointsPaymentAmount = (float)$invoice['total_amount'];
        } elseif ($paymentMethod === 'Split' && !empty($data['split_details'])) {
            $splits = is_string($data['split_details']) ? json_decode($data['split_details'], true) : $data['split_details'];
            foreach ($splits as $split) {
                if (($split['method'] ?? '') === 'Points') {
                    $pointsPaymentAmount += (float)($split['amount'] ?? 0);
                }
            }
        }

        $creditPaymentAmount = 0.00;
        if ($paymentMethod === 'Credit') {
            $creditPaymentAmount = (float)$invoice['total_amount'];
        } elseif ($paymentMethod === 'Split' && !empty($data['split_details'])) {
            $splits = is_string($data['split_details']) ? json_decode($data['split_details'], true) : $data['split_details'];
            foreach ($splits as $split) {
                if (($split['method'] ?? '') === 'Credit') {
                    $creditPaymentAmount += (float)($split['amount'] ?? 0);
                }
            }
        }

        $customerId = $invoice['customer_id'] ? (int)$invoice['customer_id'] : null;

        if ($pointsPaymentAmount > 0) {
            if (!$customerId) {
                throw new Exception("Customer must be selected to pay with points.");
            }
            $pointsNeeded = (int)ceil($pointsPaymentAmount / $this->loyaltyService->getCurrencyPerPoint());
            $customerPoints = $this->loyaltyService->getCustomerPoints($customerId);
            if ($customerPoints < $pointsNeeded) {
                throw new Exception("Insufficient loyalty points balance for this transaction.");
            }
        }

        $updateData = [
            'status' => 'paid',
            'payment_method' => $paymentMethod,
            'tender_amount' => isset($data['tender_amount']) ? (float)$data['tender_amount'] : null,
            'change_amount' => isset($data['change_amount']) ? (float)$data['change_amount'] : null,
            'split_details' => !empty($data['split_details']) ? (is_string($data['split_details']) ? $data['split_details'] : json_encode($data['split_details'])) : null
        ];

        if ($customerId && $invoice['payment_method'] === 'Credit') {
            $customer = $this->customerRepo->getById($customerId);
            if ($customer) {
                $newCredit = max(0, ($customer['credit_balance'] ?? 0) - (float)$invoice['total_amount']);
                $this->customerRepo->updateCreditBalance($customerId, $newCredit);
            }
        }

        $this->invoiceRepo->update($invoiceId, $updateData);
        
        if ($pointsPaymentAmount > 0) {
            $this->loyaltyService->payWithPoints($customerId, $invoiceId, $pointsPaymentAmount);
        }

        if ($creditPaymentAmount > 0) {
            $customer = $this->customerRepo->getById($customerId);
            $newCredit = ($customer['credit_balance'] ?? 0) + $creditPaymentAmount;
            $this->customerRepo->updateCreditBalance($customerId, $newCredit);
        }

        // Award points
        if ($customerId) {
            $this->loyaltyService->awardPoints($customerId, $invoiceId, (float)$invoice['total_amount']);
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
            
            // Fix price for Package Redemption and Package Purchase
            if (strpos($appointment['service'], '(Package Redemption)') !== false) {
                $price = 0.00;
            } elseif (strpos($appointment['service'], 'Package: ') === 0) {
                $packageName = preg_replace('/^Package: (.*?) \(First Service: .*\)$/', '$1', $appointment['service']);
                $packageName = str_replace('Package: ', '', $packageName);
                
                $packagesList = $this->packageRepo->getAll(); // Cache optimization if called multiple times, but this is fine for bulk
                foreach ($packagesList as $pkg) {
                    if ($pkg['name'] === $packageName) {
                        $price = (float)$pkg['price'];
                        break;
                    }
                }
            }

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
        $creditSettledAmount = 0.00;
        
        foreach ($invoices as $invoice) {
            if ($invoice['status'] === 'unpaid') {
                if ($invoice['payment_method'] === 'Credit') {
                    $creditSettledAmount += (float)$invoice['total_amount'];
                }

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

        if ($creditSettledAmount > 0) {
            $customer = $this->customerRepo->getById($customerId);
            if ($customer) {
                $newCredit = max(0, ($customer['credit_balance'] ?? 0) - $creditSettledAmount);
                $this->customerRepo->updateCreditBalance($customerId, $newCredit);
            }
        }
    }

    public function paySelectedInvoices(int $customerId, array $invoiceIds, array $paymentData): void
    {
        if (empty($invoiceIds)) {
            return;
        }

        $invoices = $this->invoiceRepo->getByCustomerId($customerId);
        $creditSettledAmount = 0.00;
        
        foreach ($invoices as $invoice) {
            if ($invoice['status'] === 'unpaid' && in_array((string)$invoice['id'], $invoiceIds)) {
                if ($invoice['payment_method'] === 'Credit') {
                    $creditSettledAmount += (float)$invoice['total_amount'];
                }

                $this->invoiceRepo->update($invoice['id'], [
                    'status' => 'paid',
                    'payment_method' => $paymentData['payment_method'] ?? 'Cash',
                    'tender_amount' => $invoice['total_amount'],
                    'change_amount' => 0
                ]);
                
                if (!empty($invoice['appointment_id'])) {
                    $this->appointmentRepo->updateStatus($invoice['appointment_id'], 'paid');
                }
                
                $this->loyaltyService->awardPoints($customerId, $invoice['id'], (float)$invoice['total_amount']);
            }
        }

        if ($creditSettledAmount > 0) {
            $customer = $this->customerRepo->getById($customerId);
            if ($customer) {
                $newCredit = max(0, ($customer['credit_balance'] ?? 0) - $creditSettledAmount);
                $this->customerRepo->updateCreditBalance($customerId, $newCredit);
            }
        }
    }
}
