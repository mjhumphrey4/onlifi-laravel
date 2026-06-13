<?php
// sms_helper.php
// SMS notification helper for sending voucher codes to customers

// Check if composer autoload exists before requiring
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} elseif (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

use PahappaLimited\CommsSDK\v1\CommsSDK;

/**
 * Send voucher code via SMS to customer
 * 
 * @param string $msisdn Phone number in format 256XXXXXXXXX
 * @param string $voucherCode The voucher code to send
 * @param string $packageName Optional package name for context
 * @return array ['success' => bool, 'message' => string, 'response' => mixed]
 */
function sendVoucherSMS($msisdn, $voucherCode, $packageName = '') {
    // Check if SDK is available
    if (!class_exists('PahappaLimited\CommsSDK\v1\CommsSDK')) {
        return [
            'success' => false,
            'message' => 'SMS SDK not installed. Run: composer install',
            'response' => null
        ];
    }
    
    try {
        // Authenticate with CommsSDK
        $sdk = CommsSDK::authenticate('humphreympairwe', '32ccb38b175de8d61ce05263e9cadfd522f258bac05f931d');
        
        // Set custom sender ID for branding
        $sdk = $sdk->withSenderId('STK WIFI');
        
        // Construct SMS message
        $packageInfo = $packageName ? " for $packageName" : "";
        $message = "STK WIFI Kampala: Your $packageInfo voucher code is: $voucherCode. Thank you!";
        
        // Send SMS
        $success = $sdk->sendSMS($msisdn, $message);
        
        if ($success) {
            return [
                'success' => true,
                'message' => 'SMS sent successfully',
                'response' => $success
            ];
        } else {
            return [
                'success' => false,
                'message' => 'SMS sending failed',
                'response' => $success
            ];
        }
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'SMS error: ' . $e->getMessage(),
            'response' => null
        ];
    }
}

/**
 * Send payment confirmation SMS (without voucher)
 * 
 * @param string $msisdn Phone number in format 256XXXXXXXXX
 * @param string $amount Payment amount
 * @param string $status Payment status message
 * @return array ['success' => bool, 'message' => string, 'response' => mixed]
 */
function sendPaymentStatusSMS($msisdn, $amount, $status) {
    // Check if SDK is available
    if (!class_exists('PahappaLimited\CommsSDK\v1\CommsSDK')) {
        return [
            'success' => false,
            'message' => 'SMS SDK not installed',
            'response' => null
        ];
    }
    
    try {
        $sdk = CommsSDK::authenticate('humphreympairwe', '32ccb38b175de8d61ce05263e9cadfd522f258bac05f931d');
        $sdk = $sdk->withSenderId('STK WIFI');
        
        $message = "STK WIFI: Payment of UGX $amount - $status. For support, call 0786979317.";
        
        $success = $sdk->sendSMS($msisdn, $message);
        
        return [
            'success' => $success,
            'message' => $success ? 'SMS sent successfully' : 'SMS sending failed',
            'response' => $success
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'SMS error: ' . $e->getMessage(),
            'response' => null
        ];
    }
}

/**
 * Check SMS account balance
 * 
 * @return array ['success' => bool, 'balance' => float|null, 'message' => string]
 */
function checkSMSBalance() {
    if (!class_exists('PahappaLimited\CommsSDK\v1\CommsSDK')) {
        return [
            'success' => false,
            'balance' => null,
            'message' => 'SMS SDK not installed'
        ];
    }
    
    try {
        $sdk = CommsSDK::authenticate('humphreympairwe', '32ccb38b175de8d61ce05263e9cadfd522f258bac05f931d');
        $balance = $sdk->getBalance();
        
        return [
            'success' => true,
            'balance' => $balance,
            'message' => "Balance: $balance"
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'balance' => null,
            'message' => 'Error checking balance: ' . $e->getMessage()
        ];
    }
}
?>
