<?php

namespace hmcswModule\coinbase\src;

use CoinbaseCommerce\ApiClient;
use CoinbaseCommerce\Exceptions\ApiException;
use CoinbaseCommerce\Resources\Charge;
use CoinbaseCommerce\Webhook;
use Exception;
use hmcsw\exception\ApiErrorException;
use hmcsw\exception\NotFoundException;
use hmcsw\exception\PaymentException;
use hmcsw\exception\ValidationException;
use hmcsw\infrastructure\format\FormatUtil;
use hmcsw\infrastructure\logs\LogService;
use hmcsw\infrastructure\logs\LogType;
use hmcsw\infrastructure\module\ModulePaymentRepository;
use hmcsw\infrastructure\module\payment\ModuleOneTimeCreateResponse;
use hmcsw\payment\domain\Payment;
use hmcsw\payment\domain\PaymentMethodMode;
use hmcsw\payment\domain\PaymentRefund;
use hmcsw\payment\domain\PaymentType;
use hmcsw\payment\ModulePaymentMethod;
use hmcsw\payment\service\PaymentService;
use hmcsw\service\config\ConfigService;
use hmcsw\user\domain\User;

class coinbase implements ModulePaymentRepository
{
  public string $webhookUrl;
  private array $config;
  private string $client_secret;
  private string $hook_secret;

  public function __construct()
  {
    $this->config = json_decode(file_get_contents(__DIR__ . '/../config/config.json'), true);
  }

  public function startModule(): bool
  {
    if ($this->config['enabled']) {
      return true;
    } else {
      return false;
    }
  }

  public function getMessages(string $lang): array|false
  {
    if (!file_exists(__DIR__ . '/../messages/' . $lang . '.json')) {
      return false;
    }

    return json_decode(file_get_contents(__DIR__ . '/../messages/' . $lang . '.json'), true);
  }

  public function getModuleInfo(): array
  {
    return json_decode(file_get_contents(__DIR__ . '/../module.json'), true);
  }

  public function initial(): void
  {
    $this->client_secret = $this->config['secret']['client'];
    $this->hook_secret = $this->config['secret']['hook'];

    $this->webhookUrl = ConfigService::getApiUrl() . '/hooks/payment/mollie?key=' . $this->hook_secret;

  }

  /**
   * @throws ValidationException
   * @throws PaymentException
   */
  private function overpayed(Payment $payment, array $data): array
  {
    $checkout = PaymentService::checkoutPayment($payment, true);

    $amount = 0;

    foreach ($data['event']['data']['payments'] as $payment1) {
      $amount += ($payment1['value']['local']['amount'] ?? 0) * 100;
    }

    if($amount == 0){
      throw new ValidationException("Invalid amount");
    } elseif($amount < $payment->getAmount()){
      PaymentService::underPaid($payment, $amount, true);
    } elseif($amount == $payment->getAmount()){
      return $checkout;
    } elseif($amount > $payment->getAmount()){
      PaymentService::overPaid($payment, $amount, true);
    }
    return $checkout;
  }

  public function hook(): array
  {
    $headerName = 'X-Cc-Webhook-Signature';
    $headers = getallheaders();
    $signatureHeader = $headers[$headerName] ?? "";
    $payload = trim(file_get_contents('php://input'));

    $data = json_decode(file_get_contents('php://input'), true);
    if ($data === null) {
      throw new ValidationException("Invalid payload");
    }

    LogService::writeLog($data, "coinbase_hook_test_start", LogType::DEBUG);
    try {
      $event = Webhook::buildEvent($payload, $signatureHeader, $this->hook_secret);
      http_response_code(200);

      $id = $data['event']['data']['id'];
      try {
        $payment = PaymentService::getPaymentByExternalId($id);
      } catch (NotFoundException $e) {
        return ["success" => true,
          "response" => "Hook success, but nothing to do: " . $e->getMessage(),
          "status_code" => 200];
      }

      if ($event->type == "charge:confirmed") {
        PaymentService::approvePayment($payment);
        return PaymentService::checkoutPayment($payment, true);
      } elseif ($event->type == "charge:resolved") {
        PaymentService::approvePayment($payment);
        return $this->overpayed($payment, $data);
      } elseif ($event->type == "charge:failed") {
        PaymentService::cancelPayment($payment);
      } elseif($event->type == "charge:pending") {
        PaymentService::paymentPendingButApproved($payment);
      } else {
        return ["success" => true,
          "response" => "Hook success, but nothing to do: " . $event->type,
          "status_code" => 200];
      }
      return [];
    } catch (Exception $e) {
      if ($e->getMessage() == "payment already approved") {
        return ["success" => true,
          "response" => "Hook success, but nothing to do: " . $e->getMessage(),
          "status_code" => 200];
      }
      http_response_code(400);
      return ["success" => false,
        "response" => ["error_code" => 400,
          "error_message" => "Hook failed. Invalid signature.",
          "error_response" => $e->getMessage()],
        "status_code" => 400];
    }

  }

  public function checkoutPayment(Payment $payment): bool
  {
    try {
      $this->getCoinbase();
    } catch (ApiErrorException $e) {
      throw new PaymentException($e->getMessage(), [], $e);
    }

    if ($payment->getExternalIdentifier() == "00000000-0000-0000-0000-000000000000") {
      return true;
    }

    try {
      $retrievedCharge = Charge::retrieve($payment->getExternalIdentifier());
      if ($retrievedCharge->status == "CONFIRMED") {
        return true;
      } elseif ($retrievedCharge->status == "RESOLVED") {
        return true;
      } else {
        throw new PaymentException("Payment not confirmed");
      }
    } catch (Exception $e) {
      throw new PaymentException($e->getMessage(), [], $e);
    }

  }

  /**
   * @throws ApiErrorException
   */
  public function getCoinbase(): ApiClient
  {
    try {
      $apiClientObj = ApiClient::init($this->client_secret);
      $apiClientObj->setTimeout(3);
      return $apiClientObj;
    } catch (ApiException $e) {
      throw new ApiErrorException($e->getMessage(), [], $e);
    }
  }

  public function getAvailablePaymentMethods(int $amount, PaymentType $type = PaymentType::oneTime): array
  {
    $methods = [];
    if ($type == PaymentType::oneTime) {
      foreach ($this->config['methods']['oneTime'] as $method => $setting) {
        if ($setting['enabled']) {
          if ($setting['minimum'] <= $amount and $setting['maximum'] >= $amount) {
            try {
              $methods[$method] = $this->getPaymentMethod(PaymentType::oneTime, $method);
            } catch (NotFoundException) {
            }
          }
        }
      }
    }

    return $methods;

  }

  public function createOneTimePayment(Payment $payment, string $returnURL, string $cancelURL): ModuleOneTimeCreateResponse
  {
    try {
      $this->getCoinbase();
    } catch (ApiErrorException $e) {
      throw new PaymentException($e->getMessage(), [], $e);
    }

    try {
      $amount = FormatUtil::formatCurrencyWithConfig($payment->getAmount(), ".");

      $chargeData = ['name' => ConfigService::getConfigValue("name"),
        "description" => "Checkout",
        'local_price' => ['amount' => $amount->getFormat(), 'currency' => "EUR"],
        'pricing_type' => 'fixed_price',
        "redirect_url" => $returnURL,
        "cancel_url" => $cancelURL];
      $charge = Charge::create($chargeData);

      $external_id = $charge['id'];
      $link = $charge['hosted_url'];

      return new ModuleOneTimeCreateResponse($external_id, $link);
    } catch (Exception $e) {
      throw new PaymentException($e->getMessage(), [], $e);
    }
  }

  public function getConfig(): array
  {
    return $this->config;
  }

  public function refundPayment(PaymentRefund $refund): array
  {
    throw new PaymentException("Refund not supported");
  }

  public function getProperties(): array
  {
    return [];
  }

  public function updateCustomer(User $user, string $external_id): array
  {
    throw new PaymentException("Update customer not supported");
  }

  public function createCustomer(User $user): array
  {
    throw new PaymentException("Create customer not supported");
  }

  public function deleteCustomer(User $user, string $external_id): bool
  {
    throw new PaymentException("Delete customer not supported");
  }

  public function getPaymentMethod(PaymentType $type, string $identifier): ModulePaymentMethod
  {
    if ($type == PaymentType::oneTime) {
      foreach ($this->config['methods']['oneTime'] as $method => $setting) {
        if ($method == $identifier) {
          return new ModulePaymentMethod(PaymentMethodMode::method,
            PaymentType::oneTime,
            $setting['minimum'],
            $setting['maximum'],
            $setting['fee'],
            isset($setting['displayName']) ? FormatUtil::getMessage($setting['displayName']) : FormatUtil::getMessage('module.coinbase.method.'.$method),
            $this->getIdentifier(),
            $method,
          );
        }
      }
    }

    throw new NotFoundException("method not found");
  }

  public function getName(): string
  {
    return $this->getModuleInfo()['name'];
  }

  public function getIdentifier(): string
  {
    return $this->getModuleInfo()['identifier'];
  }
}
