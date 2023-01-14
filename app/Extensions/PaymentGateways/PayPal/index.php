<?php

use App\Events\PaymentEvent;
use App\Events\UserUpdateCreditsEvent;
use App\Models\PartnerDiscount;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ShopProduct;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use PayPalCheckoutSdk\Core\PayPalHttpClient;
use PayPalCheckoutSdk\Core\ProductionEnvironment;
use PayPalCheckoutSdk\Core\SandboxEnvironment;
use PayPalCheckoutSdk\Orders\OrdersCaptureRequest;
use PayPalCheckoutSdk\Orders\OrdersCreateRequest;
use PayPalHttp\HttpException;



/**
 * @param Request $request
 * @param ShopProduct $shopProduct
 */
function PaypalPay(Request $request)
{
    /** @var User $user */
    $user = Auth::user();
    $shopProduct = ShopProduct::findOrFail($request->shopProduct);

     // create a new payment
     $payment = Payment::create([
        'user_id' => $user->id,
        'payment_id' => null,
        'payment_method' => 'paypal',
        'type' => $shopProduct->type,
        'status' => 'open',
        'amount' => $shopProduct->quantity,
        'price' => $shopProduct->price - ($shopProduct->price*PartnerDiscount::getDiscount()/100),
        'tax_value' => $shopProduct->getTaxValue(),
        'tax_percent' => $shopProduct->getTaxPercent(),
        'total_price' => $shopProduct->getTotalPrice(),
        'currency_code' => $shopProduct->currency_code,
        'shop_item_product_id' => $shopProduct->id,
    ]);

    $request = new OrdersCreateRequest();
    $request->prefer('return=representation');
    $request->body = [
            "intent" => "CAPTURE",
            "purchase_units" => [
                [
                    "reference_id" => uniqid(),
                    "description" => $shopProduct->display . (PartnerDiscount::getDiscount()?(" (" . __('Discount') . " " . PartnerDiscount::getDiscount() . '%)'):""),
                    "amount"       => [
                        "value"         => $shopProduct->getTotalPrice(),
                        'currency_code' => strtoupper($shopProduct->currency_code),
                        'breakdown' => [
                            'item_total' =>
                            [
                                'currency_code' => strtoupper($shopProduct->currency_code),
                                'value' => $shopProduct->getPriceAfterDiscount(),
                            ],
                            'tax_total' =>
                            [
                                'currency_code' => strtoupper($shopProduct->currency_code),
                                'value' => $shopProduct->getTaxValue(),
                            ]
                        ]
                    ]
                ]
            ],
            "application_context" => [
                "cancel_url" => route('payment.Cancel'),
                "return_url" => route('payment.PayPalSuccess', ['payment' => $payment->id]),
                'brand_name' =>  config('app.name', 'Laravel'),
                'shipping_preference'  => 'NO_SHIPPING'
            ]


    ];



    try {
        // Call API with your client and get a response for your call
        $response = getPayPalClient()->execute($request);

        Redirect::away($response->result->links[1]->href)->send();
    } catch (HttpException $ex) {
        error_log($ex->statusCode);
        error_log($ex->getMessage());

        $payment->delete();
        return Redirect::route('payment.Cancel');
    }
}
/**
 * @param Request $laravelRequest
 */
function PaypalSuccess(Request $laravelRequest)
{
    $user = Auth::user();
    $payment = Payment::findOrFail($laravelRequest->payment);
    $shopProduct = ShopProduct::findOrFail($payment->shop_item_product_id);
 
    $request = new OrdersCaptureRequest($laravelRequest->input('token'));
    $request->prefer('return=representation');

    try {
        // Call API with your client and get a response for your call
        $response = getPayPalClient()->execute($request);
        if ($response->statusCode == 201 || $response->statusCode == 200) {
            //update server limit
            if (config('SETTINGS::USER:SERVER_LIMIT_AFTER_IRL_PURCHASE') !== 0) {
                    if ($user->server_limit < config('SETTINGS::USER:SERVER_LIMIT_AFTER_IRL_PURCHASE')) {
                        $user->update(['server_limit' => config('SETTINGS::USER:SERVER_LIMIT_AFTER_IRL_PURCHASE')]);
                    }
            }
            //update User with bought item
            if ($shopProduct->type=="Credits") {
                    $user->increment('credits', $shopProduct->quantity);
            }elseif ($shopProduct->type=="Server slots"){
                    $user->increment('server_limit', $shopProduct->quantity);
            }
            //give referral commission always
            if((config("SETTINGS::REFERRAL:MODE") == "commission" || config("SETTINGS::REFERRAL:MODE") == "both") && $shopProduct->type=="Credits" && config("SETTINGS::REFERRAL::ALWAYS_GIVE_COMMISSION") == "true"){
                    if($ref_user = DB::table("user_referrals")->where('registered_user_id', '=', $user->id)->first()){
                        $ref_user = User::findOrFail($ref_user->referral_id);
                        $increment = number_format($shopProduct->quantity*(PartnerDiscount::getCommission($ref_user->id))/100,0,"","");
                        $ref_user->increment('credits', $increment);

                        //LOGS REFERRALS IN THE ACTIVITY LOG
                        activity()
                            ->performedOn($user)
                            ->causedBy($ref_user)
                            ->log('gained '. $increment.' '.config("SETTINGS::SYSTEM:CREDITS_DISPLAY_NAME").' for commission-referral of '.$user->name.' (ID:'.$user->id.')');
                    }

            }
            //update role give Referral-reward
            if ($user->role == 'member') {
                    $user->update(['role' => 'client']);

                    //give referral commission only on first purchase
                    if((config("SETTINGS::REFERRAL:MODE") == "commission" || config("SETTINGS::REFERRAL:MODE") == "both") && $shopProduct->type=="Credits" && config("SETTINGS::REFERRAL::ALWAYS_GIVE_COMMISSION") == "false"){
                        if($ref_user = DB::table("user_referrals")->where('registered_user_id', '=', $user->id)->first()){
                            $ref_user = User::findOrFail($ref_user->referral_id);
                            $increment = number_format($shopProduct->quantity*(PartnerDiscount::getCommission($ref_user->id))/100,0,"","");
                            $ref_user->increment('credits', $increment);

                            //LOGS REFERRALS IN THE ACTIVITY LOG
                            activity()
                                ->performedOn($user)
                                ->causedBy($ref_user)
                                ->log('gained '. $increment.' '.config("SETTINGS::SYSTEM:CREDITS_DISPLAY_NAME").' for commission-referral of '.$user->name.' (ID:'.$user->id.')');
                        }

                    }

            }

            //update payment
            $payment->update([
                'status' => 'success',
                'payment_id' => $response->result->id,
            ]);

            event(new UserUpdateCreditsEvent($user));
            event(new PaymentEvent($payment));

            error_log("Payment successfull");

            // redirect to the payment success page with success message
            Redirect::route('home')->with('success', 'Payment successful')->send();
        } elseif(env('APP_ENV') == 'local') {
            // If call returns body in response, you can get the deserialized version from the result attribute of the response
            $payment->delete();
            dd($response);
        } else {
            $payment->update([
                'status' => 'failed',
                'payment_id' => $response->result->id,
            ]);  
            abort(500);
        }
    } catch (HttpException $ex) {
        if (env('APP_ENV') == 'local') {
            echo $ex->statusCode;
            $payment->delete();
            dd($ex->getMessage());
        } else {
            $payment->update([
                'status' => 'failed',
                'payment_id' => $response->result->id,
            ]);  
            abort(422);
        }
    }
}
/**
 * @return PayPalHttpClient
 */
function getPayPalClient()
{
    $environment = env('APP_ENV') == 'local'
        ? new SandboxEnvironment(getPaypalClientId(), getPaypalClientSecret())
        : new ProductionEnvironment(getPaypalClientId(), getPaypalClientSecret());
    return new PayPalHttpClient($environment);
}
/**
 * @return string
 */
function getPaypalClientId()
{
    return env('APP_ENV') == 'local' ?  config("SETTINGS::PAYMENTS:PAYPAL:SANDBOX_CLIENT_ID") : config("SETTINGS::PAYMENTS:PAYPAL:CLIENT_ID");
}
/**
 * @return string
 */
function getPaypalClientSecret()
{
    return env('APP_ENV') == 'local' ? config("SETTINGS::PAYMENTS:PAYPAL:SANDBOX_SECRET") : config("SETTINGS::PAYMENTS:PAYPAL:SECRET");
}
function getConfig()
{
    return [
        "name" => "PayPal",
        "description" => "PayPal payment gateway",
        "settings" => [
            "mode" => [
                "type" => "select",
                "label" => "Mode",
                "value" => config("APP_ENV") == 'local' ? "sandbox" : "live",
                "options" => [
                    "sandbox" => "Sandbox",
                    "live" => "Live",
                ],
            ],
            "CLIENT_ID" => [
                "type" => "text",
                "label" => "PayPal Client ID",
                "value" => config("SETTINGS::PAYMENTS:PAYPAL:CLIENT_ID"),
            ],
            "SECRET" => [
                "type" => "text",
                "label" => "PayPal Secret",
                "value" => config("SETTINGS::PAYMENTS:PAYPAL:SECRET"),
            ],
            "SANDBOX_CLIENT_ID" => [
                "type" => "text",
                "label" => "PayPal Sandbox Client ID",
                "value" => config("SETTINGS::PAYMENTS:PAYPAL:SANDBOX_CLIENT_ID"),
            ],
            "SANDBOX_SECRET" => [
                "type" => "text",
                "label" => "PayPal Sandbox Secret",
                "value" => config("SETTINGS::PAYMENTS:PAYPAL:SANDBOX_SECRET"),
            ],
        ],
    ];
}