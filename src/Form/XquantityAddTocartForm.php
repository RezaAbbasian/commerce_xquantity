<?php

namespace Drupal\commerce_xquantity\Form;

use Drupal\commerce\Context;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_cart\Form\AddToCartForm;
use Drupal\Component\Utility\NestedArray;

/**
 * Overrides the order item add to cart form.
 */
class XquantityAddTocartForm extends AddToCartForm {

  /**
   * The IDs of all forms.
   *
   * @var array
   */
  protected static $formIds = [];

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    if (empty($this->formId)) {
      $this->formId = $this->getBaseFormId();
    }
    $id = $this->formId;

    if (!in_array($id, static::$formIds)) {
      /** @var \Drupal\commerce_order\Entity\OrderItemInterface $order_item */
      $order_item = $this->entity;
      if ($purchased_entity = $order_item->getPurchasedEntity()) {
        extract($purchased_entity->toArray());
      }
      else {
        extract($order_item->toArray());
      }
      $properties = [
        'variation_id' => !isset($variation_id) ?: $variation_id,
        'uuid' => !isset($uuid) ?: $uuid,
        'uid' => !isset($uid) ?: $uid,
        'product_id' => !isset($product_id) ?: $product_id,
        'created' => !isset($created) ?: $created,
      ];
      $this->formId .= '_' . sha1(serialize($properties));
    }
    else {
      $base_id = $this->getBaseFormId();
      // For the case when on a page 2+ exactly the same purchased entities.
      while (in_array($id, static::$formIds)) {
        $id = $base_id . '_' . sha1($id . $id);
      }
      $this->formId = $id;
    }
    static::$formIds[] = $this->formId;

    return $this->formId;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $item = $price = NULL;
    $form_object = $form_state->getFormObject();
    $scale = $form_object->quantityScale ?: 0;
    $order_item = $this->entity;
    $quantity = $order_item->getQuantity();
    $order_type_id = $this->orderTypeResolver->resolve($order_item);
    $store = $this->selectStore($order_item->getPurchasedEntity());
    $cart = $this->cartProvider->getCart($order_type_id, $store);
    if ($cart && ($items = $cart->getItems())) {
      $matcher = \Drupal::service('commerce_cart.order_item_matcher');
      if ($item = $matcher->match($order_item, $cart->getItems())) {
        $quantity = bcadd($quantity, $item->getQuantity(), $scale);
      }
    }
    if ($qty_prices = $form_object->quantityPrices) {
      foreach ($qty_prices as $qty => $adjustment) {
        $start = bccomp($qty, $quantity, $scale);
        $end = $adjustment['end'] ? bccomp($quantity, $adjustment['end'], $scale) : 0;
        if (($end === 1) || ($start === 1)) {
          continue;
        }
        $price = $adjustment['price'];
      }
      if ($price) {
        $this->entity->setUnitPrice($price, TRUE);
        if ($item && !$price->equals($item->getUnitPrice())) {
          $item->setUnitPrice($price, TRUE);
          $this->cartManager->updateOrderItem($cart, $item, FALSE);
        }
      }
    }
    parent::submitForm($form, $form_state);
    $messenger = $this->messenger();
    $messages = $messenger->messagesByType('status');
    $messenger->deleteByType('status');
    foreach ($messages as $msg) {
      if (preg_match('/\<a href\="\/cart"\>.*\<\/a\>/', $msg->__toString(), $matches)) {
        $this->moduleHandler->alter("xquantity_added_to_cart_msg", $msg, $this);
        $msg && $messenger->addMessage($msg);
      }
      else {
        $messenger->addMessage($msg);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\commerce_order\Entity\OrderItemInterface $order_item */
    $order_item = $this->entity;
    $settings = $order_item->getQuantityWidgetSettings();
    $default = $step = $settings['#step'] ?: $settings['#base_step'];
    $min = $settings['#min'] && $step <= $settings['#min'] ? $settings['#min'] : $step;
    $default = $settings['#default_value'] ?: ($settings['#base_default_value'] ?: $min);
    $value = $form_state->getValue(['quantity', 0, 'value']);
    // If the value is NULL it means the quantity field is disabled.
    $quantity = $value !== NULL ? $value : $default;

    if (!$quantity || ($quantity < $min)) {
      $form_state->setErrorByName('quantity', $this->t('The quantity should be no less than %min', [
        '%min' => $min,
      ]));
      return;
    }
    parent::validateForm($form, $form_state);
    if ($form_state->getTriggeringElement()['#type'] == 'submit'
      && ($id = NestedArray::getValue($form, [
        'purchased_entity',
        'widget',
        '0',
        'variation',
        '#value',
      ]))
    ) {
      /** @var \Drupal\commerce\PurchasableEntityInterface $purchased_entity */
      $purchased_entity = $order_item->getPurchasedEntity()::load($id);
      $store = $this->selectStore($purchased_entity);
      $context = new Context($this->currentUser, $store, time(), ['xquantity' => 'add_to_cart']);
      $availability = \Drupal::service('commerce.availability_manager');
      $available = $purchased_entity && $availability->check($purchased_entity, $quantity, $context);
      if (!$available) {
        $msg = $this->t('Unfortunately, the quantity %quantity of the %label is not available right at the moment.', [
          '%quantity' => $quantity,
          '%label' => $purchased_entity ? $purchased_entity->label() : $this->t('???'),
        ]);
        $purchased_entity && $this->moduleHandler->alter("xquantity_add_to_cart_not_available_msg", $msg, $quantity, $purchased_entity);
        $form_state->setErrorByName('quantity', $msg);
      }
    }
  }

}
