<?php 

namespace Thoughtco\StripeAuthorize;

use AdminAuth;
use Admin\Models\Orders_model;
use Admin\Models\Payments_model;
use Event;
use Igniter\Flame\Exception\ApplicationException;
use Stripe\StripeClient;
use System\Classes\BaseExtension;
use Thoughtco\OrderApprover\Events\OrderCreated;

/**
 * StripeAuthorize Extension Information File
 */
class Extension extends BaseExtension
{
    public function boot()
    {
        
        Event::listen('admin.controller.beforeResponse', function ($controller, $params){
        
            if (!AdminAuth::isLogged() OR !$controller->getLocationId()) return;
            
            Payments_model::where([
                'class_name' => 'Igniter\PayRegister\Payments\Stripe',
                'status' => 1
            ])
            ->each(function($payment) {
                
                if (!$payment->data)
                    return;
    
                // dispatch any orders with default stripe status
                Orders_model::where([
                    'status_id' => $payment->data['order_status'],
                    'payment' => $payment->code,
                ])
                ->each(function($order){
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

            $gateway = $this->createGateway($order->payment_method);
            
            try {
                        
                $response = $gateway->paymentIntents->capture($intentId, []);
                            
                if ($response->status == 'succeeded') {
                    $order->logPaymentAttempt('Payment captured successfully', 1, [], $response);
                    return;
                }
                
                throw new Exception('Status '.$response->status);
            
            } catch (Exception $e) {
                $order->logPaymentAttempt('Payment capture failed -> '.$e->getMessage(), 0, [], $response);
            }
        }); 
        
        // order rejected through orderApprover extension - cancel payment
        Event::listen('thoughtco.orderApprover.orderRejected', function ($notifier, $order) {
            
            $order = Orders_model::with(['payment_logs', 'payment_method'])->find($order->order_id);
            
            if (!$this->isStripeOrder($order))
                return;
                
            $intentId = $this->getIntentFromOrder($order);

            $gateway = $this->createGateway($order->payment_method);
            
            try {
                        
                $response = $gateway->paymentIntents->cancel($intentId, []);
                        
                if ($response->status == 'canceled') {
                    $order->logPaymentAttempt('Payment cancelled successfully', 1, [], $response);
                    return;
                }
                
                throw new Exception('Status '.$response->status);
            
            } catch (Exception $e) {
                $order->logPaymentAttempt('Payment cancellation failed -> '.$e->getMessage(), 0, [], $response);
            }    
            
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
        $gateway = new StripeClient([
            'api_key' => $paymentMethod->transaction_mode != 'live' ? $paymentMethod->test_secret_key : $paymentMethod->live_secret_key,
        ]);
       
        return $gateway;
    }
    
}
