<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use Slim\Views\Twig;
use Exception;

class PdfService
{
    private Twig $view;

    public function __construct(Twig $view)
    {
        $this->view = $view;
    }

    /**
     * Generates a PDF invoice and saves it to the public directory.
     * Returns the public URL path to the generated PDF.
     */
    public function generateInvoicePdf(array $invoice, string $baseUrl = ''): string
    {
        $html = $this->view->fetch('invoices/view.twig', [
            'invoice' => $invoice,
            'title' => 'Invoice #' . sprintf('%05d', $invoice['id'])
        ]);

        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'Helvetica');
        
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper(array(0, 0, 226.77, 841.89), 'portrait');
        $dompdf->render();

        $pdfOutput = $dompdf->output();
        if (!$pdfOutput) {
            throw new Exception("Failed to generate PDF");
        }

        $publicDir = realpath(__DIR__ . '/../../public');
        $uploadDir = $publicDir . '/uploads/invoices';
        
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
                throw new Exception("Failed to create upload directory");
            }
        }

        $filename = 'invoice_' . $invoice['id'] . '_' . time() . '.pdf';
        $filePath = $uploadDir . '/' . $filename;
        
        if (file_put_contents($filePath, $pdfOutput) === false) {
            throw new Exception("Failed to save PDF to disk");
        }

        return rtrim($baseUrl, '/') . '/uploads/invoices/' . $filename;
    }

    public function generateBulkReceiptPdf(array $invoices, string $baseUrl = ''): string
    {
        $html = $this->view->fetch('invoices/bulk_receipt.twig', [
            'invoices' => $invoices,
            'title' => 'Bulk Payment Receipt'
        ]);

        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'Helvetica');
        
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper(array(0, 0, 226.77, 841.89), 'portrait');
        $dompdf->render();

        $pdfOutput = $dompdf->output();
        if (!$pdfOutput) {
            throw new Exception("Failed to generate bulk PDF receipt");
        }

        $publicDir = realpath(__DIR__ . '/../../public');
        $uploadDir = $publicDir . '/uploads/receipts';
        
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
                throw new Exception("Failed to create upload directory");
            }
        }

        $filename = 'bulk_receipt_' . time() . '.pdf';
        $filePath = $uploadDir . '/' . $filename;
        
        if (file_put_contents($filePath, $pdfOutput) === false) {
            throw new Exception("Failed to save PDF to disk");
        }

        return rtrim($baseUrl, '/') . '/uploads/receipts/' . $filename;
    }
}
