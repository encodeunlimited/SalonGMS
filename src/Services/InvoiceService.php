<?php

namespace App\Services;

use App\Repositories\InvoiceRepository;
use App\Repositories\InvoiceItemRepository;
use Exception;

class InvoiceService extends BaseService
{
    private InvoiceRepository $invoiceRepo;
    private InvoiceItemRepository $itemRepo;

    public function __construct(InvoiceRepository $invoiceRepo, InvoiceItemRepository $itemRepo)
    {
        $this->invoiceRepo = $invoiceRepo;
        $this->itemRepo = $itemRepo;
    }

    public function setTenantId(int $tenantId): self
    {
        parent::setTenantId($tenantId);
        $this->invoiceRepo->setTenantId($tenantId);
        $this->itemRepo->setTenantId($tenantId);
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
            'payment_method' => $data['payment_method'] ?? 'cash'
        ]);

        // 4. Create Items
        foreach ($processedItems as $pItem) {
            $pItem['invoice_id'] = $invoice['id'];
            $this->itemRepo->create($pItem);
        }

        return $invoice;
    }
}
