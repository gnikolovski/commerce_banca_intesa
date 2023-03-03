<?php

namespace Drupal\Tests\commerce_banca_intesa\FunctionalJavascript;

use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\Tests\commerce\FunctionalJavascript\CommerceWebDriverTestBase;

/**
 * Tests the Commerce Banca Intesa payment configuration form.
 *
 * @group commerce_banca_intesa
 */
class ConfigurationFormTest extends CommerceWebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'commerce_banca_intesa',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function getAdministratorPermissions() {
    return array_merge([
      'access content',
      'administer commerce_payment_gateway',
    ], parent::getAdministratorPermissions());
  }

  /**
   * Tests creating Banca Intesa payment gateway.
   */
  public function testCreateBancaIntesaGateway() {
    $this->drupalGet('admin/commerce/config/payment-gateways');
    $this->getSession()->getPage()->clickLink('Add payment gateway');
    $this->assertSession()->addressEquals('admin/commerce/config/payment-gateways/add');
    $radio_button = $this->getSession()->getPage()->findField('Banca Intesa');
    $radio_button->click();
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->fillField('label', 'Banca Intesa');
    $this->assertJsCondition('jQuery(".machine-name-value:visible").length > 0');
    $values = [
      'configuration[banca_intesa_offsite_redirect][mode]' => 'test',
      'configuration[banca_intesa_offsite_redirect][test_redirect_url]' => 'test_redirect_url',
      'configuration[banca_intesa_offsite_redirect][live_redirect_url]' => 'live_redirect_url',
      'configuration[banca_intesa_offsite_redirect][merchant_id]' => 'merchant_id',
      'configuration[banca_intesa_offsite_redirect][store_key]' => 'store_key',
      'configuration[banca_intesa_offsite_redirect][use_display_name]' => TRUE,
      'configuration[banca_intesa_offsite_redirect][send_mail][success]' => TRUE,
      'configuration[banca_intesa_offsite_redirect][send_mail][fail]' => FALSE,
      'configuration[banca_intesa_offsite_redirect][show_payment_report_table][success]' => TRUE,
      'configuration[banca_intesa_offsite_redirect][show_payment_report_table][fail]' => FALSE,
      'configuration[banca_intesa_offsite_redirect][api_logging][request]' => TRUE,
      'configuration[banca_intesa_offsite_redirect][api_logging][response]' => FALSE,
      'status' => TRUE,
    ];
    $this->submitForm($values, 'Save');
    $this->assertSession()->pageTextContains('Saved the Banca Intesa payment gateway.');

    $payment_gateway = PaymentGateway::load('banca_intesa');
    $this->assertEquals('banca_intesa', $payment_gateway->id());
    $this->assertEquals('Banca Intesa', $payment_gateway->label());
    $this->assertEquals('banca_intesa_offsite_redirect', $payment_gateway->getPluginId());
    $this->assertEquals(TRUE, $payment_gateway->status());

    $payment_gateway_plugin = $payment_gateway->getPlugin();
    $this->assertEquals('test', $payment_gateway_plugin->getMode());
    $config = $payment_gateway_plugin->getConfiguration();
    $this->assertEquals('test_redirect_url', $config['test_redirect_url']);
    $this->assertEquals('live_redirect_url', $config['live_redirect_url']);
    $this->assertEquals('merchant_id', $config['merchant_id']);
    $this->assertEquals('store_key', $config['store_key']);
    $this->assertTrue($config['use_display_name']);
    $this->assertEquals('success', $config['send_mail']['success']);
    $this->assertEmpty($config['send_mail']['fail']);
    $this->assertEquals('success', $config['show_payment_report_table']['success']);
    $this->assertEmpty($config['show_payment_report_table']['fail']);
    $this->assertEquals('request', $config['api_logging']['request']);
    $this->assertEmpty($config['api_logging']['response']);
  }

}
