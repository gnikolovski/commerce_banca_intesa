<?php

declare(strict_types=1);

namespace Drupal\commerce_banca_intesa;

use Drupal\commerce\MailHandlerInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use GuzzleHttp\Client;
use SimpleXMLElement;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * Defines the BancaIntesaService class.
 *
 * @package Drupal\commerce_banca_intesa
 */
class BancaIntesaService implements BancaIntesaServiceInterface {

  use StringTranslationTrait;

  /**
   * BancaIntesaService constructor.
   *
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger.
   * @param \Drupal\commerce\MailHandlerInterface $mailHandler
   *   The mail handler.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    #[Autowire(service: 'renderer')]
    protected RendererInterface $renderer,
    #[Autowire(service: 'logger.factory')]
    protected LoggerChannelFactoryInterface $loggerFactory,
    #[Autowire(service: 'commerce.mail_handler')]
    protected MailHandlerInterface $mailHandler,
    #[Autowire(service: 'entity_type.manager')]
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritDoc}
   */
  public function getRedirectUrl(array $configuration): string {
    if ($configuration['mode'] === 'live') {
      $redirect_url = $configuration['live_redirect_url'];
    }
    else {
      $redirect_url = $configuration['test_redirect_url'];
    }

    return $redirect_url;
  }

  /**
   * {@inheritDoc}
   */
  public function buildPostData(array $configuration, OrderInterface $order): array {
    $random_string = md5(microtime());
    $shop_url = Url::fromRoute('<front>', [], [
      'absolute' => TRUE,
      'https' => TRUE,
    ]);

    return [
      'currency' => '941',
      'trantype' => $configuration['auth_option'] ?? 'PreAuth',
      'okUrl' => $this->getReturnUrl($order),
      'failUrl' => $this->getCancelUrl($order),
      'amount' => $order->getTotalPrice()->getNumber(),
      'oid' => $order->id(),
      'clientid' => $this->cleanValue($configuration['merchant_id']),
      'storetype' => '3d_pay_hosting',
      'lang' => 'sr',
      'rnd' => $random_string,
      'encoding' => 'utf-8',
      'shopurl' => $shop_url->toString(),
      'hashAlgorithm' => 'ver2',
      'hash' => $this->generateHash($configuration, $order, $random_string),
    ];
  }

  /**
   * {@inheritDoc}
   */
  public function isHashValid(array $configuration, OrderInterface $order, Request $request): bool {
    $banca_intesa_hash = $request->request->get('HASH');
    $banca_intesa_hash_parameters = $request->request->get('HASHPARAMS');
    $banca_intesa_parsed_hash_parameters = explode('|', $banca_intesa_hash_parameters);

    $banca_intesa_hash_data = '';
    foreach ($banca_intesa_parsed_hash_parameters as $banca_intesa_parsed_hash_parameter) {
      $banca_intesa_parameter = $request->request->get($banca_intesa_parsed_hash_parameter);
      if ($banca_intesa_parameter == NULL) {
        $banca_intesa_parameter = '';
      }
      $banca_intesa_hash_data .= $this->cleanValue($banca_intesa_parameter) . '|';
    }
    $hash = base64_encode(hash('sha512', $banca_intesa_hash_data . $this->cleanValue($configuration['store_key']), TRUE));

    if ($banca_intesa_hash != $hash) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * {@inheritDoc}
   */
  public function buildPaymentReportTable(Request $request): array {
    $order_id = $request->request->get('oid');
    $authorization_code = $request->request->get('AuthCode');
    $payment_status = $request->request->get('Response');
    $transaction_status_code = $request->request->get('ProcReturnCode');
    $transaction_id = $request->request->get('TransId');
    $transaction_date = $request->request->get('EXTRA_TRXDATE');
    $status_code_3d = $request->request->get('mdStatus');

    return [
      ['name' => $this->t('Order ID'), 'value' => $order_id],
      ['name' => $this->t('Authorization code'), 'value' => $authorization_code],
      ['name' => $this->t('Payment status'), 'value' => $payment_status],
      ['name' => $this->t('Transaction status code'), 'value' => $transaction_status_code],
      ['name' => $this->t('Transaction ID'), 'value' => $transaction_id],
      ['name' => $this->t('Transaction date'), 'value' => $transaction_date],
      ['name' => $this->t('Status code for the 3D transaction'), 'value' => $status_code_3d],
    ];
  }

  /**
   * {@inheritDoc}
   */
  public function getRenderedPaymentReportTable(Request $request): MarkupInterface {
    $info_table_data = $this->buildPaymentReportTable($request);

    $info_table = [
      '#type' => 'table',
      '#header' => [$this->t('Parameter name'), $this->t('Parameter value')],
      '#rows' => $info_table_data,
    ];

    return $this->renderer->render($info_table);
  }

  /**
   * {@inheritDoc}
   */
  public function log(string $message, array $context) {
    $this->loggerFactory->get('commerce_banca_intesa')->debug($message, $context);
  }

  /**
   * {@inheritDoc}
   */
  public function sendMail(OrderInterface $order, $message, array $payment_report): bool {
    $to = $order->getEmail();
    $subject = $this->t('Payment report for order #@number', ['@number' => $order->id()]);

    $body = [
      '#theme' => 'commerce_banca_intesa_payment_report',
      '#order_entity' => $order,
      '#message' => $message,
      '#payment_report' => $payment_report,
    ];

    $params = [
      'id' => 'commerce_banca_intesa_payment_report',
      'from' => $order->getStore()->getEmail(),
    ];
    $customer = $order->getCustomer();
    if ($customer->isAuthenticated()) {
      $params['langcode'] = $customer->getPreferredLangcode();
    }

    return $this->mailHandler->sendMail($to, $subject, $body, $params);
  }

  /**
   * Cleans the string value from restricted characters.
   *
   * @param string $value
   *   The string value to be cleaned.
   *
   * @return string
   *   The cleaned value.
   */
  protected function cleanValue($value): string {
    return str_replace('|', '\\|', str_replace('\\', '\\\\', $value));
  }

  /**
   * Gets the return URL.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return string
   *   The return URL.
   */
  protected function getReturnUrl(OrderInterface $order): string {
    $return_url = Url::fromRoute('commerce_payment.checkout.return', [
      'commerce_order' => $order->id(),
      'step' => 'payment',
    ], ['absolute' => TRUE, 'https' => TRUE]);
    return $return_url->toString();
  }

  /**
   * Gets the cancel URL.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return string
   *   The cancel URL.
   */
  protected function getCancelUrl(OrderInterface $order): string {
    $cancel_url = Url::fromRoute('commerce_payment.checkout.cancel', [
      'commerce_order' => $order->id(),
      'step' => 'payment',
    ], ['absolute' => TRUE, 'https' => TRUE]);
    return $cancel_url->toString();
  }

  /**
   * Generates the hash for client authentication.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param string $random_string
   *   The random string.
   *
   * @return string
   *   The generated hash.
   */
  protected function generateHash(array $configuration, OrderInterface $order, $random_string): string {
    $hash_data = $this->cleanValue($configuration['merchant_id']) . '|';
    $hash_data .= $order->id() . '|';
    $hash_data .= $order->getTotalPrice()->getNumber() . '|';
    $hash_data .= $this->getReturnUrl($order) . '|';
    $hash_data .= $this->getCancelUrl($order) . '|';
    $hash_data .= isset($configuration['auth_option']) ? $configuration['auth_option'] . '||' : 'PreAuth||';
    $hash_data .= $random_string . '||||';
    $hash_data .= '941|';
    $hash_data .= $this->cleanValue($configuration['store_key']);
    return base64_encode(hash('sha512', $hash_data, TRUE));
  }

  /**
   * {@inheritDoc}
   */
  public function isRemoteOrderPaid(string $order_id): bool {
    $payment_gateways = $this->entityTypeManager
      ->getStorage('commerce_payment_gateway')
      ->loadByProperties(['plugin' => 'banca_intesa_offsite_redirect']);
    if (empty($payment_gateways)) {
      $this->log('Payment plugin with ID: @id not found.', ['@id' => 'banca_intesa_offsite_redirect']);
      return FALSE;
    }

    // Get payment gateway configuration.
    $payment_gateway = reset($payment_gateways);
    $configuration = $payment_gateway->getPluginConfiguration();
    $api_url = $configuration[$configuration['mode'] . '_api_url'];
    $merchant_id = $configuration['merchant_id'];
    $username = $configuration['username'];
    $password = $configuration['password'];

    // Build the XML request.
    $xml = '<?xml version="1.0" encoding="UTF-8"?>';
    $xml .= '<CC5Request>';
    $xml .= '<Name>' . $username . '</Name>';
    $xml .= '<Password>' . $password . '</Password>';
    $xml .= '<ClientId>' . $merchant_id . '</ClientId>';
    $xml .= '<OrderId>' . $order_id . '</OrderId>';
    $xml .= '<Extra>';
    $xml .= '<ORDERSTATUS>QUERY</ORDERSTATUS>';
    $xml .= '</Extra>';
    $xml .= '</CC5Request>';

    $client = new Client();

    try {
      $response = $client->post($api_url, [
        'form_params' => [
          'DATA' => $xml,
        ],
        'headers' => [
          'Content-Type' => 'application/x-www-form-urlencoded',
        ],
      ]);

      $http_status = $response->getStatusCode();
      if ($http_status === 200) {
        $body = (string) $response->getBody();
        $xml_response = simplexml_load_string($body);
        $proc_return_code = (string) $xml_response->ProcReturnCode;
        $response_status = (string) $xml_response->Response;

        if ($proc_return_code == '00' && $response_status == 'Approved') {
          $this->log('Banca Intesa return code: @proc_return_code and response status: @response_status for order @order_id.', [
            '@proc_return_code' => $proc_return_code,
            '@response_status' => $response_status,
            '@order_id' => $order_id,
          ]);
          $this->finalizeOrder($order_id, $payment_gateway, $xml_response);
          return TRUE;
        }
        else {
          $this->log('Banca Intesa return code: @proc_return_code and response status: @response_status for order @order_id.', [
            '@proc_return_code' => $proc_return_code,
            '@response_status' => $response_status,
            '@order_id' => $order_id,
          ]);
          return FALSE;
        }
      }
      else {
        $this->log('Banca Intesa http status: @status for order @order_id.', [
          '@status' => $http_status,
          '@order_id' => $order_id,
        ]);
        return FALSE;
      }
    }
    catch (\Exception $e) {
      $this->log('Banca Intesa error querying order status: @error for order @order_id.', [
        '@error' => $e->getMessage(),
        '@order_id' => $order_id,
      ]);
      return FALSE;
    }
  }

  /**
   * {@inheritDoc}
   */
  protected function finalizeOrder(string $order_id, PaymentGateway $payment_gateway, SimpleXMLElement $xml_response): void {
    $order_storage = $this->entityTypeManager->getStorage('commerce_order');
    $order = $order_storage->load($order_id);
    if (!$order instanceof OrderInterface) {
      return;
    }

    $banca_intesa_transaction_id = (string) $xml_response->TransId;
    $banca_intesa_response = (string) $xml_response->Response;
    $banca_intesa_transaction_date = (string) $xml_response->Extra->CAPTURE_DTTM;
    $banca_intesa_authorization_code = (string) $xml_response->Extra->AUTH_CODE;
    $banca_intesa_status_code_3d = (string) $xml_response->Extra->MDSTATUS;
    $banca_intesa_transaction_status_code = (string) $xml_response->ProcReturnCode;

    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
    $payment_data = [
      'type' => 'payment_default',
      'state' => 'completed',
      'amount' => $order->getBalance(),
      'payment_gateway' => $payment_gateway->id(),
      'order_id' => $order->id(),
      'remote_id' => $banca_intesa_transaction_id,
      'remote_state' => $banca_intesa_response,
      'authorized' => strtotime($banca_intesa_transaction_date),
      'avs_response_code' => $banca_intesa_authorization_code,
      'avs_response_code_label' => $banca_intesa_status_code_3d . '||' . $banca_intesa_transaction_status_code,
    ];
    $payment = $payment_storage->create($payment_data);
    $payment->save();

    $order->getState()->applyTransitionById('place');
    $order->save();

    $message = $this->t('Card payment has been successful.');
    $payment_report = [
      ['name' => $this->t('Order ID'), 'value' => $order->id()],
      ['name' => $this->t('Authorization code'), 'value' => $banca_intesa_authorization_code],
      ['name' => $this->t('Payment status'), 'value' => $banca_intesa_response],
      ['name' => $this->t('Transaction status code'), 'value' => $banca_intesa_transaction_status_code],
      ['name' => $this->t('Transaction ID'), 'value' => $banca_intesa_transaction_id],
      ['name' => $this->t('Transaction date'), 'value' => $banca_intesa_transaction_date],
      ['name' => $this->t('Status code for the 3D transaction'), 'value' => $banca_intesa_status_code_3d],
    ];
    $this->sendMail($order, $message, $payment_report);
  }

}
