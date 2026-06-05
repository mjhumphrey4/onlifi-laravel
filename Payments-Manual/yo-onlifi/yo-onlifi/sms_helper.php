<?php
// sms_helper.php
// SMS notification helper for sending voucher codes to customers

// Check if composer autoload exists before requiring
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
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
        // Using credentials from your smsapi.php
        $sdk = CommsSDK::authenticate('humphreympairwe', '32ccb38b175de8d61ce05263e9cadfd522f258bac05f931d');
        
        // Set custom sender ID for branding
        $sdk = $sdk->withSenderId('STK WIFI');
        
        // Construct SMS message
        $packageInfo = $packageName ? " for $packageName" : "";
        $message = "STK WIFI Kampala: Your $packageInfo voucher code is: $voucherCode. Thank you!";
        
        // Send SMS
        $success = sendSmsWithRetries($sdk, $msisdn, $message);
        
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
        
        $success = sendSmsWithRetries($sdk, $msisdn, $message);
        
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
 * Send TWO voucher codes via SMS to customer (for special 10,000/= 7-day package)
 * 
 * @param string $msisdn Phone number in format 256XXXXXXXXX
 * @param array $voucherCodes Array of two voucher codes to send
 * @param string $packageName Optional package name for context
 * @return array ['success' => bool, 'message' => string, 'response' => mixed]
 */
function sendTwoVouchersSMS($msisdn, $voucherCodes, $packageName = '') {
    if (!class_exists('PahappaLimited\CommsSDK\v1\CommsSDK')) {
        return [
            'success' => false,
            'message' => 'SMS SDK not installed. Run: composer install',
            'response' => null
        ];
    }
    
    if (!is_array($voucherCodes) || count($voucherCodes) < 2) {
        return [
            'success' => false,
            'message' => 'Two voucher codes required',
            'response' => null
        ];
    }
    
    try {
        $sdk = CommsSDK::authenticate('humphreympairwe', '32ccb38b175de8d61ce05263e9cadfd522f258bac05f931d');
        $sdk = $sdk->withSenderId('STK WIFI');
        
        $packageInfo = $packageName ? " for $packageName" : "";
        $message = "STK WIFI Kampala: Your$packageInfo voucher codes are: " . $voucherCodes[0] . " and " . $voucherCodes[1] . ". Thank you!";
        
        $success = sendSmsWithRetries($sdk, $msisdn, $message);
        
        if ($success) {
            return [
                'success' => true,
                'message' => 'SMS sent successfully with 2 voucher codes',
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

function sendSmsWithRetries($sdk, string $msisdn, string $message) {
    $attempts = 3;
    $lastResult = false;

    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
        $lastResult = $sdk->sendSMS($msisdn, $message);

        if ($lastResult) {
            return $lastResult;
        }

        if ($attempt < $attempts) {
            usleep(250000);
        }
    }

    return $lastResult;
}
?>
