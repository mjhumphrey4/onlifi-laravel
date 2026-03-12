<?php

namespace App\Http\Controllers;

use App\Services\YoPaymentService;
use App\Services\VoucherService;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    private $paymentService;
    private $voucherService;
    private $smsService;

    public function __construct(
        YoPaymentService $paymentService,
        VoucherService $voucherService,
        SmsService $smsService
    ) {
        $this->paymentService = $paymentService;
        $this->voucherService = $voucherService;
        $this->smsService = $smsService;
    }

    public function initiate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:200',
            'msisdn' => 'required|regex:/^256\d{9}$/',
            'origin_site' => 'required|string',
            'client_mac' => 'required|string',
            'voucher_type' => 'required|string',
            'origin_url' => 'required|url',
            'email' => 'nullable|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $result = $this->paymentService->initiatePayment($request->all());
            return response()->json($result);
        } catch (\Exception $e) {
            Log::error('Payment initiation error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => -1,
                'errorMessage' => 'Payment initiation failed',
            ], 500);
        }
    }

    public function checkStatus(Request $request)
    {
        $ref = $request->query('ref');

        if (!$ref) {
            return response()->json([
                'transactionStatus' => -1,
                'errorMessage' => 'Missing transaction reference',
            ], 400);
        }

        try {
            $result = $this->paymentService->checkTransactionStatus($ref);
            return response()->json($result);
        } catch (\Exception $e) {
            Log::error('Status check error', [
                'ref' => $ref,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'transactionStatus' => -1,
                'errorMessage' => 'Status check failed',
            ], 500);
        }
    }

    public function ipn(Request $request)
    {
        Log::info('IPN received', ['data' => $request->all()]);

        try {
            $verified = $this->paymentService->handleIPN($request->all());

            if ($verified) {
                $externalRef = $request->input('ExternalReference');
                
                $voucherResult = $this->voucherService->assignVoucherToTransaction($externalRef);

                if ($voucherResult['success']) {
                    $msisdn = $request->input('MobileMoneyNumber');
                    $voucherCode = $voucherResult['voucherCode'];

                    $this->smsService->sendVoucherSMS($msisdn, $voucherCode);

                    Log::info('Voucher assigned and SMS sent', [
                        'external_ref' => $externalRef,
                        'voucher_code' => $voucherCode,
                    ]);
                }
            }

            return response('OK', 200);
        } catch (\Exception $e) {
            Log::error('IPN processing error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response('ERROR', 500);
        }
    }

    public function failure(Request $request)
    {
        Log::warning('Payment failure notification', ['data' => $request->all()]);
        return response('OK', 200);
    }
}
