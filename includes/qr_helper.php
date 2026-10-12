<?php
/**
 * QR Code Helper Class
 * Uses PHP QR Code library
 */

class QRHelper {
    private $qr_dir;
    
    public function __construct() {
        $this->qr_dir = dirname(__DIR__) . '/uploads/qrcodes/';
        
        // Create directory if it doesn't exist
        if (!file_exists($this->qr_dir)) {
            mkdir($this->qr_dir, 0777, true);
        }
    }
    
    /**
     * Generate QR Code using endroid/qr-code library or fallback
     */
    public function generateQRCode($data, $filename) {
        // Try to use Composer's endroid/qr-code if available
        if (class_exists('Endroid\QrCode\QrCode')) {
            return $this->generateWithEndroid($data, $filename);
        }
        
        // Fallback: Use Google Charts API (simple but works)
        return $this->generateWithGoogleAPI($data, $filename);
    }
    
    /**
     * Generate using endroid/qr-code library
     */
    private function generateWithEndroid($data, $filename) {
        try {
            $qrCode = new Endroid\QrCode\QrCode($data);
            $qrCode->setSize(200);
            $qrCode->setMargin(10);
            $qrCode->writeFile($filename);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Fallback: Use Google Charts API
     */
    private function generateWithGoogleAPI($data, $filename) {
        $qr_code_url = "https://chart.googleapis.com/chart?chs=200x200&cht=qr&chl=" . urlencode($data) . "&choe=UTF-8";
        
        $ch = curl_init($qr_code_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $image_data = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code == 200 && $image_data) {
            file_put_contents($filename, $image_data);
            return true;
        }
        
        return false;
    }
    
    /**
     * Generate QR code as base64 image
     */
    public function generateQRCodeBase64($data) {
        // Use a free QR code API
        $qr_code_url = "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=" . urlencode($data);
        
        $ch = curl_init($qr_code_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $image_data = curl_exec($ch);
        curl_close($ch);
        
        if ($image_data) {
            return 'data:image/png;base64,' . base64_encode($image_data);
        }
        
        return null;
    }
}
?>