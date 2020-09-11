<?php

namespace Drupal\commerce_banca_intesa\PluginForm\OffsiteRedirect;

use Drupal\commerce_banca_intesa\BancaIntesaServiceInterface;
use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class BancaIntesaForm.
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
   * Constructs a new BancaIntesaForm object.
   *
   * @param \Drupal\commerce_banca_intesa\BancaIntesaServiceInterface $banca_intesa_service
   *   The banca intesa service.
   */
  public function __construct(BancaIntesaServiceInterface $banca_intesa_service) {
    $this->bancaIntesaService = $banca_intesa_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('commerce_banca_intesa.banca_intesa_service')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    $configuration = $this->entity->getPaymentGateway()->getPluginConfiguration();
    $order = $this->entity->getOrder();

    $redirect_url = $this->bancaIntesaService->getRedirectUrl($configuration);
    $post_data = $this->bancaIntesaService->buildPostData($configuration, $order);

    if (!empty($configuration['api_logging']['request'])) {
      $this->bancaIntesaService->log('Banca Intesa payment request: @url <pre>@body</pre>', [
        '@url' => $redirect_url,
        '@body' => var_export($post_data, TRUE),
      ]);
    }

    $form = $this->buildRedirectForm(
      $form,
      $form_state,
      $redirect_url,
      $post_data,
      self::REDIRECT_POST
    );

    return $form;
  }

}
