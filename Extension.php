<?php

namespace Thoughtco\StripeAuthorize;

use Admin\Facades\AdminAuth;
use Admin\Models\Orders_model;
use Admin\Models\Payments_model;
use Igniter\Flame\Exception\ApplicationException;
use Illuminate\Support\Facades\Event;
use System\Classes\BaseExtension;
use Thoughtco\OrderApprover\Events\OrderCreated;

/**
 * StripeAuthorize Extension Information File
 */
class Extension extends BaseExtension
{
    public function boot()
    {
        Event::listen('admin.controller.beforeResponse', function ($controller, $params) {
            if (!AdminAuth::isLogged() OR !$controller->getLocationId()) return;

            Payments_model::where([
                'class_name' => 'Igniter\PayRegister\Payments\Stripe',
                'status' => 1,
            ])
                ->each(function ($payment) {
                    if (!$payment->data)
                        return;

                    // dispatch any orders with default stripe status
                    Orders_model::where([
                        'status_id' => $payment->data['order_status'],
                        'payment' => $payment->code,
                    ])
                        ->each(function ($order) {
                            Event::dispatch(new OrderCreated($order));
                        });
                });
        });

        // order accepted through orderApprover extension - accept payment
        Event::listen('thoughtco.orderApprover.orderAccepted', function ($notifier, $order) {
            $order = Orders_model::with(['payment_logs', 'payment_method'])->find($order->order_id);

            if (!$this->isStripeOrder($order))
                return;

            $intentId = $this->getIntentFromOrder($order);

            $order->payment_method->capturePaymentIntent($intentId, $order);
        });

        // order rejected through orderApprover extension - cancel payment
        Event::listen('thoughtco.orderApprover.orderRejected', function ($notifier, $order) {
            $order = Orders_model::with(['payment_logs', 'payment_method'])->find($order->order_id);

            if (!$this->isStripeOrder($order))
                return;

            $intentId = $this->getIntentFromOrder($order);

            $order->payment_method->cancelPaymentIntent($intentId, $order);
        });
    }

    protected function isStripeOrder($order)
    {
        return isset($order->payment_method) && $order->payment_method->class_name == 'Igniter\PayRegister\Payments\Stripe';
    }

    protected function getIntentFromOrder($order)
    {
        foreach ($order->payment_logs as $paymentLog) {
            if (array_get($paymentLog->response, 'status') === 'requires_capture') {
                $intentId = array_get($paymentLog->response, 'id');
                if ($intentId)
                    return $intentId;
            }
        }

        throw new ApplicationException('Missing Stripe Intent ID');
    }
}
