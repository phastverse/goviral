<?php

namespace App\Services;

use App\Models\Logged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class KorapayService
{
    protected $baseUrl;
    protected $secretKey;
    protected $publicKey;
    protected $currency;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.korapay.base_url', 'https://api.korapay.com/merchant'), '/');
        $this->secretKey = trim(config('services.korapay.secret_key'));
        $this->publicKey = trim(config('services.korapay.public_key'));
        $this->currency = config('services.korapay.currency', 'NGN');
    }

    public function initializeTransaction($amount, $user, $redirectUrl)
    {
        try {
            if (empty($this->secretKey) || empty($this->publicKey)) {
                return [
                    'success' => false,
                    'message' => 'Payment gateway not configured. Please contact support.'
                ];
            }

            $reference = 'BOOSTER-FW-' . strtoupper(Str::random(10));

            $payload = [
                'reference' => $reference,
                'amount' => (int)$amount, // Korapay expects integer in kobo/cents
                'currency' => $this->currency,
                'redirect_url' => $redirectUrl,
                'customer' => [
                    'name' => $user->name,
                    'email' => $user->email,
                ],
                'notification_url' => route('wallet.webhook'), // For webhook notifications
                'merchant_bears_cost' => true, // we bear the cost
                'metadata' => [
                    'user_id' => $user->id,
                    'purpose' => 'wallet_topup',
                ],
            ];

            // Create initial log entry with PENDING status
            $logEntry = Logged::create([
                'user_id' => $user->id,
                'reference' => $reference,
                'type' => 'wallet_topup',
                'method' => 'Korapay',
                'amount' => $amount, // Store the ORIGINAL amount user intended to pay
                'status' => 'pending',
                'description' => 'Wallet top-up initiated',
                'request_data' => $payload,
                'ip_address' => request()->ip(),
            ]);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->secretKey,
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . '/api/v1/charges/initialize', $payload);

            $responseData = $response->json();

            // Update log with response
            $logEntry->update([
                'response_data' => $responseData,
            ]);

            if ($response->successful() && isset($responseData['status']) && $responseData['status'] === true) {
                if (isset($responseData['data']['checkout_url'])) {
                    return [
                        'success' => true,
                        'data' => [
                            'checkout_url' => $responseData['data']['checkout_url']
                        ],
                        'reference' => $reference
                    ];
                }

                // Update log as failed
                $logEntry->update([
                    'status' => 'failed',
                    'error_message' => 'Unexpected response structure',
                ]);

                return [
                    'success' => false,
                    'message' => 'Unexpected response from payment gateway'
                ];
            }

            $errorMessage = $responseData['message'] ?? 'Payment Gateway Error';

            // Update log as failed
            $logEntry->update([
                'status' => 'failed',
                'error_message' => $errorMessage,
            ]);

            return [
                'success' => false,
                'message' => $errorMessage
            ];

        } catch (\Exception $e) {
            // Update log if it exists
            if (isset($logEntry)) {
                $logEntry->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                ]);
            }

            \Log::error('Korapay Initialize Exception', [
                'message' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'An error occurred'
            ];
        }
    }

    public function verifyTransaction($reference)
    {
        try {
            if (empty($this->secretKey)) {
                return ['success' => false, 'message' => 'Configuration error'];
            }

            // Find the log entry first - we need the original amount
            $logEntry = Logged::where('reference', $reference)->first();
            
            if (!$logEntry) {
                return [
                    'success' => false,
                    'message' => 'Transaction not found'
                ];
            }

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->secretKey,
                'Content-Type' => 'application/json',
            ])->get($this->baseUrl . "/api/v1/charges/{$reference}");

            $responseData = $response->json();

            if ($response->successful() && isset($responseData['status']) && $responseData['status'] === true) {
                if (isset($responseData['data'])) {
                    $data = $responseData['data'];
                    $status = strtolower($data['status'] ?? '');

                    // Update log with verification response
                    $logEntry->update([
                        'response_data' => array_merge(
                            $logEntry->response_data ?? [],
                            ['verification' => $data]
                        ),
                    ]);

                    if ($status === 'success') {
                        // Update log as successful
                        $logEntry->update([
                            'status' => 'success',
                        ]);

                        // Return the ORIGINAL amount from our log (what user intended to pay)
                        // NOT the amount_paid from Korapay (which includes fees)
                        return [
                            'success' => true,
                            'amount' => $logEntry->amount, // Original amount from our database
                            'reference' => $reference,
                            'transaction_id' => $data['reference'] ?? null,
                            'payment_method' => $data['payment_method'] ?? 'korapay',
                        ];
                    } elseif ($status === 'processing' || $status === 'pending') {
                        $logEntry->update(['status' => 'pending']);

                        return [
                            'success' => false,
                            'message' => 'Payment is still pending',
                            'status' => 'pending'
                        ];
                    } elseif ($status === 'failed' || $status === 'cancelled') {
                        $logEntry->update([
                            'status' => 'failed',
                            'error_message' => 'Payment was ' . $status,
                        ]);

                        return [
                            'success' => false,
                            'message' => 'Payment was ' . $status,
                            'status' => 'failed'
                        ];
                    }

                    return [
                        'success' => false,
                        'message' => 'Payment status: ' . $status,
                        'status' => $status
                    ];
                }
            }

            // Update log as failed
            $logEntry->update([
                'status' => 'failed',
                'error_message' => 'Verification request failed',
                'response_data' => array_merge(
                    $logEntry->response_data ?? [],
                    ['verification_error' => $responseData]
                ),
            ]);

            return [
                'success' => false,
                'message' => 'Verification request failed'
            ];

        } catch (\Exception $e) {
            // Update log if it exists
            $logEntry = Logged::where('reference', $reference)->first();
            if ($logEntry) {
                $logEntry->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                ]);
            }

            return [
                'success' => false,
                'message' => 'Verification error: Pls contact customer support if you have been debited'            ];
        }
    }

    /**
     * Verify webhook signature using HMAC SHA256
     */
    public function verifyWebhookSignature($payload, $signature)
    {
        try {
            if (empty($this->secretKey)) {
                return false;
            }

            // Korapay signs only the 'data' object in the webhook payload
            $dataToSign = is_array($payload) ? json_encode($payload['data'] ?? $payload) : $payload;
            
            $expectedSignature = hash_hmac('sha256', $dataToSign, $this->secretKey);
            
            return hash_equals($expectedSignature, $signature);
        } catch (\Exception $e) {
            \Log::error('Korapay webhook signature verification failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Initiate payout/transfer to bank account
     */
    public function initiatePayout($amount, $bankName, $accountNumber, $accountName, $reference, $narration = 'Withdrawal', $customerEmail = '')
    {
        try {
            if (empty($this->secretKey)) {
                return [
                    'success' => false,
                    'message' => 'Payment gateway not configured. Please contact support.'
                ];
            }
 
            $bankCode = $this->getBankCode($bankName);

            if ($bankCode === '000') {
                \Log::warning("Unknown bank name provided: {$bankName}");
            }

            // Korapay payout payload — amount in Naira (2 decimal places), NOT kobo
            $payload = [
                'reference' => $reference,
                'destination' => [
                    'type'        => 'bank_account',
                    'amount'      => number_format((float)$amount, 2, '.', ''),  // e.g. "5000.00"
                    'currency'    => $this->currency,
                    'narration'   => $narration,
                    'bank_account' => [
                        'bank'    => $bankCode,
                        'account' => $accountNumber,
                    ],
                    'customer' => [
                        'name'  => $accountName,
                        'email' => $customerEmail,  // REQUIRED by Korapay
                    ],
                ],
            ];

            \Log::info('Korapay Payout Request', [
                'reference'      => $reference,
                'amount'         => $amount,
                'bank_name'      => $bankName,
                'bank_code'      => $bankCode,
                'account_number' => $accountNumber,
            ]);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->secretKey,
                'Content-Type'  => 'application/json',
            ])->post($this->baseUrl . '/api/v1/transactions/disburse', $payload);

            $responseData = $response->json();
            \Log::info('Korapay Payout Response', $responseData);

            if ($response->successful() && isset($responseData['status']) && $responseData['status'] === true) {
                return [
                    'success'    => true,
                    'transfer_id' => $responseData['data']['reference'] ?? null,
                    'message'    => 'Payout initiated successfully',
                ];
            }

            return [
                'success' => false,
                'message' => $responseData['message'] ?? 'Payout failed',
            ];

        } catch (\Exception $e) {
            \Log::error('Korapay Payout Exception', ['message' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'An error occurred: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Map bank name to Korapay bank code
     * Tries to fetch from API first, falls back to hardcoded list
     */
    protected function getBankCode($bankName)
    {
        // Try to get bank code from API
        try {
            $banksResponse = $this->getBanks();
            
            if (isset($banksResponse['status']) && $banksResponse['status'] === true && isset($banksResponse['data'])) {
                foreach ($banksResponse['data'] as $bank) {
                    if (strcasecmp($bank['name'], $bankName) === 0) {
                        return $bank['code'];
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::warning('Failed to fetch bank code from API, using fallback: ' . $e->getMessage());
        }

        // Fallback to hardcoded bank codes
        $bankCodes = [
            'Access Bank' => '044',
            'GTBank' => '058',
            'Guaranty Trust Bank' => '058',
            'First Bank' => '011',
            'First Bank of Nigeria' => '011',
            'UBA' => '033',
            'United Bank for Africa' => '033',
            'Zenith Bank' => '057',
            'Fidelity Bank' => '070',
            'Union Bank' => '032',
            'Union Bank of Nigeria' => '032',
            'Stanbic IBTC' => '221',
            'Stanbic IBTC Bank' => '221',
            'Sterling Bank' => '232',
            'Polaris Bank' => '076',
            'Wema Bank' => '035',
            'Keystone Bank' => '082',
            'FCMB' => '214',
            'First City Monument Bank' => '214',
            'Ecobank' => '050',
            'Ecobank Nigeria' => '050',
            'Heritage Bank' => '030',
            'Jaiz Bank' => '301',
            'Providus Bank' => '101',
            'Kuda Bank' => '50211',
            'Kuda' => '50211',
            'OPay' => '100004',
            'Opay Digital Services Limited' => '100004',
            'PalmPay' => '100033',
            'Moniepoint' => '50515',
            'Moniepoint Microfinance Bank' => '50515',
            'VFD Microfinance Bank' => '566',
            'Globus Bank' => '00103',
            'Parallex Bank' => '526',
            'Premium Trust Bank' => '105',
            'Titan Trust Bank' => '102',
            'SunTrust Bank' => '100',
        ];

        // Try exact match first
        if (isset($bankCodes[$bankName])) {
            return $bankCodes[$bankName];
        }

        // Try case-insensitive match
        foreach ($bankCodes as $name => $code) {
            if (strcasecmp($name, $bankName) === 0) {
                return $code;
            }
        }

        \Log::warning("Bank code not found for: {$bankName}");
        return '000'; // Default/unknown bank code
    }

    /**
     * Get list of banks from Korapay
     */
public function getBanks($countryCode = 'NG')
{
    try {
        $url = $this->baseUrl . '/api/v1/misc/banks?countryCode=' . $countryCode;
        
        // \Log::info('Korapay Get Banks Request', [
        //     'url' => $url,
        //     'country_code' => $countryCode,
        //     'using_key' => 'public_key'
        // ]);
        
        // Use PUBLIC KEY instead of SECRET KEY
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->publicKey,  // Changed from secretKey to publicKey
            'Content-Type' => 'application/json',
        ])->get($url);
        
        // \Log::info('Korapay Get Banks Response', [
        //     'status_code' => $response->status(),
        //     'body' => $response->json(),
        //     'country_code' => $countryCode
        // ]);
        
        if ($response->successful()) {
            return $response->json();
        }
        
        // \Log::warning('Korapay Get Banks Failed', [
        //     'status_code' => $response->status(),
        //     'body' => $response->body(),
        //     'country_code' => $countryCode
        // ]);
        
        return [];
    } catch (\Exception $e) {
        \Log::error('Failed to fetch banks: ' . $e->getMessage(), [
            'country_code' => $countryCode,
            'exception' => $e->getTraceAsString()
        ]);
        return [];
    }
}
}