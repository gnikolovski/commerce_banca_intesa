<?php

namespace Drupal\commerce_banca_intesa;

use Drupal\commerce_order\Entity\OrderInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Interface BancaIntesaServiceInterface.
 *
 * @package Drupal\commerce_banca_intesa
 */
interface BancaIntesaServiceInterface {

  /**
   * Gets the payment gateway redirect URL.
   *
   * @param array $configuration
   *   The payment gateway plugin configuration.
   *
   * @return string
   *   The payment gateway redirect URL.
   */
  public function getRedirectUrl(array $configuration);

  /**
   * Builds the data that we are sending to the payment gateway provider.
   *
   * @param array $configuration
   *   The payment gateway plugin configuration.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return array
   *   The data that we are sending to the payment gateway provider.
   */
  public function buildPostData(array $configuration, OrderInterface $order);

  /**
   * Checks if hash is valid.
   *
   * @param array $configuration
   *   The payment gateway plugin configuration.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return bool
   *   TRUE if hash is valid, otherwise FALSE.
   */
  public function isHashValid(array $configuration, OrderInterface $order, Request $request);

  /**
   * Creates the payment report data.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return array
   *   The payment report data.
   */
  public function buildPaymentReportTable(Request $request);

  /**
   * Gets the rendered payment report table.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Drupal\Component\Render\MarkupInterface
   *   The rendered payment report data.
   */
  public function getRenderedPaymentReportTable(Request $request);

  /**
   * Logs the message.
   *
   * @param string $message
   *   The log message.
   * @param array $context
   *   The log message context.
   */
  public function log($message, array $context);

  /**
   * Sends the email to customer.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param string $message
   *   The message text.
   * @param array $payment_report
   *   The payment report data.
   *
   * @return bool
   *   TRUE if the email was sent successfully, FALSE otherwise.
   */
  public function sendMail(OrderInterface $order, $message, array $payment_report);

}
