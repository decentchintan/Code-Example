<?php

// Template Name: Account: Register
include 'vendor/autoload.php';

use Soap\Gocardless;
use Subscriptions\Entity\User;
use Omnipay\Omnipay;
use Omnipay\Common\CreditCard;
use Omnipay\Common\Item;
use Omnipay\Common\ItemBag;



if (is_user_logged_in()) {
    wp_redirect(SubscriptionContainer()->get('Options')->get('packages_page'));
}

$session = \Soap\Gocardless\Session\Session::getInstance();

$subscriptionSessionContainer = SubscriptionContainer()->get('Session');

if ($_POST['action'] == 'Register') {

    $validSubmission = validate_registration($_POST['register'], $_FILES['profile'], $_POST['profile']);

    if(is_array($validSubmission)) {

        $subscriptionSessionContainer->set('form_data', $validSubmission);
        if($_POST['payment_method'] == 'gocardless'){
            try {
                $flow = RedirectFlowFactory()->create();
                wp_redirect( $flow->getRedirectUrl(), 302 );
                exit();
            } catch(Exception $e) {
                $subscriptionSessionContainer->remove('form_data');
                $message = WP_DEBUG ? $e->getMessage() : 'We are sorry. Something went wrong with processing your request.';
                $router->redirectWithFlash(get_permalink(), ['error' => $message], true);
            }
        } else {

            $cart = $_SESSION['cart'];
            $packages = $session->getPackage();
            
            $additional_price_per_magazine = $packages['additional_price_per_magazine'];

            $Total = $packages['price'] + ( ( $session->getAdditionalMagazine() - 1 ) * $additional_price_per_magazine );
            
            $itemBag = new itemBag;
            $item = new Item;
            $item->setName( $packages['display_name'] );
            $qty = $session->getAdditionalMagazine();
            $item->setQuantity( $qty );
            $price = $packages['price'];
            
            $cart_price = $Total;
            $item->setPrice( $price );
            $itemBag->add( $item );
            $gateway = OmniPay::create('SagePay\Server');
            $gateway->setVendor('northpointpubli');

            if (IS_TEST_SITE) {
                $gateway->setTestMode(true); // For a test account
            }else{
                $gateway->setTestMode(false); // For a test account
            }

            $user_details = array(
                'first_name' => $_POST['register']['first_name'], 
                'last_name' => $_POST['register']['last_name'], 
                'address_line_1' => $_POST['profile']['address_line_1'], 
                'address_line_2' => $_POST['profile']['address_line_2'], 
                'town' => $_POST['profile']['town'], 
                'postcode' => $_POST['profile']['postcode'], 
                'telephone' => $_POST['profile']['telephone'], 
                'email' => $_POST['register']['email'], 
            );

            array_walk( $user_details, function( &$item ) {
                $item = mb_convert_encoding( $item, 'ISO-8859-1', 'UTF-8' );
            } );
            
            $credit_card = new CreditCard([
                'firstName'         => $user_details['first_name'],
                'lastName'          => $user_details['last_name'],
                
                'billingFirstName'  => $user_details['first_name'],
                'billingLastName'   => $user_details['last_name'],
                'billingAddress1'   => $user_details['address_line_1'],
                'billingAddress2'   => $user_details['address_line_2'],
                'billingCity'       => $user_details['town'],
                'billingPostcode'   => $user_details['postcode'],
                'billingCountry'    => 'GB',
                'billingPhone'      => $user_details['telephone'],
                
                'email'             => $user_details['email'],
                'clientIp'          => $_SERVER['SERVER_ADDR'],

                'shippingFirstName' => $user_details['first_name'],
                'shippingLastName'  => $user_details['last_name'],
                'shippingAddress1'  => $user_details['address_line_1'],
                'shippingAddress2'  => $user_details['address_line_2'],
                'shippingCity'      => $user_details['town'],
                'shippingPostcode'  => $user_details['postcode'],
                'shippingCountry'   => 'GB',
                'shippingPhone'     => $user_details['telephone'],
            ]);

            $transactionID = $cart['id'] . '-' . rand(100, 999);
            
            SubscriptionContainer()->get('Session')->set('cart_transaction_id', $transactionID );

            $request = $gateway->purchase( [
                'amount' => number_format( $cart_price, 2 ),
                'currency' => 'GBP',
                'card' => $credit_card,
                'returnUrl' => site_url( '/sagepay-subscribe-notify/', 'https' ),
                'notifyUrl' => site_url( '/sagepay-subscribe-notify/', 'https' ),
                'transactionId' => $transactionID,
                'description' => $packages['display_name'],
                'items' => $itemBag,
                'APPLY_3DSECURE_APPLY' => 1,
            ] );

            $request->setAccountType( \Omnipay\SagePay\Message\AbstractRequest::ACCOUNT_TYPE_E );

            $submissionData = array();
            $submissionData['user_details'] = $user_details;
            $submissionData['cart'] = $cart;
            $submissionData['amount'] = $Total;
            $submissionData['item'] = $packages['display_name'];
            $submissionData['packages'] = $packages['id'];


            try {
                
                $response = $request->send();

                global $wpdb;
                $table_name = $wpdb->prefix.'sagepay_tx';

                if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) != $table_name ) {
                    //table not in database. Create new table
                    $charset_collate = $wpdb->get_charset_collate();
                 
                    $sql = "CREATE TABLE $table_name (
                        id varchar(100) NOT NULL,
                        data text NOT NULL,
                        message varchar(500),
                        notify_data text,
                        pay_status varchar(26) NOT NULL,
                        created_on TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (id),
                        UNIQUE KEY (id)
                    ) $charset_collate;";

                    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

                    dbDelta( $sql );
                }
                
                $wpdb->insert( 
                    $table_name, 
                    array( 
                        'id' => $transactionID, 
                        'data' => $response->getTransactionReference() ,
                        'pay_status' => 'PENDING'
                    ), 
                    array( 
                        '%s', 
                        '%s',
                        '%s',
                    ) 
                );

                if ($response->isSuccessful()) :

                    //not using this part

                elseif ($response->isRedirect()) :

                    $reference = $response->getTransactionReference();
                    $submissionData['reference'] = $reference;

                    /* Insert User  */
                    $profileData = $_POST['profile'];
                    $registerData = $_POST['profile'];
                    $args = array(
                        'user_login'    => $_POST['register']['username'],
                        'display_name'  => $_POST['register']['username'],
                        'user_pass'     => $_POST['register']['password'],
                        'user_email'    => $_POST['register']['email'],
                        'first_name'    => $_POST['register']['first_name'],
                        'last_name'     => $_POST['register']['last_name'],
                    );
                    $userID = wp_insert_user( $args );

                    if(is_wp_error($userID)) {
                        return false;
                    }

                    update_user_meta($userID, 'image', $_POST['profile_attachment_id']);

                    //Update the users nicename if they have a company name.
                    if (isset($profileData['company_name']) && $profileData['company_name'] != "")
                    {
                        $args = array(
                            'ID' => $userID,
                            'user_nicename' => str_replace(' ', '-', $profileData['company_name'])
                        );

                        wp_update_user($args);
                    }

                    unset($registerData['username']);
                    unset($registerData['password']);
                    unset($registerData['password_retype']);
                    unset($registerData['email']);
                    unset($registerData['first_name']);
                    unset($registerData['last_name']);

                    foreach ($registerData as $key => $value)
                    {
                        update_user_meta($userID, $key, $value);
                    }

                    foreach ($profileData as $key => $value)
                    {
                        update_user_meta($userID, $key, $value);
                    }

                    $submissionData['user'] = $userID;

                    /* insert user end */

                    SaveTransactionReference($transactionID, $submissionData);

                    $response->redirect();

                else :
                    //do something with an error
                    echo $response->getMessage();

                endif;

            } catch (\Exception $e) {

                //do something with this if an error has occurred
                echo 'Sorry, there was an error processing your payment. Please try again later.';
            }
        }
    }
}

$plans = SubscriptionContainer()->get('PackageRepository')->all();
$router = SubscriptionContainer()->get('Router');
$session->setPackageUrl(get_permalink());
$flow = null;

if (wp_verify_nonce($_POST['_nonce'], "confirm-package-action") && is_numeric($_POST['package'])) {
    $packageId = (int) $_POST['package'];
    $session->setPackageId($packageId);

    if(isset($_POST['add_more_magazine'])) {
        $session->setAdditionalMagazine((int) $_POST['add_more_magazine']);
    }

    wp_redirect('/account/register?step=details');
    exit();
}

$currentStep = isset($_GET['step']) && ! empty($_GET['step']) ? $_GET['step'] : null;

$selectedPackageId = null;
$hasSelectedPackage = false;
$magazineCount = 0;

if(wp_verify_nonce( $_POST['_nonce'], "confirm-package-selection" ) && is_numeric($_POST['package'])) {
    $selectedPackageId = (int) $_POST['package'];
    $hasSelectedPackage = true;
    $currentStep = 'confirm';
} elseif(isset($_GET['package'])) {
    $package = SubscriptionContainer()->get('PackageRepository')->findByName($_GET['package']);

    if($package) {
        $selectedPackageId = (int) $package['id'];
        $hasSelectedPackage = true;
        $currentStep = 'confirm';
    }
}

if($currentStep == 'finish' && ! $subscriptionSessionContainer->has('signup_finish')) {
    $router->redirect(get_permalink() . '?step=plan', true);
}

$issues = get_posts(array('post_type' => 'products', 'posts_per_page' => -1));

$secondaryTitle = get_field('secondary_title');
if (empty($secondaryTitle)) {
    $secondaryTitle = 'Choose One Of Our Subscription Packages';
}

$subscribedWith = null;

if ($currentStep == 'finish' && isset($_GET['subscription'])) {
    $subscribedWith = SubscriptionContainer()->get('PackageRepository')->find((int) $_GET['subscription']);
}

get_header(); ?>

<main class="main">
    <div class="f-container f-container-center">
        <section class="section has-angle-bot">
            
            <?php fat_get_partial('breadcrumbs') ?>

            <div class="f-grid f-grid-large" data-f-grid-margin>
                <div class="f-width-1-1">

                    <?php 
                    /**
                     * first page - display vertical options to user
                     */
                    if ($currentStep == 'plan' || is_null($currentStep)) : ?>
                        
                        <div class="intro-section f-text-center">
                            <?php the_content(); ?>

                            <h3><?php echo $secondaryTitle ?></h3>
                        </div>

                        <?php fat_get_partial( 'subscribe/plans', null, ['plans' => $plans, 'selectedPackageId' => $selectedPackageId, 'magazineCount' => $magazineCount] , true ); ?>

                    <?php 
                    /**
                     * step 2
                     * 
                     * A plan has been picked and now choosing issue amount
                     */
                    elseif ($currentStep == 'confirm'): ?>


                        <div class="plan-horizontal f-grid">

                            <?php 

                                $plan = SubscriptionContainer()->get('PackageRepository')->find($selectedPackageId);

                                if($plan['name'] == 'LBV Hub') {
                                    $titleClass = 'Plan__title--lbv-hub';
                                } elseif($plan['name'] == 'Agency Account') {
                                    $titleClass = 'Plan__title--agency-account';
                                } elseif($plan['name'] == 'Print Only') {
                                    $titleClass = 'Plan__title--print-only';
                                }
                             ?>
                            <div class="f-width-medium-1-2">
                                
                                <?php fat_get_partial(
                                    'subscribe/plan', 
                                    null,
                                    array('plan' => $plan, 'selected' => true, 'magazineCount' =>  $magazineCount, 'titleClass' => $titleClass),
                                    true
                                ); ?>
                            </div>

                            <div class="f-width-medium-1-2 subscribe-panel">
                                <form action="<?php echo get_permalink(); ?>" class="signup-payment-details-form f-form f-form-large" method="POST">
                                    <?php if($plan['more_magazines']): ?>
                                        <label class="single-magazine__select__label mag-count-label">
                                            <p>Please select how many copies of each edition you’d like to receive as part of your subscriptions</p>
                                            <?php if($plan['name'] == 'LBV Hub'): ?>
                                                <p>Your LBV hub subscription includes one print copy. Additional copies receive a 20% discount and are £2 per month per subscription</p>
                                            <?php endif; ?>
                                            <select
                                                name="add_more_magazine"
                                                class="single-magazine__select magazine-count-select"
                                                data-price-per-mag="<?php echo $plan['additional_price_per_magazine'] ?>"
                                                data-package-price="<?php echo $plan['price'] ?>"
                                            >
                                                <?php for($i = 1; $i <= $plan['more_magazines']; $i++) { ?>
                                                    <option value="<?php echo $i ?>" <?php echo ($i == $magazineCount && $selected ? 'selected' : '') ?>><?php echo $i ?></option>
                                                <?php } ?>
                                            </select>

                                            <span style="margin-left: 10px;margin-top: 0;">&pound;<span class="js-new-price"></span> a month</span>
                                        </label>
                                    <?php endif; ?>
                                    <input type="hidden" name="package" value="<?php echo $plan['id'] ?>">
                                    <input type="hidden" name="_nonce" value="<?php echo wp_create_nonce("confirm-package-action") ?>"/>
                                    <p><button type="submit" class="Plan__form__submit signup-payment-submit f-btn f-btn-form f-btn-primary">Proceed</button></p>
                                </form>
                            </div>
                        </div>


                    <?php 
                    /**
                     * step 3
                     *
                     * Adding all the client's details
                     */
                    elseif ($currentStep == 'details'): ?>

                        <div class="f-grid">
                            <div class="f-width-large-4-12 register">
                                <?php 
                                    try {
                                        view('register-form-content', array('packageId' => $session->getPackageId()));
                                        view('signup-benefits', ['plan' =>  $session->getPackage()]) ;
                                    }
                                    catch(Exception $e)
                                    {
                                        $_SESSION['notices']['error'][] = 'Your application has timed out.';
                                        wp_redirect( home_url('/account/register/')  );
                                        die;
                                    }
                                ?>
                                  <?php fat_get_partial('subscribe/testimonials') ?>
                            </div>

                            <div class="f-width-large-8-12">

                                <?php fat_get_partial( 'subscribe/form' ); ?>

                            </div>
                        </div>

                    <?php elseif($currentStep == 'finish'):
                        $subscriptionSessionContainer->remove('signup_finish')
                        ?>
                        <?php if($subscriptionSessionContainer->has('message')): ?>
                            <?php if($subscriptionSessionContainer->get('message') == 'success'): ?>

                                <div class="inner narrow">
                                    <?php if(is_array($subscribedWith) && $subscribedWith['name'] === User::agencyAccount): ?>
                                        <div class="f-alert f-alert-success">Thank you for signing up for an LBV agency account. You can log in below and access your account now.</div>

                                        <div class="notice success" >
                                            <p >Lancashire Business View will contact you directly on the details you’ve supplied to obtain your client account information for verification. Once this is complete you will be able to access your client accounts through your account.</p>
                                        </div>
                                    <?php else: ?>
                                        <p class="f-alert f-alert-success">Success. Your subscription has been set up. </p>
                                        <p>If you have ordered multiple subscriptions, please sign in to provide us with the correct details for each subscription. Once signed in, click on <i>Manage Subscription</i>.</p>
                                    <?php endif; ?>

                                    <?php fat_get_partial( 'content/page', 'login'); ?>
                                </div>

                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if($subscriptionSessionContainer->has('error')): ?>
                            <p class="Signup__message--error"><?php echo $subscriptionSessionContainer->get('error') ?></p>
                        <?php endif; ?>

                    <?php endif; ?>

            	</div>
            </div>
        </section>
    </div>

    <?php
    fat_get_partial( 'footer/newsletter' );
    ?>
</main>

<?php get_footer();