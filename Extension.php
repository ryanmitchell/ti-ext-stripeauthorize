<?php 

namespace Thoughtco\StripeAuthorize;

use Admin\Models\Orders_model;
use Event;
use Igniter\Flame\Exception\ApplicationException;
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
        // this wont be necessary when stripe authorise + capture is merged to payregister
        Event::listen('payregister.stripe.extendFields', function ($gateway, &$fields, $order, $data) {
            $fields['capture_method'] = 'manual';
        });
        
        // order accepted through notifier extension - accept payment
        Event::listen('thoughtco.notifier.orderAccepted', function ($notifier, $order) {
            
            $order = Orders_model::with(['payment_logs', 'payment_method'])->find($order->order_id);
            
            if (!$this->isStripeOrder($order))
                return;
        
            $intentId = getIntentFromOrder($order);

            $gateway = $this->createGateway($order->payment_method);
                        
            $response = $gateway->capture([
                'paymentIntentReference' => $intentId,
            ])->send();
                            
            if ($response->isSuccessful()) {
                $order->logPaymentAttempt('Payment captured successfully', 1, [], $response->getData());
                return;
            }
    
            $order->logPaymentAttempt('Payment capture failed -> '.$response->getMessage(), 0, [], $response->getData());
        }); 
        
        // order rejected through notifier extension - cancel payment
        Event::listen('thoughtco.notifier.orderRejected', function ($notifier, $order) {
            
            $order = Orders_model::with(['payment_logs', 'payment_method'])->find($order->order_id);
            
            if (!$this->isStripeOrder($order))
                return;
                
            $intentId = getIntentFromOrder($order);

            $gateway = $this->createGateway($order->payment_method);
                        
            $response = $gateway->cancel([
                'paymentIntentReference' => $intentId,
            ])->send();
                            
            $data = $response->getData();
            if (array_get($data, 'status') === 'canceled') {
                $order->logPaymentAttempt('Payment cancelled successfully', 1, [], $data);
                return;
            }
    
            $order->logPaymentAttempt('Payment cancellation failed -> '.$response->getMessage(), 0, [], $data);
            
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
    
    protected function createGateway($paymentMethod)
    {
        $gateway = Omnipay::create('Stripe\PaymentIntents');
        $gateway->setApiKey($paymentMethod->transaction_mode != 'live' ? $paymentMethod->test_secret_key : $paymentMethod->live_secret_key);
    }
    
}
