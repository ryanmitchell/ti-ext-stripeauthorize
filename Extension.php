<?php 

namespace Thoughtco\StripeAuthorize;

use Admin\Models\Orders_model;
use Event;
use Omnipay\Omnipay;
use System\Classes\BaseExtension;
use Thoughtco\Notifier\Events\OrderCreated;

/**
 * StripeAuthorize Extension Information File
 */
class Extension extends BaseExtension
{
    public function boot()
    {
        // dispatch any orders with default stripe status
        Orders_model::where('status_id', 1)
        ->each(function($order){
            Event::dispatch(new OrderCreated($order));   
        });
        
        // stripe payment capture method should be manual
        Event::listen('payregister.stripe.extendFields', function ($gateway, &$fields, $order, $data) {
            $fields['capture_method'] = 'manual';
        });
        
        // order accepted through notifier - accept payment
        Event::listen('thoughtco.notifier.orderAccepted', function ($notifier, $order) {
            
            $order = Orders_model::with(['payment_logs', 'payment_method'])->find($order->order_id);
        
            // if stripe
            if (isset($order->payment_method) && $order->payment_method->class_name == 'Igniter\PayRegister\Payments\Stripe')
            {        
                $order->payment_logs->each(function($paymentLog) use ($order) {
                    if (array_get($paymentLog->response, 'status') === 'requires_capture')
                    {
                        $intentId = array_get($paymentLog->response, 'id');
                        
                        $gateway = Omnipay::create('Stripe\PaymentIntents');
                        $gateway->setApiKey($order->payment_method->transaction_mode != 'live' ? $order->payment_method->test_secret_key : $order->payment_method->live_secret_key);
                        
                        $response = $gateway->capture([
                            'paymentIntentReference' => $intentId,
                        ])->send();
                                        
                        if ($response->isSuccessful()) {
                            $order->logPaymentAttempt('Payment captured successfully', 1, [], $response->getData());
                            return;
                        }
                
                        $order->logPaymentAttempt('Payment capture failed -> '.$response->getMessage(), 0, [], $response->getData());
                    }
                });
            }
        }); 
        
        // order rejected through notifier - cancel payment
        Event::listen('thoughtco.notifier.orderRejected', function ($notifier, $order) {
            
            $order = Orders_model::with(['payment_logs', 'payment_method'])->find($order->order_id);
        
            // if stripe
            if (isset($order->payment_method) && $order->payment_method->class_name == 'Igniter\PayRegister\Payments\Stripe')
            {            
                $order->payment_logs->each(function($paymentLog) use ($order) {
                    if (array_get($paymentLog->response, 'status') === 'requires_capture')
                    {
                        $intentId = array_get($paymentLog->response, 'id');
                        
                        $gateway = Omnipay::create('Stripe\PaymentIntents');
                        $gateway->setApiKey($order->payment_method->transaction_mode != 'live' ? $order->payment_method->test_secret_key : $order->payment_method->live_secret_key);
                        
                        $response = $gateway->cancel([
                            'paymentIntentReference' => $intentId,
                        ])->send();
                                        
                        $data = $response->getData();
                        if (array_get($data, 'status') === 'canceled') {
                            $order->logPaymentAttempt('Payment cancelled successfully', 1, [], $data);
                            return;
                        }
                
                        $order->logPaymentAttempt('Payment cancellation failed -> '.$response->getMessage(), 0, [], $data);
                    }
                });
            
            }
            
        });                
    }
    
}
