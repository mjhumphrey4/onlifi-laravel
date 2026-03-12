# OnLiFi API Examples

Real-world examples of using the OnLiFi multi-tenant API.

## Authentication

All tenant API requests require authentication headers:

```bash
X-API-Key: onlifi_your_api_key_here
X-API-Secret: your_api_secret_here
```

## Payment Flow Example

### 1. Initiate Payment

```bash
curl -X POST http://localhost:8000/api/payments/initiate \
  -H "Content-Type: application/json" \
  -H "X-API-Key: onlifi_abc123..." \
  -H "X-API-Secret: xyz789..." \
  -d '{
    "amount": 2000,
    "msisdn": "256771234567",
    "origin_site": "MainSite",
    "client_mac": "AA:BB:CC:DD:EE:FF",
    "voucher_type": "Daily_2000",
    "origin_url": "https://mysite.com/payment",
    "email": "customer@example.com"
  }'
```

**Response:**
```json
{
  "status": 1,
  "transactionReference": "YO123456789",
  "externalReference": "TXN_1710288000_abc123",
  "statusMessage": "Transaction initiated successfully"
}
```

### 2. Check Payment Status

```bash
curl -X GET "http://localhost:8000/api/payments/check-status?ref=YO123456789" \
  -H "X-API-Key: onlifi_abc123..." \
  -H "X-API-Secret: xyz789..."
```

**Response (Pending):**
```json
{
  "transactionStatus": 0,
  "statusMessage": "Transaction pending",
  "voucherCode": null
}
```

**Response (Success):**
```json
{
  "transactionStatus": 1,
  "statusMessage": "Transaction successful",
  "voucherCode": "WIFI2024ABC"
}
```

### 3. IPN Webhook (Automatic)

YO! Payments sends this to your server:

```bash
POST http://localhost:8000/api/payments/ipn
Content-Type: application/json

{
  "ExternalReference": "TXN_1710288000_abc123",
  "MobileMoneyNumber": "256771234567",
  "Amount": 2000,
  "TransactionStatus": "SUCCEEDED",
  "NetworkReference": "MTN123456",
  "Narrative": "Payment received"
}
```

System automatically:
- Verifies the payment
- Updates transaction status
- Assigns a voucher
- Sends SMS to customer

## Voucher Management

### Generate Voucher Batch

```bash
curl -X POST http://localhost:8000/api/vouchers/generate-batch \
  -H "Content-Type: application/json" \
  -H "X-API-Key: onlifi_abc123..." \
  -H "X-API-Secret: xyz789..." \
  -d '{
    "voucher_type_id": 1,
    "quantity": 500,
    "group_name": "March 2024 Batch",
    "sales_point_id": 1
  }'
```

**Response:**
```json
{
  "message": "Voucher batch generated successfully",
  "group": {
    "id": 5,
    "name": "March 2024 Batch",
    "quantity": 500,
    "generated_count": 500,
    "status": "completed"
  },
  "vouchers_generated": 500
}
```

### List Vouchers

```bash
curl -X GET "http://localhost:8000/api/vouchers?status=active&page=1" \
  -H "X-API-Key: onlifi_abc123..." \
  -H "X-API-Secret: xyz789..."
```

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "code": "WIFI2024ABC",
      "username": "user001",
      "password": "pass001",
      "status": "active",
      "assigned_to": "256771234567",
      "assigned_at": "2024-03-13T10:30:00+03:00",
      "expires_at": "2024-03-14T10:30:00+03:00"
    }
  ],
  "current_page": 1,
  "total": 450
}
```

### Validate Voucher

```bash
curl -X POST http://localhost:8000/api/vouchers/validate \
  -H "Content-Type: application/json" \
  -H "X-API-Key: onlifi_abc123..." \
  -H "X-API-Secret: xyz789..." \
  -d '{
    "username": "user001",
    "password": "pass001"
  }'
```

**Response (Valid):**
```json
{
  "valid": true,
  "message": "Voucher is valid",
  "voucher": {
    "code": "WIFI2024ABC",
    "expires_at": "2024-03-14T10:30:00+03:00",
    "time_remaining": "23 hours"
  }
}
```

### Get Voucher Statistics

```bash
curl -X GET http://localhost:8000/api/vouchers/statistics \
  -H "X-API-Key: onlifi_abc123..." \
  -H "X-API-Secret: xyz789..."
```

**Response:**
```json
{
  "total_vouchers": 5000,
  "active_vouchers": 3200,
  "used_vouchers": 1500,
  "expired_vouchers": 300,
  "by_type": {
    "Daily_1000": 2000,
    "Weekly_5000": 1500,
    "Monthly_15000": 1500
  }
}
```

## MikroTik Router Management

### Add Router

```bash
curl -X POST http://localhost:8000/api/routers \
  -H "Content-Type: application/json" \
  -H "X-API-Key: onlifi_abc123..." \
  -H "X-API-Secret: xyz789..." \
  -d '{
    "name": "Kampala Office Router",
    "host": "192.168.1.1",
    "port": 8728,
    "username": "admin",
    "password": "mikrotik_pass",
    "location": "Kampala, Uganda"
  }'
```

**Response:**
```json
{
  "message": "Router added successfully",
  "router": {
    "id": 1,
    "name": "Kampala Office Router",
    "host": "192.168.1.1",
    "status": "active",
    "location": "Kampala, Uganda"
  }
}
```

### Test Router Connection

```bash
curl -X POST http://localhost:8000/api/routers/1/test-connection \
  -H "X-API-Key: onlifi_abc123..." \
  -H "X-API-Secret: xyz789..."
```

**Response:**
```json
{
  "success": true,
  "message": "Connection successful",
  "router_info": {
    "identity": "MikroTik",
    "version": "7.13",
    "model": "RB4011iGS+",
    "uptime": "2w3d4h"
  }
}
```

### Get Active Users

```bash
curl -X GET http://localhost:8000/api/routers/1/active-users \
  -H "X-API-Key: onlifi_abc123..." \
  -H "X-API-Secret: xyz789..."
```

**Response:**
```json
{
  "router_id": 1,
  "active_users": 45,
  "users": [
    {
      "username": "user001",
      "mac_address": "AA:BB:CC:DD:EE:FF",
      "ip_address": "10.5.50.10",
      "uptime": "2h30m",
      "bytes_in": 150000000,
      "bytes_out": 50000000
    }
  ]
}
```

### Ingest Telemetry Data

MikroTik routers send telemetry:

```bash
curl -X POST http://localhost:8000/api/routers/telemetry/ingest \
  -H "Content-Type: application/json" \
  -H "X-API-Key: onlifi_abc123..." \
  -H "X-API-Secret: xyz789..." \
  -d '{
    "router_id": 1,
    "cpu_load": 25.5,
    "memory_usage": 45.2,
    "uptime": 1234567,
    "active_users": 45,
    "total_bandwidth_in": 1500000000,
    "total_bandwidth_out": 500000000
  }'
```

## Transaction Reporting

### List Transactions

```bash
curl -X GET "http://localhost:8000/api/transactions?status=success&from=2024-03-01&to=2024-03-31" \
  -H "X-API-Key: onlifi_abc123..." \
  -H "X-API-Secret: xyz789..."
```

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "external_ref": "TXN_1710288000_abc123",
      "msisdn": "256771234567",
      "amount": 2000,
      "status": "success",
      "voucher_code": "WIFI2024ABC",
      "created_at": "2024-03-13T10:30:00+03:00"
    }
  ],
  "total": 1250,
  "successful": 1180,
  "failed": 70
}
```

### Get Transaction Statistics

```bash
curl -X GET http://localhost:8000/api/transactions/statistics \
  -H "X-API-Key: onlifi_abc123..." \
  -H "X-API-Secret: xyz789..."
```

**Response:**
```json
{
  "total_transactions": 1250,
  "successful_transactions": 1180,
  "failed_transactions": 70,
  "total_revenue": 2360000,
  "average_transaction": 2000,
  "today": {
    "transactions": 45,
    "revenue": 90000
  },
  "this_month": {
    "transactions": 1250,
    "revenue": 2360000
  }
}
```

### Daily Report

```bash
curl -X GET "http://localhost:8000/api/transactions/daily-report?date=2024-03-13" \
  -H "X-API-Key: onlifi_abc123..." \
  -H "X-API-Secret: xyz789..."
```

**Response:**
```json
{
  "date": "2024-03-13",
  "total_transactions": 45,
  "successful": 42,
  "failed": 3,
  "total_revenue": 90000,
  "by_hour": {
    "00": 2,
    "01": 1,
    "08": 5,
    "09": 8,
    "10": 12,
    "11": 10,
    "12": 7
  },
  "by_voucher_type": {
    "Daily_1000": 20,
    "Daily_2000": 15,
    "Weekly_5000": 7
  }
}
```

## Multi-Tenant Examples

### Tenant A Makes Payment

```bash
curl -X POST http://localhost:8000/api/payments/initiate \
  -H "X-API-Key: tenant_a_key" \
  -H "X-API-Secret: tenant_a_secret" \
  -d '{
    "amount": 1000,
    "msisdn": "256771111111",
    ...
  }'
```

### Tenant B Makes Payment (Same Time)

```bash
curl -X POST http://localhost:8000/api/payments/initiate \
  -H "X-API-Key: tenant_b_key" \
  -H "X-API-Secret: tenant_b_secret" \
  -d '{
    "amount": 2000,
    "msisdn": "256772222222",
    ...
  }'
```

**Result**: Both payments process independently with no conflicts. Each tenant's transaction is stored in their own isolated database.

### Tenant A Lists Their Transactions

```bash
curl -X GET http://localhost:8000/api/transactions \
  -H "X-API-Key: tenant_a_key" \
  -H "X-API-Secret: tenant_a_secret"
```

**Result**: Only sees Tenant A's transactions (not Tenant B's).

## Admin Tenant Management

### Create New Tenant

```bash
curl -X POST http://localhost:8000/api/admin/tenants \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Acme Corporation",
    "domain": "acme.example.com",
    "admin_name": "John Doe",
    "admin_email": "john@acme.com",
    "admin_password": "SecurePass123!"
  }'
```

### List All Tenants

```bash
curl -X GET http://localhost:8000/api/admin/tenants
```

### Get Tenant Stats

```bash
curl -X GET http://localhost:8000/api/admin/tenants/1/stats
```

### Suspend Tenant

```bash
curl -X POST http://localhost:8000/api/admin/tenants/1/suspend
```

### Regenerate API Credentials

```bash
curl -X POST http://localhost:8000/api/admin/tenants/1/regenerate-credentials
```

**Response:**
```json
{
  "message": "API credentials regenerated successfully",
  "api_credentials": {
    "api_key": "onlifi_new_key_here",
    "api_secret": "new_secret_here"
  }
}
```

## Error Responses

### Invalid API Credentials

```json
{
  "error": "Tenant not identified",
  "message": "Please provide valid API credentials"
}
```

### Tenant Inactive

```json
{
  "error": "Tenant inactive",
  "message": "Your account has been deactivated"
}
```

### Trial Expired

```json
{
  "error": "Access denied",
  "message": "Your trial has expired or subscription is inactive"
}
```

### Validation Error

```json
{
  "error": "Validation failed",
  "errors": {
    "msisdn": ["The msisdn format is invalid."],
    "amount": ["The amount must be at least 200."]
  }
}
```

## JavaScript/React Example

```javascript
const API_BASE = 'http://localhost:8000/api';
const API_KEY = 'onlifi_abc123...';
const API_SECRET = 'xyz789...';

async function initiatePayment(amount, msisdn) {
  const response = await fetch(`${API_BASE}/payments/initiate`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-API-Key': API_KEY,
      'X-API-Secret': API_SECRET,
    },
    body: JSON.stringify({
      amount,
      msisdn,
      origin_site: 'WebApp',
      client_mac: '00:00:00:00:00:00',
      voucher_type: `Feature_${amount}`,
      origin_url: window.location.href,
    }),
  });

  return await response.json();
}

async function checkStatus(transactionRef) {
  const response = await fetch(
    `${API_BASE}/payments/check-status?ref=${transactionRef}`,
    {
      headers: {
        'X-API-Key': API_KEY,
        'X-API-Secret': API_SECRET,
      },
    }
  );

  return await response.json();
}

// Usage
const result = await initiatePayment(2000, '256771234567');
console.log('Transaction Reference:', result.transactionReference);

// Poll for status
const interval = setInterval(async () => {
  const status = await checkStatus(result.transactionReference);
  
  if (status.transactionStatus === 1) {
    console.log('Payment successful! Voucher:', status.voucherCode);
    clearInterval(interval);
  } else if (status.transactionStatus < 0) {
    console.log('Payment failed:', status.errorMessage);
    clearInterval(interval);
  }
}, 5000);
```

## PHP Example

```php
<?php

$apiKey = 'onlifi_abc123...';
$apiSecret = 'xyz789...';
$apiBase = 'http://localhost:8000/api';

function makeRequest($endpoint, $method = 'GET', $data = null) {
    global $apiKey, $apiSecret, $apiBase;
    
    $ch = curl_init($apiBase . $endpoint);
    
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-API-Key: ' . $apiKey,
        'X-API-Secret: ' . $apiSecret,
    ]);
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}

// Initiate payment
$result = makeRequest('/payments/initiate', 'POST', [
    'amount' => 2000,
    'msisdn' => '256771234567',
    'origin_site' => 'PHPApp',
    'client_mac' => '00:00:00:00:00:00',
    'voucher_type' => 'Feature_2000',
    'origin_url' => 'https://mysite.com/payment',
]);

echo "Transaction Reference: " . $result['transactionReference'] . "\n";

// Check status
$status = makeRequest('/payments/check-status?ref=' . $result['transactionReference']);
echo "Status: " . $status['transactionStatus'] . "\n";
```

---

**These examples demonstrate the complete API functionality with real-world use cases.**
