<?php

namespace Drupal\commerce_banca_intesa\PluginForm\OffsiteRedirect;

use Drupal\commerce_banca_intesa\BancaIntesaServiceInterface;
use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\gnikolovski_payment_log\PaymentLogServiceInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines the BancaIntesaForm class.
 *
 * @package Drupal\commerce_banca_intesa\PluginForm\OffsiteRedirect
 */
class BancaIntesaForm extends BasePaymentOffsiteForm implements ContainerInjectionInterface {

  /**
   * The banca intesa service.
   *
   * @var \Drupal\commerce_banca_intesa\BancaIntesaServiceInterface
   */
  protected $bancaIntesaService;

  /**
   * The payment log service.
   *
   * @var \Drupal\gnikolovski_payment_log\PaymentLogServiceInterface
   */
  protected $paymentLogService;

  /**
   * Constructs a new BancaIntesaForm object.
   *
   * @param \Drupal\commerce_banca_intesa\BancaIntesaServiceInterface $banca_intesa_service
   *   The banca intesa service.
   */
  public function __construct(BancaIntesaServiceInterface $banca_intesa_service, PaymentLogServiceInterface $payment_log_service) {
    $this->bancaIntesaService = $banca_intesa_service;
    $this->paymentLogService = $payment_log_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('commerce_banca_intesa.banca_intesa_service'),
      $container->get('gnikolovski_payment_log.service'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    $configuration = $this->entity->getPaymentGateway()->getPluginConfiguration();
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $this->entity->getOrder();

    $redirect_url = $this->bancaIntesaService->getRedirectUrl($configuration);
    $post_data = $this->bancaIntesaService->buildPostData($configuration, $order);

    if (!empty($configuration['api_logging']['request'])) {
      $this->bancaIntesaService->log('Banca Intesa payment request: @url <pre>@body</pre>', [
        '@url' => $redirect_url,
        '@body' => var_export($post_data, TRUE),
      ]);
    }

    $this->paymentLogService->logRequest(
      $order->getEmail(),
      $order->id(),
      '',
      '',
      $this->entity->getPaymentGateway()->getPluginId(),
      json_encode($post_data),
    );

    return $this->buildRedirectForm(
      $form,
      $form_state,
      $redirect_url,
      $post_data,
      self::REDIRECT_POST
    );
  }

}
