<?php

namespace Drupal\commerce_banca_intesa\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides the Banca Intesa off-site redirect payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "banca_intesa_offsite_redirect",
 *   label = "Banca Intesa",
 *   display_label = "Banca Intesa",
 *   forms = {
 *     "offsite-payment" = "Drupal\commerce_banca_intesa\PluginForm\OffsiteRedirect\BancaIntesaForm",
 *   },
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "mastercard", "visa",
 *   },
 *   requires_billing_information = FALSE,
 * )
 */
class BancaIntesaOffsiteRedirect extends OffsitePaymentGatewayBase implements OffsitePaymentGatewayInterface {

  /**
   * The banca intesa service.
   *
   * @var \Drupal\commerce_banca_intesa\BancaIntesaServiceInterface
   */
  protected $bancaIntesaService;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->bancaIntesaService = $container->get('commerce_banca_intesa.banca_intesa_service');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'test_redirect_url' => 'https://testsecurepay.eway2pay.com/fim/est3Dgate',
      'live_redirect_url' => 'https://bib.eway2pay.com/fim/est3Dgate',
      'merchant_id' => '',
      'store_key' => '',
      'username' => '',
      'password' => '',
      'use_display_name' => FALSE,
      'send_mail' => [
        'success' => 'success',
        'fail' => 'fail',
      ],
      'show_payment_report_table' => [
        'success' => 'success',
        'fail' => 'fail',
      ],
      'api_logging' => [
        'request' => 'request',
        'response' => 'response',
      ],
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['test_redirect_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Test redirect URL'),
      '#default_value' => $this->configuration['test_redirect_url'],
      '#required' => TRUE,
    ];

    $form['live_redirect_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Live redirect URL'),
      '#default_value' => $this->configuration['live_redirect_url'],
      '#required' => TRUE,
    ];

    $form['merchant_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Merchant ID'),
      '#default_value' => $this->configuration['merchant_id'],
      '#required' => TRUE,
    ];

    $form['store_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Store key'),
      '#default_value' => $this->configuration['store_key'],
      '#required' => TRUE,
    ];

    $form['username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Username'),
      '#default_value' => $this->configuration['username'],
      '#required' => TRUE,
    ];

    $form['password'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Password'),
      '#default_value' => $this->configuration['password'],
      '#required' => TRUE,
    ];

    $form['use_display_name'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use display name as gateway name'),
      '#description' => $this->t('Use the display name instead of the payment gateway name in user messages.'),
      '#default_value' => $this->configuration['use_display_name'],
    ];

    $form['send_mail'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Send mail'),
      '#options' => [
        'success' => $this->t('Send mail on payment success'),
        'fail' => $this->t('Send mail on payment fail'),
      ],
      '#default_value' => $this->configuration['send_mail'],
    ];

    $form['show_payment_report_table'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Show Payment report table'),
      '#options' => [
        'success' => $this->t('Show on payment success'),
        'fail' => $this->t('Show on payment fail'),
      ],
      '#default_value' => $this->configuration['show_payment_report_table'],
    ];

    $form['api_logging'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Log the following messages for debugging'),
      '#options' => [
        'request' => $this->t('API request messages'),
        'response' => $this->t('API response messages'),
      ],
      '#default_value' => $this->configuration['api_logging'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['test_redirect_url'] = $values['test_redirect_url'];
      $this->configuration['live_redirect_url'] = $values['live_redirect_url'];
      $this->configuration['merchant_id'] = $values['merchant_id'];
      $this->configuration['store_key'] = $values['store_key'];
      $this->configuration['username'] = $values['username'];
      $this->configuration['password'] = $values['password'];
      $this->configuration['use_display_name'] = $values['use_display_name'];
      $this->configuration['send_mail'] = $values['send_mail'];
      $this->configuration['show_payment_report_table'] = $values['show_payment_report_table'];
      $this->configuration['api_logging'] = $values['api_logging'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onReturn(OrderInterface $order, Request $request) {
    if (!empty($this->configuration['api_logging']['response'])) {
      $this->bancaIntesaService->log('Banca Intesa payment success response: <pre>@body</pre>', [
        '@body' => var_export($request->request->all(), TRUE),
      ]);
    }

    $success = TRUE;
    $message = '';

    // Verify the Banca Intesa order ID.
    $banca_intesa_order_id = $request->request->get('ReturnOid');
    if ($banca_intesa_order_id != $order->id()) {
      $success = FALSE;
      $message = 'The Banca Intesa order ID is not valid or missing.';
    }

    // Verify the Banca Intesa client ID.
    $banca_intesa_client_id = $request->request->get('clientid');
    if ($banca_intesa_client_id != $this->configuration['merchant_id']) {
      $success = FALSE;
      $message = 'The Banca Intesa client ID is not valid or missing.';
    }

    // Verify the Banca Intesa digital signature (hash).
    if ($this->bancaIntesaService->isHashValid($this->configuration, $order, $request) === FALSE) {
      $success = FALSE;
      $message = 'The Banca Intesa digital signature is not valid.';
    }

    // Make sure that transaction is approved.
    $banca_intesa_response_code = $request->request->get('ProcReturnCode');
    if ($banca_intesa_response_code != '00') {
      $success = FALSE;
      $message = sprintf('Unexpected Banca Intesa order status response code %s.', $banca_intesa_response_code);
    }

    if ($success == FALSE) {
      if (!empty($this->configuration['send_mail']['fail'])) {
        $mail_message = $this->t('Something went wrong at @gateway. Please review your information and try again.', [
          '@gateway' => $this->bancaIntesaService->getPaymentGatewayName($this->configuration),
        ]);
        $payment_report = $this->bancaIntesaService->buildPaymentReportTable($request);
        $this->bancaIntesaService->sendMail($order, $mail_message, $payment_report);
      }

      if (!empty($this->configuration['show_payment_report_table']['fail'])) {
        $info_table = $this->bancaIntesaService->getRenderedPaymentReportTable($request);
        $this->messenger()->addMessage($info_table);
      }

      throw new PaymentGatewayException($message);
    }

    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
    $banca_intesa_transaction_id = $request->request->get('TransId');
    $banca_intesa_response = $request->request->get('Response');
    $payment = $payment_storage->create([
      'state' => 'completed',
      'amount' => $order->getBalance(),
      'payment_gateway' => $this->parentEntity->id(),
      'order_id' => $order->id(),
      'remote_id' => $banca_intesa_transaction_id,
      'remote_state' => $banca_intesa_response,
    ]);
    $payment->save();

    $message = $this->t('Payment completed successfully at @gateway.', [
      '@gateway' => $this->getPaymentGatewayName(),
    ]);

    $this->messenger()->addMessage($message);

    if (!empty($this->configuration['send_mail']['success'])) {
      $payment_report = $this->bancaIntesaService->buildPaymentReportTable($request);
      $this->bancaIntesaService->sendMail($order, $message, $payment_report);
    }

    if (!empty($this->configuration['show_payment_report_table']['success'])) {
      $info_table = $this->bancaIntesaService->getRenderedPaymentReportTable($request);
      $this->messenger()->addStatus($info_table);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onCancel(OrderInterface $order, Request $request) {
    if (!empty($this->configuration['api_logging']['response'])) {
      $this->bancaIntesaService->log('Banca Intesa payment fail response: <pre>@body</pre>', [
        '@body' => var_export($request->request->all(), TRUE),
      ]);
    }

    $return_code = $request->request->get('ProcReturnCode');
    $message = '';

    // Handles the error response code.
    if ($return_code == '99') {
      $message = $this->t('Something went wrong at @gateway. Please review your information and try again.', [
        '@gateway' => $this->getPaymentGatewayName(),
      ]);
    }

    // Handles the insufficient funds error response code.
    if ($return_code == '51') {
      $message = $this->t('Payment declined at @gateway. Your account has insufficient funds or you have hit your limit.', [
        '@gateway' => $this->getPaymentGatewayName(),
      ]);
    }

    // Handles the declined response code.
    if (!in_array($return_code, ['99', '00', '51'])) {
      $message = $this->t('Payment declined at @gateway. Please review your information and try again.', [
        '@gateway' => $this->getPaymentGatewayName(),
      ]);
    }

    $this->messenger()->addError($message);

    if (!empty($this->configuration['send_mail']['fail'])) {
      $payment_report = $this->bancaIntesaService->buildPaymentReportTable($request);
      $this->bancaIntesaService->sendMail($order, $message, $payment_report);
    }

    if (!empty($this->configuration['show_payment_report_table']['fail'])) {
      $info_table = $this->bancaIntesaService->getRenderedPaymentReportTable($request);
      $this->messenger()->addStatus($info_table);
    }
  }

  /**
   * Gets the payment gateway name.
   *
   * @return string
   *   The payment gateway name.
   */
  protected function getPaymentGatewayName() {
    if (!empty($this->configuration['use_display_name'])) {
      $name = $this->getDisplayLabel();
    }
    else {
      $name = $this->getLabel();
    }

    return $name;
  }

}
