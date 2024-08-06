<?php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;

if (ini_get('date.timezone') === '') {
    date_default_timezone_set('Asia/Jakarta');
} else {
    date_default_timezone_set(ini_get('date.timezone'));
}

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} elseif (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    die('Autoloader not found.');
}

/**
 * Midtrans FOSSBilling Integration.
 *
 * @property mixed $apiId
 * @author github.com/FZFR | https://fazza.fr
 */
class Payment_Adapter_Midtrans implements \FOSSBilling\InjectionAwareInterface
{
    protected ?Pimple\Container $di = null;
    private array $config = [];
    private Logger $logger;

    public function __construct($config)
    {
        $this->config = $config;
        $this->initLogger();
    }

    private function initLogger(): void
    {
        $this->logger = new Logger('Midtrans');
        $logDir = __DIR__ . '/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        $this->logger->pushHandler(new RotatingFileHandler($logDir . '/midtrans.log', 0, Logger::DEBUG));
    }

    public function setDi(Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?Pimple\Container
    {
        return $this->di;
    }

    public static function getConfig(): array
    {
        return [
            'supports_one_time_payments' => true,
            'description' => 'Pay with Midtrans (Credit Card, Bank Transfer, E-Wallet, etc)',
            'logo' => [
                'logo' => 'Midtrans.png',
                'height' => '60px',
                'width' => '125px',
            ],
            'form' => [
                'merchant_id' => ['text', ['label' => 'Merchant ID']],
                'client_key' => ['text', ['label' => 'Client Key']],
                'server_key' => ['password', ['label' => 'Server Key']],
                'sandbox_merchant_id' => ['text', ['label' => 'Sandbox Merchant ID']],
                'sandbox_client_key' => ['text', ['label' => 'Sandbox Client Key']],
                'sandbox_server_key' => ['password', ['label' => 'Sandbox Server Key']],
                'use_sandbox' => ['radio', [
                    'label' => 'Use Sandbox',
                    'multiOptions' => ['1' => 'Yes', '0' => 'No'],
                ]],
                'payment_mode' => ['select', [
                    'label' => 'Payment Mode',
                    'multiOptions' => [
                        'popup' => 'Popup',
                        'embedded' => 'Embedded',
                    ],
                ]],
                'default_country_code' => ['text', [
                    'label' => 'Default Country Code (ISO 3166-1 alpha-3)',
                    'description' => 'e.g., IDN for Indonesia, USA for United States',
                    'value' => 'IDN',
                ]],
            ],
        ];
    }

    public function getHtml($api_admin, $invoice_id, $subscription)
    {
        try {
            $invoice = $this->di['db']->getExistingModelById('Invoice', $invoice_id, 'Invoice not found');
            
            $snapToken = $this->getSnapToken($invoice);
            
            if ($this->config['payment_mode'] == 'popup') {
                return $this->getPopupHtml($snapToken, $invoice->id);
            } else {
                return $this->getEmbeddedHtml($snapToken, $invoice->id);
            }
        } catch (Exception $e) {
            $this->logger->error('Error in getHtml: ' . $e->getMessage());
            return 'Error: ' . $e->getMessage();
        }
    }

    private function getSnapToken($invoice)
    {
        $maxAttempts = 3;
        $attempt = 0;
    
        while ($attempt < $maxAttempts) {
            try {
                $storedToken = $this->getStoredSnapToken($invoice->id);
                if ($storedToken) {
                    return $storedToken['token'];
                }
    
                $invoiceService = $this->di['mod_service']('Invoice');
                $buyer = $this->di['db']->getExistingModelById('Client', $invoice->client_id, 'Client not found');
    
                $uniqueOrderId = $invoice->id . '-' . time() . '-' . $attempt;
    
                $items = $this->di['db']->find('InvoiceItem', 'invoice_id = ?', [$invoice->id]);
    
                $itemDetails = [];
                $totalAmount = 0;
                foreach ($items as $item) {
                    $itemAmount = (int)round($item->price * $item->quantity);
                    $itemDetails[] = [
                        'id' => $item->id,
                        'price' => (int)round($item->price),
                        'quantity' => (int)$item->quantity,
                        'name' => substr($item->title, 0, 50),
                    ];
                    $totalAmount += $itemAmount;
                }
    
                $tax = (int)round($invoiceService->getTax($invoice));
                if ($tax > 0) {
                    $itemDetails[] = [
                        'id' => 'TAX',
                        'price' => $tax,
                        'quantity' => 1,
                        'name' => 'Tax',
                    ];
                    $totalAmount += $tax;
                }
    
                $invoiceTotal = (int)round($invoiceService->getTotalWithTax($invoice));
                if ($totalAmount != $invoiceTotal) {
                    $adjustment = $invoiceTotal - $totalAmount;
                    if ($adjustment != 0) {
                        $itemDetails[] = [
                            'id' => 'ADJUSTMENT',
                            'price' => $adjustment,
                            'quantity' => 1,
                            'name' => 'Adjustment',
                        ];
                    }
                    $totalAmount = $invoiceTotal;
                }
    

                $phoneInfo = $this->processPhoneNumber($buyer->phone, $buyer->country);
    
                $shippingAddress = [
                    'first_name' => $buyer->first_name,
                    'last_name' => $buyer->last_name,
                    'email' => $buyer->email,
                    'phone' => $phoneInfo['full_number'],
                    'address' => $buyer->address ?? '',
                    'city' => $buyer->city ?? '',
                    'postal_code' => $buyer->postcode ?? '',
                    'country_code' => $this->config['default_country_code'],
                    'state' => $buyer->state ?? '',
                ];
    
                $shippingAddress = array_filter($shippingAddress);

                $billingAddress = [
                    'first_name' => $buyer->first_name,
                    'last_name' => $buyer->last_name,
                    'email' => $buyer->email,
                    'phone' => $phoneInfo['full_number'],
                    'address' => $buyer->address ?? '',
                    'city' => $buyer->city ?? '',
                    'postal_code' => $buyer->postcode ?? '',
                    'country_code' => $this->config['default_country_code'],
                    'state' => $buyer->state ?? '',
                ];
    
                $billingAddress = array_filter($billingAddress);
    
                $params = [
                    'transaction_details' => [
                        'order_id' => $uniqueOrderId,
                        'gross_amount' => $totalAmount,
                    ],
                    'customer_details' => [
                        'first_name' => $buyer->first_name,
                        'last_name' => $buyer->last_name,
                        'email' => $buyer->email,
                        'phone' => $phoneInfo['full_number'],
                        'billing_address' => $billingAddress,
                        'shipping_address' => $shippingAddress,
                    ],
                    'item_details' => $itemDetails,
                    'callbacks' => [
                        'finish' => $this->di['url']->link('invoice/' . $invoice->hash, ['restore_session' => session_id()])
                    ]
                ];    
    
                $url = $this->config['use_sandbox'] ? 'https://app.sandbox.midtrans.com/snap/v1/transactions' : 'https://app.midtrans.com/snap/v1/transactions';
    
                $this->logger->info('Requesting Snap token', ['url' => $url, 'params' => $params]);
    
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Authorization: Basic ' . base64_encode($this->config['use_sandbox'] ? $this->config['sandbox_server_key'] : $this->config['server_key']),
                ]);
    
                $result = curl_exec($ch);
                $error = curl_error($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
    
                if ($error) {
                    $this->logger->error('cURL Error', ['error' => $error]);
                    throw new \Exception('Failed to connect to Midtrans: ' . $error);
                }
    
                $this->logger->info('Snap token response', ['httpCode' => $httpCode, 'result' => $result]);
    
                $result = json_decode($result, true);
    
                if (!isset($result['token'])) {
                    $this->logger->error('Failed to get Snap token', ['result' => $result]);
                    throw new \Exception('Failed to get Snap token: ' . json_encode($result));
                }
    
                $this->storeSnapToken($invoice->id, $result['token'], $uniqueOrderId);
    
                return $result['token'];
            } catch (\Exception $e) {
                if (strpos($e->getMessage(), 'order_id has already been taken') !== false) {
                    $attempt++;
                    continue;
                }
                throw $e;
            }
        }
    
        throw new \Exception('Failed to get unique Snap token after ' . $maxAttempts . ' attempts');
    }

    public function processTransaction($api_admin, $id, $data, $gateway_id)
    {
        $this->logger->info('Processing transaction', ['id' => $id, 'data' => $data]);
    
        try {
            $notificationData = json_decode($data['http_raw_post_data'], true);
            if (!$notificationData) {
                throw new \Exception('Invalid notification data');
            }
    
            $orderIdParts = explode('-', $notificationData['order_id']);
            $invoiceId = $orderIdParts[0];
    
            $this->logger->info('Extracted invoice ID', ['invoiceId' => $invoiceId, 'fullOrderId' => $notificationData['order_id']]);
    
            $invoice = $this->di['db']->getExistingModelById('Invoice', $invoiceId, 'Invoice not found');
    
            $serverKey = $this->config['use_sandbox'] ? $this->config['sandbox_server_key'] : $this->config['server_key'];
            $signature = hash('sha512', $notificationData['order_id'].$notificationData['status_code'].$notificationData['gross_amount'].$serverKey);
            if ($signature !== $notificationData['signature_key']) {
                $this->logger->error('Invalid signature', ['received' => $notificationData['signature_key'], 'calculated' => $signature]);
                return false;
            }
    
            $tx = $this->di['db']->load('Transaction', $invoiceId);
            if(!$tx) {
                $tx = $this->di['db']->dispense('Transaction');
                $tx->invoice_id = $invoiceId;
                $tx->gateway_id = $gateway_id;
            }
    
            $tx->txn_status = $notificationData['transaction_status'];
            $tx->txn_id = $notificationData['transaction_id'];
            $tx->amount = $notificationData['gross_amount'];
            $tx->currency = $notificationData['currency'];
            $tx->payment_type = $notificationData['payment_type'];
    
            $invoiceService = $this->di['mod_service']('Invoice');
            $clientService = $this->di['mod_service']('Client');
            $client = $this->di['db']->getExistingModelById('Client', $invoice->client_id, 'Client not found');
    
            if ($notificationData['transaction_status'] == 'capture' || $notificationData['transaction_status'] == 'settlement') {
                if($invoice->status != \Model_Invoice::STATUS_PAID) {
                    $amount = $notificationData['gross_amount'];
                    
                    $paymentMethod = $this->getPaymentMethodDescription($notificationData);
                    $description = sprintf('Payment for invoice #%s via %s', $invoice->id, $paymentMethod);
                    $clientService->addFunds($client, $amount, $description, [
                        'invoice_id' => $invoice->id,
                        'payment_method' => $paymentMethod,
                        'transaction_id' => $notificationData['transaction_id'],
                        'payment_type' => $notificationData['payment_type']
                    ]);
                    $this->logger->info('Funds added to client balance', [
                        'clientId' => $client->id, 
                        'amount' => $amount, 
                        'paymentMethod' => $paymentMethod
                    ]);
    

                    $invoiceService->markAsPaid($invoice);
                    $this->logger->info('Invoice marked as paid', ['id' => $invoiceId]);
    
                    $tx->status = 'complete';
                }
            } else if ($notificationData['transaction_status'] == 'pending') {
                $tx->status = 'pending';
            } else if (in_array($notificationData['transaction_status'], ['deny', 'expire', 'cancel'])) {
                $tx->status = 'failed';
            }
    
            $this->di['db']->store($tx);
    
            $this->logger->info('Transaction processed', ['id' => $invoiceId, 'status' => $tx->status]);
    
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Error processing transaction: ' . $e->getMessage(), ['exception' => $e, 'trace' => $e->getTraceAsString()]);
            return false;
        }
    }
    
    private function getPaymentMethodDescription($notificationData)
    {
        $paymentType = $notificationData['payment_type'];
        $paymentMethod = ucfirst(str_replace('_', ' ', $paymentType));
    
        switch ($paymentType) {
            case 'credit_card':
                $bank = $notificationData['bank'] ?? '';
                $cardType = $notificationData['card_type'] ?? '';
                $maskedCard = $notificationData['masked_card'] ?? '';
                $paymentMethod .= " ($bank $cardType $maskedCard)";
                break;
            case 'bank_transfer':
                $bank = $notificationData['va_numbers'][0]['bank'] ?? $notificationData['bank'] ?? '';
                $vaNumber = $notificationData['va_numbers'][0]['va_number'] ?? $notificationData['permata_va_number'] ?? '';
                $paymentMethod = "Bank Transfer $bank ($vaNumber)";
                break;
            case 'gopay':
                $paymentMethod = "GoPay";
                break;
            case 'shopeepay':
                $paymentMethod = "ShopeePay";
                break;
            case 'cstore':
                $store = $notificationData['store'] ?? '';
                $paymentMethod = "$store Payment";
                break;
            case 'akulaku':
                $paymentMethod = "Akulaku";
                break;
            case 'bca_klikpay':
                $paymentMethod = "BCA KlikPay";
                break;
            case 'bca_klikbca':
                $paymentMethod = "Klik BCA";
                break;
            case 'mandiri_clickpay':
                $paymentMethod = "Mandiri Clickpay";
                break;
            case 'echannel':
                $paymentMethod = "Mandiri Bill Payment";
                break;
            case 'cimb_clicks':
                $paymentMethod = "CIMB Clicks";
                break;
            case 'danamon_online':
                $paymentMethod = "Danamon Online Banking";
                break;
            case 'qris':
                $acquirer = $notificationData['acquirer'] ?? '';
                $paymentMethod = "QRIS ($acquirer)";
                break;
            case 'bri_epay':
                $paymentMethod = "BRI e-Pay";
                break;
            case 'indomaret':
                $paymentMethod = "Indomaret";
                break;
            case 'alfamart':
                $paymentMethod = "Alfamart";
                break;
            case 'ovo':
                $paymentMethod = "OVO";
                break;
            case 'dana':
                $paymentMethod = "DANA";
                break;
            case 'linkaja':
                $paymentMethod = "LinkAja";
                break;
            default:                
                $paymentMethod = ucfirst(str_replace('_', ' ', $paymentType));
                break;
        }
    
        return $paymentMethod;
    }
    
    private function processPhoneNumber($phone, $countryCode = null)
    {
        $phone = preg_replace('/\D/', '', $phone);

        if ($countryCode === null) {
            $countryCode = $this->config['default_country_code'] ?? 'IDN';
        }

        $numericCountryCode = $this->getNumericCountryCodeFromFOSSBilling($countryCode);

        if ($numericCountryCode && substr($phone, 0, strlen($numericCountryCode)) !== $numericCountryCode) {
            if (substr($phone, 0, 1) === '0') {
                $phone = $numericCountryCode . substr($phone, 1);
            } else {
                $phone = $numericCountryCode . $phone;
            }
        }

        return [
            'country_code' => $numericCountryCode,
            'number' => $numericCountryCode ? substr($phone, strlen($numericCountryCode)) : $phone,
            'full_number' => '+' . $phone
        ];
    }

    private function getNumericCountryCodeFromFOSSBilling($countryCode)
    {
        try {
            $countryService = $this->di['mod_service']('Country');
            $country = $countryService->getCountryByCode($countryCode);
            return $country['phone_code'] ?? null;
        } catch (\Exception $e) {
            $this->logger->warning('Failed to get numeric country code from FOSSBilling', [
                'countryCode' => $countryCode,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    private function getPopupHtml($snapToken, $invoiceId)
    {
        $clientKey = $this->config['use_sandbox'] ? $this->config['sandbox_client_key'] : $this->config['client_key'];
        $snapJsUrl = $this->config['use_sandbox'] ? 'https://app.sandbox.midtrans.com/snap/snap.js' : 'https://app.midtrans.com/snap/snap.js';
        $invoice = $this->di['db']->getExistingModelById('Invoice', $invoiceId, 'Invoice not found');
        $redirectUrl = $this->di['url']->link('invoice/' . $invoice->hash, ['restore_session' => session_id()]);
    
        return "
        <script type='text/javascript' src='{$snapJsUrl}' data-client-key='{$clientKey}'></script>
        <button id='pay-button'>Pay Now</button>
        <script type='text/javascript'>
            var payButton = document.getElementById('pay-button');
            payButton.addEventListener('click', function () {
                snap.pay('{$snapToken}', {
                    onSuccess: function(result){
                        window.location.href = '{$redirectUrl}';
                    },
                    onPending: function(result){
                        window.location.href = '{$redirectUrl}';
                    },
                    onError: function(result){
                        window.location.href = '{$redirectUrl}';
                    },
                    onClose: function(){
                        window.location.href = '{$redirectUrl}';
                    }
                });
            });
        </script>";
    }
    
    private function getEmbeddedHtml($snapToken, $invoiceId)
    {
        $clientKey = $this->config['use_sandbox'] ? $this->config['sandbox_client_key'] : $this->config['client_key'];
        $snapJsUrl = $this->config['use_sandbox'] ? 'https://app.sandbox.midtrans.com/snap/snap.js' : 'https://app.midtrans.com/snap/snap.js';
        $invoice = $this->di['db']->getExistingModelById('Invoice', $invoiceId, 'Invoice not found');
        $redirectUrl = $this->di['url']->link('invoice/' . $invoice->hash, ['restore_session' => session_id()]);
    
        return "
        <div id='snap-container' style='width: 100%; height: 600px;'></div>
        <script type='text/javascript' src='{$snapJsUrl}' data-client-key='{$clientKey}'></script>
        <script type='text/javascript'>
            window.snap.embed('{$snapToken}', {
                embedId: 'snap-container',
                onSuccess: function(result){
                    console.log('payment success!', result);
                    window.location.href = '{$redirectUrl}';
                },
                onPending: function(result){
                    console.log('waiting your payment!', result);
                    window.location.href = '{$redirectUrl}';
                },
                onError: function(result){
                    console.log('payment failed!', result);
                    window.location.href = '{$redirectUrl}';
                },
                onClose: function(){
                    console.log('you closed the popup without finishing the payment');
                    window.location.href = '{$redirectUrl}';
                }
            });
        </script>";
    }

    private function verifyTransactionStatus($orderId)
    {
        $url = $this->config['use_sandbox'] 
            ? 'https://api.sandbox.midtrans.com/v2/'
            : 'https://api.midtrans.com/v2/';
        $url .= $orderId . '/status';
    
        $serverKey = $this->config['use_sandbox'] ? $this->config['sandbox_server_key'] : $this->config['server_key'];
    
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode($serverKey . ':')
        ]);
    
        $result = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
    
        if ($error) {
            $this->logger->error('Error verifying transaction status', ['error' => $error]);
            throw new \Exception('Failed to verify transaction status: ' . $error);
        }
    
        $statusData = json_decode($result, true);
    
        if (!isset($statusData['transaction_status'])) {
            $this->logger->error('Invalid status response', ['response' => $result]);
            throw new \Exception('Invalid status response from Midtrans');
        }
    
        return $statusData;
    }

    public function isIpnValid($data, $ipn)
    {
        $this->logger->info('Validating IPN', ['data' => $data, 'ipn' => $ipn]);

        $notificationData = json_decode($data, true);
        if (!$notificationData) {
            $this->logger->error('Invalid JSON data in IPN');
            return false;
        }
    
        $serverKey = $this->config['use_sandbox'] ? $this->config['sandbox_server_key'] : $this->config['server_key'];
        $signature = hash('sha512', $notificationData['order_id'].$notificationData['status_code'].$notificationData['gross_amount'].$serverKey);
    
        if ($signature !== $notificationData['signature_key']) {
            $this->logger->error('Invalid signature', ['received' => $notificationData['signature_key'], 'calculated' => $signature]);
            return false;
        }
    
        return true;
    }

    public function recurrentPayment($api_admin, $id, $data, $gateway_id)
    {
        throw new Payment_Exception('Midtrans doesn\'t support recurrent payments');
    }

    private function storeSnapToken($invoiceId, $token, $orderId)
    {
        $storageDir = __DIR__ . '/temp_storage';
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0755, true);
        }
        $filename = $storageDir . '/midtrans_' . $invoiceId . '.json';
        $data = json_encode([
            'token' => $token,
            'order_id' => $orderId,
            'created_at' => time()
        ]);
        file_put_contents($filename, $data);
        $this->logger->info('Storing Snap token in file', ['invoiceId' => $invoiceId, 'filename' => $filename]);
    }

    private function getStoredSnapToken($invoiceId)
    {
        $filename = __DIR__ . '/temp_storage/midtrans_' . $invoiceId . '.json';
        if (file_exists($filename)) {
            $data = json_decode(file_get_contents($filename), true);
            if (time() - $data['created_at'] < 3600) {
                return $data;
            }
            unlink($filename);
        }
        return null;
    }
}