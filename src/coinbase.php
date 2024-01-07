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
use hmcsw\objects\user\User;
use hmcsw\payment\ModulePaymentMethod;
use hmcsw\payment\PaymentEntity;
use hmcsw\payment\PaymentMethodMode;
use hmcsw\payment\PaymentRetour;
use hmcsw\payment\PaymentType;
use hmcsw\service\api\ApiService;
use hmcsw\service\authorization\log\LogType;
use hmcsw\service\authorization\LogService;
use hmcsw\service\config\ConfigService;
use hmcsw\service\general\BalanceService;
use hmcsw\service\module\ModulePaymentRepository;
use hmcsw\service\templates\LanguageService;

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

  public function getMessages(string $lang): array|bool
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
   * @throws NotFoundException
   */
  private function overpayed(PaymentEntity $payment, array $data): array
  {
    $checkout = $payment->checkoutPayment(true);

    $amount = 0;

    foreach ($data['event']['data']['payments'] as $payment1) {
      $amount += ($payment1['value']['local']['amount'] ?? 0) * 100;
    }

    $payment->overPaid($amount, true);
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
        $payment = PaymentEntity::getPaymentByExternal($id);
      } catch (NotFoundException $e) {
        return ["success" => true,
          "response" => "Hook success, but nothing to do: " . $e->getMessage(),
          "status_code" => 200];
      }

      if ($event->type == "charge:confirmed") {
        $payment->approvePayment();
        return $payment->checkoutPayment(true);
      } elseif ($event->type == "charge:resolved") {
        $payment->approvePayment();
        return $this->overpayed($payment, $data);
      } elseif ($event->type == "charge:failed") {
        $payment->cancelPayment();
      } else {
        return ["success" => true,
          "response" => "Hook success, but nothing to do: " . $event->type,
          "status_code" => 200];
      }
      return ApiService::getSuccessMessage();
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

  public function checkoutPayment(PaymentEntity $payment): bool
  {
    try {
      $this->getCoinbase();
    } catch (ApiErrorException $e) {
      throw new PaymentException($e->getMessage(), $e->getCode());
    }

    if ($payment->getExternalId() == "00000000-0000-0000-0000-000000000000") {
      return true;
    }

    try {
      $retrievedCharge = Charge::retrieve($payment->getExternalId());
      if ($retrievedCharge->status == "CONFIRMED") {
        return true;
      } elseif ($retrievedCharge->status == "RESOLVED") {
        return true;
      } else {
        throw new PaymentException("Payment not confirmed", 400);
      }
    } catch (Exception $e) {
      throw new PaymentException($e->getMessage(), $e->getCode());
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
      throw new ApiErrorException($e->getMessage(), $e->getCode(), [], $e);
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

  public function createOneTimePayment(PaymentEntity $payment, string $returnURL, string $cancelURL): array
  {
    try {
      $this->getCoinbase();
    } catch (ApiErrorException $e) {
      throw new PaymentException($e->getMessage(), $e->getCode());
    }

    try {
      $currency = "EUR";

      $chargeData = ['name' => ConfigService::getConfigValue("name"),
        "description" => "Checkout",
        'local_price' => ['amount' => BalanceService::creditsToEuro($payment->getAmount(), "."), 'currency' => $currency],
        'pricing_type' => 'fixed_price',
        "redirect_url" => $returnURL,
        "cancel_url" => $cancelURL];
      $charge = Charge::create($chargeData);

      $external_id = $charge->id;
      $links = $charge->hosted_url;

      return ["external_id" => $external_id, "link" => $links];
    } catch (Exception $e) {
      throw new PaymentException($e->getMessage(), $e->getCode());
    }
  }

  public function getConfig(): array
  {
    return $this->config;
  }

  public function refundPayment(PaymentRetour $retour): array
  {
    throw new PaymentException("Refund not supported", 0);
  }

  public function getProperties(): array
  {
    return [];
  }

  public function updateCustomer(User $user, string $external_id): array
  {
    throw new PaymentException("Update customer not supported", 0);
  }

  public function createCustomer(User $user): array
  {
    throw new PaymentException("Create customer not supported", 0);
  }

  public function deleteCustomer(User $user, string $external_id): bool
  {
    throw new PaymentException("Delete customer not supported", 0);
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
            LanguageService::getMessage('site.cart.method.' . $method), "coinbase", $method);
        }
      }
    }

    throw new NotFoundException("method not found");
  }
}
