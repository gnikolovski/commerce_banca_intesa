<?php

namespace Drupal\commerce_banca_intesa;

use Drupal\commerce\MailHandlerInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Class BancaIntesaService.
 *
 * @package Drupal\commerce_banca_intesa
 */
class BancaIntesaService implements BancaIntesaServiceInterface {

  use StringTranslationTrait;

  /**
   * The renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The mail handler.
   *
   * @var \Drupal\commerce\MailHandlerInterface
   */
  protected $mailHandler;

  /**
   * The profile view builder.
   *
   * @var \Drupal\profile\ProfileViewBuilder
   */
  protected $profileViewBuilder;

  /**
   * The current request.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $currentRequest;

  /**
   * BancaIntesaService constructor.
   *
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger.
   * @param \Drupal\commerce\MailHandlerInterface $mail_handler
   *   The mail handler.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   */
  public function __construct(RendererInterface $renderer, LoggerChannelFactoryInterface $loggerFactory, MailHandlerInterface $mail_handler, EntityTypeManagerInterface $entity_type_manager, RequestStack $request_stack) {
    $this->renderer = $renderer;
    $this->logger = $loggerFactory->get('commerce_banca_intesa');
    $this->mailHandler = $mail_handler;
    $this->profileViewBuilder = $entity_type_manager->getViewBuilder('profile');
    $this->currentRequest = $request_stack->getCurrentRequest();
  }

  /**
   * {@inheritDoc}
   */
  public function getRedirectUrl(array $configuration) {
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
  public function buildPostData(array $configuration, OrderInterface $order) {
    $random_string = md5(microtime());
    $shop_url = Url::fromRoute('<front>', [], [
      'absolute' => TRUE,
      'https' => TRUE,
    ]);

    return [
      'currency' => '941',
      'trantype' => 'PreAuth',
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
  public function isHashValid(array $configuration, OrderInterface $order, Request $request) {
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
  public function buildPaymentReportTable(Request $request) {
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
  public function getRenderedPaymentReportTable(Request $request) {
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
  public function log($message, array $context) {
    $this->logger->debug($message, $context);
  }

  /**
   * {@inheritDoc}
   */
  public function sendMail(OrderInterface $order, $message, array $payment_report) {
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
  protected function cleanValue($value) {
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
  protected function getReturnUrl(OrderInterface $order) {
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
  protected function getCancelUrl(OrderInterface $order) {
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
  protected function generateHash(array $configuration, OrderInterface $order, $random_string) {
    $hash_data = $this->cleanValue($configuration['merchant_id']) . '|';
    $hash_data .= $order->id() . '|';
    $hash_data .= $order->getTotalPrice()->getNumber() . '|';
    $hash_data .= $this->getReturnUrl($order) . '|';
    $hash_data .= $this->getCancelUrl($order) . '|';
    $hash_data .= 'PreAuth||';
    $hash_data .= $random_string . '||||';
    $hash_data .= '941|';
    $hash_data .= $this->cleanValue($configuration['store_key']);
    return base64_encode(hash('sha512', $hash_data, TRUE));
  }

}
