<?php

namespace App\Services;

use Exception;

class WhatsAppService
{
    private string $providerUrl;
    private string $instanceId;
    private string $token;

    public function __construct(string $providerUrl = '', string $instanceId = '', string $token = '')
    {
        $this->providerUrl = rtrim($providerUrl, '/');
        $this->instanceId = $instanceId;
        $this->token = $token;
    }

    /**
     * Sends an invoice PDF via WhatsApp.
     */
    public function sendInvoice(string $phoneNumber, string $pdfUrl, string $customerName): bool
    {
        // Clean phone number (remove +, spaces, etc.)
        $cleanPhone = preg_replace('/[^0-9]/', '', $phoneNumber);
        
        if (empty($cleanPhone)) {
            return false; // Cannot send without phone
        }

        $message = "Hi {$customerName},\n\nYour payment has been received. Thank you for your business! Please find your invoice attached.\n\nBest Regards,\nSalonMS";

        // Placeholder for UltraMsg API or similar integration
        // The user can fill the actual URL, instance, and token in the config/settings.php or .env later.
        
        if (empty($this->providerUrl) || empty($this->token)) {
            // Mock mode: just return true to not break the application if API is not configured
            error_log("Mock WhatsApp message sent to {$cleanPhone} with PDF {$pdfUrl}");
            return true; 
        }

        // Example UltraMsg request structure
        $url = "{$this->providerUrl}/{$this->instanceId}/messages/document";
        
        $data = [
            'token' => $this->token,
            'to' => $cleanPhone,
            'document' => $pdfUrl,
            'filename' => 'Invoice.pdf',
            'caption' => $message
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            error_log("WhatsApp API Error: " . $err);
            return false;
        }

        return true;
    }
}
