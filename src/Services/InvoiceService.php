<?php

namespace App\Services;

use App\Repositories\InvoiceRepository;
use App\Repositories\InvoiceItemRepository;
use App\Repositories\CommissionRepository;
use App\Repositories\UserRepository;
use App\Repositories\AppointmentRepository;
use Exception;

class InvoiceService extends BaseService
{
    private InvoiceRepository $invoiceRepo;
    private InvoiceItemRepository $itemRepo;
    private CommissionRepository $commissionRepo;
    private UserRepository $userRepo;
    private AppointmentRepository $appointmentRepo;

    public function __construct(
        InvoiceRepository $invoiceRepo, 
        InvoiceItemRepository $itemRepo,
        CommissionRepository $commissionRepo,
        UserRepository $userRepo,
        AppointmentRepository $appointmentRepo
    ) {
        $this->invoiceRepo = $invoiceRepo;
        $this->itemRepo = $itemRepo;
        $this->commissionRepo = $commissionRepo;
        $this->userRepo = $userRepo;
        $this->appointmentRepo = $appointmentRepo;
    }

    public function setTenantId(int $tenantId): self
    {
        parent::setTenantId($tenantId);
        $this->invoiceRepo->setTenantId($tenantId);
        $this->itemRepo->setTenantId($tenantId);
        $this->commissionRepo->setTenantId($tenantId);
        $this->userRepo->setTenantId($tenantId);
        $this->appointmentRepo->setTenantId($tenantId);
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
}
