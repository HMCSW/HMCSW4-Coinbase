<?php

namespace hmcswModule\coinbase\src;

use Exception;
use hmcsw\utils\unitUtil;
use hmcsw\payment\payment;
use hmcsw\objects\user\User;
use CoinbaseCommerce\Webhook;
use CoinbaseCommerce\ApiClient;
use hmcsw\payment\paymentEvents;
use CoinbaseCommerce\Resources\Charge;
use hmcsw\service\config\ConfigService;
use hmcsw\service\general\BalanceService;
use hmcsw\service\authorization\logService;
use hmcsw\service\templates\LanguageService;
use CoinbaseCommerce\Exceptions\ApiException;
use hmcsw\service\module\modulePaymentRepository;

class coinbase implements modulePaymentRepository
{
  public string $webhookUrl;
  private array $config;
  private string $client_secret;
  private string $hook_secret;

  public function __construct ()
  {
    $this->config = json_decode(file_get_contents(__DIR__ . '/../config/config.json'), true);
  }

  public function startModule (): bool
  {
    if ($this->config['enabled']) {
      return true;
    } else {
      return false;
    }
  }

  public function getMessages (string $lang): array|bool
  {
    if (!file_exists(__DIR__ . '/../messages/' . $lang . '.json')) {
      return false;
    }

    return json_decode(file_get_contents(__DIR__ . '/../messages/' . $lang . '.json'), true);
  }

  public function getModuleInfo (): array
  {
    return json_decode(file_get_contents(__DIR__ . '/../module.json'), true);
  }

  public function initial (): void
  {
    $this->client_secret = $this->config['secret']['client'];
    $this->hook_secret = $this->config['secret']['hook'];

    $this->webhookUrl = ConfigService::getUrl("apiAll") . '/hooks/payment/mollie?key=' . $this->hook_secret;

  }

  public function hook (): array
  {
    $headerName = 'X-Cc-Webhook-Signature';
    $headers = getallheaders();
    $signatureHeader = $headers[$headerName] ?? null;
    $payload = trim(file_get_contents('php://input'));

    try {
      $event = Webhook::buildEvent($payload, $signatureHeader, $this->hook_secret);
      http_response_code(200);

      if ($event->type == "charge:confirmed") {
        payment::checkoutPayment($event->data->id, true);
        logService::createLog($event->data->id, "coinbase_hook_test");
        logService::createLog($event, "coinbase_hook_test");
        return payment::checkoutPayment($event->data->id);
      } elseif ($event->type == "charge:failed") {
        return paymentEvents::paymentCancelled($event->id);
      } else {
        return ["success" => true,
          "response" => "Hook success, but nothing to do: " . $event->type,
          "status_code" => 200];
      }
    } catch (\Exception $e) {
      http_response_code(400);
      return ["success" => false,
        "response" => ["error_code" => 400,
          "error_message" => "Hook failed. Invalid signature.",
          "error_response" => $e->getMessage()],
        "status_code" => 400];
    }

  }

  public function checkoutPayment (User $user, $external_id, $payment_id): array
  {
    if (is_array($this->getCoinbase())) return $this->getCoinbase();
    $coinbase = $this->getCoinbase();

    if ($external_id == "00000000-0000-0000-0000-000000000000") {
      return ["success" => true, "response" => ["status" => "paid"]];
    }

    try {
      $retrievedCharge = Charge::retrieve($external_id);
      return ["success" => true, "response" => ["status" => "paid"]];
    } catch (Exception $e) {
      return ["success" => false, "response" => ["error_code" => $e->getCode(), "error_message" => $e->getMessage()]];
    }

  }

  public function getCoinbase (): ApiClient|array
  {
    try {
      $apiClientObj = ApiClient::init($this->client_secret);
      $apiClientObj->setTimeout(3);
      return $apiClientObj;
    } catch (ApiException $e) {
      return ["success" => false, "response" => ["error_code" => $e->getCode(), "error_message" => $e->getMessage()]];
    }
  }

  public function getAvailablePaymentMethods ($amount, $type = "oneTime"): array
  {
    $methods = [];

    if ($type == "oneTime") {
      foreach ($this->config['methods']['oneTime'] as $method => $setting) {
        if ($setting['enabled']) {
          if ($setting['minimum'] <= $amount and $setting['maximum'] >= $amount) {
            $methods[$method] = ["method" => $method,
              "fee" => $setting['fee'],
              "name" => LanguageService::getMessage("site.cart.method." . $method)];
          }
        }
      }
    } elseif ($type == "moreTime") {
      foreach ($this->config['methods']['moreTime'] as $method => $setting) {
        if ($setting['enabled']) {
          if ($setting['minimum'] <= $amount and $setting['maximum'] >= $amount) {
            $methods[$method] = ["method" => $method,
              "fee" => $setting['fee'],
              "name" => LanguageService::getMessage("site.cart.method." . $method)];
          }
        }
      }
    }

    return $methods;

  }

  public function createOneTimePayment (User $user, $order_id, $payment_id, $method, string $returnURL): array
  {
    if (is_array($this->getCoinbase())) return $this->getCoinbase();
    $coinbase = $this->getCoinbase();

    $order = payment::getOrder($order_id);
    if (!$order['success']) return $order;
    $order = $order['response']['order'];

    $endPrize = $order['amount']['full'];

    try {
      $currency = unitUtil::getCurrency("code");
      $currency = "EUR";

      $chargeData = ['name' => ConfigService::getConfig() ['name'],
        "description" => "Checkout",
        'local_price' => ['amount' => BalanceService::creditsToEuro($endPrize, "."), 'currency' => $currency],
        'pricing_type' => 'fixed_price',
        "redirect_url" => $returnURL,
        "cancel_url" => $returnURL];
      $charge = Charge::create($chargeData);

      $external_id = $charge->id;
      $links = $charge->hosted_url;

      return ["success" => true,
        "response" => ["payment_id" => $payment_id, "external_id" => $external_id, "link" => $links]];
    } catch (Exception $e) {
      return ["success" => false, "response" => ["error_code" => $e->getCode(), "error_message" => $e]];
    }
  }

  public function getConfig (): array
  {
    return $this->config;
  }

  public function retourPayment ($external_id, $reason, $amount): array
  {
    return ["success" => false, "response" => ["error_code" => 400, "error_message" => "not available for coinbase"]];
  }

  public function addMethod (User $user, $method, $args): array
  {
    return ["success" => false];
  }

  public function removeMethod (User $user, $external_id): array
  {
    return ["success" => false];
  }

  public function getProperties (): array
  {
    return [];
  }

  public function createPayment (User $user, string $order_id, int $payment_id, string $methodExternalID): array
  {
    return ["success" => false];
  }

  public function methodReady (User $user, $external_id): array
  {
    // TODO: Implement methodReady() method.
  }

  public function formatePaymentName (string $type, array $input): string
  {
    // TODO: Implement formatePaymentName() method.
  }
}