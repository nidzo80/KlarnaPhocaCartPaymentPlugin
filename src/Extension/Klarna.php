<?php
/**
 * @package    Plg_Pcp_Klarna
 * @author     Generated for Phoca Cart
 * @license    GNU General Public License version 3 or later
 */

namespace YourVendor\Plugin\Pcp\Klarna\Extension;

use Exception;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseAwareInterface;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Registry\Registry;
use YourVendor\Plugin\Pcp\Klarna\Helper\ShopHelper;
use YourVendor\Plugin\Pcp\Klarna\Helper\ApiHelper;

\defined('_JEXEC') or die;

/**
 * Klarna Hosted Payment Page plugin za Phoca Cart.
 *
 * Flow (RAZLIČIT od RaiAccept/Kombank - order se kreira NAKON plaćanja):
 * 1. onPCPbeforeProceedToPayment  - validacija pre plaćanja
 * 2. onPCPbeforeEmptyCartAfterOrder - ne prazni korpu odmah
 * 3. onPCPbeforeSetPaymentForm    - kreira KP sesiju + HPP sesiju, redirect na Klarna
 * 4. onPCPafterRecievePayment     - kupac se vratio (success/cancel/failure/error).
 *                                    Na success: placeOrder() sa authorization_token-om
 *                                    dobijenim kroz redirect URL placeholder.
 * 5. onPCPonPaymentWebhook        - status_update callback od Klarne (best-effort,
 *                                    finalni status se uvek verifikuje pozivom getOrder())
 *
 * @since  0.1.0
 */
final class Klarna extends CMSPlugin implements DatabaseAwareInterface
{
    use DatabaseAwareTrait;

    public function __construct($subject, array $config = [])
    {
        parent::__construct($subject, $config);

        if ($subject instanceof \Joomla\Event\DispatcherInterface) {
            $subject->addListener(
                'onPCPgetPaymentBranchInfoAdminList',
                [$this, 'onPCPgetPaymentBranchInfoAdminList']
            );
        }
    }

    /** @var bool */
    protected $autoloadLanguage = true;

    /** @var string */
    protected string $name = 'klarna';

    // -------------------------------------------------------------------------
    // Phoca Cart Event Handlers
    // -------------------------------------------------------------------------

    public function onPCPbeforeProceedToPayment(&$proceed, &$message, $eventData): bool
    {
        if (!$this->isMyPlugin($eventData)) {
            return true;
        }

        $proceed = 1;
        $errors  = $this->initCheck();

        if (!empty($errors)) {
            $this->getApplication()->enqueueMessage(implode('<br>', $errors), 'error');
            $proceed = 0;
        }

        return true;
    }

    public function onPCPbeforeEmptyCartAfterOrder(
        string &$proceed,
        array &$pluginData,
        Registry $componentParams,
        ?Registry $paymentParams,
        object $order,
        array $eventData = []
    ): bool {
        if (!$this->isMyPlugin($eventData)) {
            return false;
        }

        $pluginData['emptycart'] = false;

        return true;
    }

    /**
     * Kreira KP sesiju + HPP sesiju, redirect kupca na Klarna payment stranicu.
     *
     * @since 0.1.0
     */
    public function onPCPbeforeSetPaymentForm(
        string &$form,
        Registry $paramsC,
        Registry $params,
        array $order,
        array $eventData = []
    ): bool {
        if (!$this->isMyPlugin($eventData)) {
            return false;
        }

        $orderId   = (int) $order['common']->id;
        $paymentId = (isset($order['common']->payment_id) && (int) $order['common']->payment_id > 0)
            ? (int) $order['common']->payment_id
            : 0;

        if ($this->isOrderCharged($orderId)) {
            ShopHelper::redirectToCart(Text::_('PLG_PCP_KLARNA_ORDER_PROCESSED_ALREADY'), 'info');
            return true;
        }

        $amount    = ShopHelper::getOrderAmount($orderId);
        $orderNr   = \PhocacartOrder::getOrderNumber($orderId);
        $credentials = $this->getCredentials($paymentId);

        // Dijagnostički log - ne otkriva password, samo prefiks username-a i
        // resolved sandbox/region da lakše uhvatimo config gap (npr. sandbox
        // param se ne čita ispravno pa se gađa produkcijski host).
        // Napomena: purchase_country_fallback je config vrednost - stvarna
        // vrednost poslata Klarni zavisi od billing zemlje narudžbe (vidi log
        // niže, unutar buildOrderPayload toka).
        ShopHelper::addLog(1, 'Payment - Klarna - config', $orderId, sprintf(
            'sandbox=%s region=%s purchase_country_fallback=%s username_prefix=%s username_len=%d password_len=%d',
            $credentials['sandbox'] ? 'true' : 'false',
            $credentials['region'],
            $this->resolvePurchaseCountry($paymentId, ''),
            substr($credentials['username'], 0, 8),
            strlen($credentials['username']),
            strlen($credentials['password'])
        ));

        $apiHelper = new ApiHelper($credentials);

        try {
            $orderPayload = $this->buildOrderPayload($order, $orderId, $amount, $orderNr, $amount['rate']);

            $marketError = $this->validateMarket($orderPayload['purchase_country'], $orderPayload['purchase_currency']);
            if ($marketError !== null) {
                ShopHelper::addLog(2, 'Payment - Klarna - ERROR unsupported market', $orderId, $marketError);
                ShopHelper::redirectToCart($marketError);
                return true;
            }

            ShopHelper::addLog(1, 'Payment - Klarna - KP session payload', $orderId, sprintf(
                'purchase_country=%s purchase_currency=%s order_amount=%d locale=%s',
                $orderPayload['purchase_country'] ?? 'N/A',
                $orderPayload['purchase_currency'] ?? 'N/A',
                $orderPayload['order_amount'] ?? 0,
                $orderPayload['locale'] ?? 'N/A'
            ));

            // Korak 1: KP sesija
            $kpSession = $apiHelper->createKpSession($orderPayload);

            // Korak 2: HPP sesija - vezuje KP sesiju za redirect stranicu
            $merchantUrls = $this->buildMerchantUrls($orderId, $paymentId);
            $hppSession   = $apiHelper->createHppSession($kpSession['session_id'], $merchantUrls);

            // Čuvamo Klarna KP session ID + purchase_country/currency uz order -
            // ovi podaci se ponovo koriste u placeOrder() koraku, jer $common
            // objekat (getItemCommon) NE sadrži adresna polja kupca, pa se
            // purchase_country tamo ne može ponovo pouzdano izvesti iz baze.
            ShopHelper::saveInternalData($orderId, [
                'klarna_kp_session_id'  => $kpSession['session_id'],
                'klarna_hpp_session_id' => $hppSession['session_id'],
                'klarna_purchase_country' => $orderPayload['purchase_country'],
                'klarna_purchase_currency' => $orderPayload['purchase_currency'],
                'klarna_locale'          => $orderPayload['locale'],
            ], $this->name);

            $this->getApplication()->redirect($hppSession['redirect_url']);
        } catch (Exception $e) {
            ShopHelper::addLog(2, 'Payment - Klarna - ERROR', $orderId, $e->getMessage());
            ShopHelper::redirectToCart($e->getMessage());
        }

        return true;
    }

    /**
     * Kupac se vratio sa Klarna HPP stranice (success / cancel / back / failure / error).
     *
     * Na success URL-u Klarna prosleđuje {{authorization_token}} kao query parametar -
     * TEK OVDE se order zapravo kreira kod Klarne (placeOrder).
     *
     * @since 0.1.0
     */
    public function onPCPafterRecievePayment(int $mid, array &$message, array $eventData): void
    {
        if (!$this->isMyPlugin($eventData)) {
            return;
        }

        $input          = $this->getApplication()->getInput();
        $redirectStatus = $input->get('redirect_status', '', 'string');
        $orderId        = (int) $input->get('orderId', 0);
        $paymentId      = (int) $input->get('pid', 0);
        $authToken      = $input->get('authorization_token', '', 'string');
        $kpSessionId    = $input->get('kp_session_id', '', 'string');

        if ($redirectStatus !== 'success' || empty($authToken)) {
            // cancel / back / failure / error
            if (!empty($authToken)) {
                try {
                    $apiHelper = new ApiHelper($this->getCredentials($paymentId));
                    $apiHelper->cancelAuthorization($authToken);
                } catch (\Throwable $e) {
                    // best effort - authorization istice sama posle 60 min
                }
            }

            if ($orderId > 0 && $paymentId > 0) {
                $statuses = $this->getOrderStatuses($paymentId);
                $statusId = $redirectStatus === 'failure'
                    ? $statuses['failed']
                    : $statuses['canceled'];
                ShopHelper::setOrderStatus($orderId, $statusId, strtoupper($redirectStatus ?: 'CANCELED'));
            }

            $this->getApplication()->redirect(
                Uri::root()
                . 'index.php?option=com_phocacart&view=response&task=response.paymentcancel&type=klarna&tmpl=component'
            );
            return;
        }

        // SUCCESS: kupac je autorizovao plaćanje - sada kreiramo order kod Klarne.
        try {
            // Sigurnosna provera: da li authorization_token stvarno pripada OVOM
            // orderId-u? Bez ove provere, neko bi mogao promeniti orderId u URL-u
            // (npr. sa 103 na 104) i pokušati da svoj authorization_token iskoristi
            // za placeOrder() na tuđoj narudžbi. session_id iz {{session_id}}
            // placeholder-a je HPP session ID (ne KP session ID - to su dva
            // različita ID-a!), pa ga poredimo sa klarna_hpp_session_id koji smo
            // sačuvali kada je TAJ order kreirao svoju HPP sesiju.
            $storedData = ShopHelper::getPaymentData($orderId)[$this->name] ?? [];
            $storedHppSessionId = $storedData['klarna_hpp_session_id'] ?? '';

            if (empty($kpSessionId) || empty($storedHppSessionId) || !hash_equals($storedHppSessionId, $kpSessionId)) {
                ShopHelper::addLog(2, 'Payment - Klarna - SECURITY session_id mismatch', $orderId,
                    'Expected HPP session: ' . $storedHppSessionId . ' Got: ' . $kpSessionId);
                ShopHelper::redirectToCart(Text::_('PLG_PCP_KLARNA_ERROR_CONFIG'));
                return;
            }

            $amount    = ShopHelper::getOrderAmount($orderId);
            $orderNr   = \PhocacartOrder::getOrderNumber($orderId);
            $apiHelper = new ApiHelper($this->getCredentials($paymentId));
            $params    = ShopHelper::getPaymentMethod($paymentId)->params;
            $autoCapture = (bool) $params->get('auto_capture', 1);

            // Ponovo gradimo order payload - Klarna zahteva iste podatke i pri place order pozivu.
            // NAPOMENA: $order (form data iz checkout-a) ovde nije dostupan jer smo na povratnom
            // requestu - koristimo PhocacartOrderView da dohvatimo iste podatke iz baze.
            $orderPayload = $this->buildOrderPayloadFromDb($orderId, $paymentId, $amount, $orderNr, $amount['rate']);
            $orderPayload['auto_capture'] = $autoCapture;

            $result  = $apiHelper->placeOrder($authToken, $orderPayload);
            $klarnaOrderId = $result['order_id'];

            ShopHelper::saveInternalData($orderId, [
                'klarna_order_id'    => $klarnaOrderId,
                'klarna_fraud_status' => $result['fraud_status'] ?? null,
            ], $this->name);

            $statuses = $this->getOrderStatuses($paymentId);

            // fraud_status: ACCEPTED (odmah potvrđeno), PENDING (Klarna još odlučuje - retko za HPP/EU)
            $fraudStatus = $result['fraud_status'] ?? 'ACCEPTED';
            $statusId    = $fraudStatus === 'ACCEPTED' ? $statuses['completed'] : $statuses['canceled'];
            $statusLabel = $fraudStatus === 'ACCEPTED' ? 'COMPLETED' : strtoupper($fraudStatus);

            ShopHelper::setOrderStatus($orderId, $statusId, $statusLabel);
            ShopHelper::addLog(1, 'Payment - Klarna - SUCCESS', $orderId,
                'Order placed. Klarna order_id: ' . $klarnaOrderId . ' fraud_status: ' . $fraudStatus);

            ShopHelper::emptyCart();
        } catch (Exception $e) {
            ShopHelper::addLog(2, 'Payment - Klarna - ERROR placeOrder', $orderId, $e->getMessage());
            ShopHelper::redirectToCart($e->getMessage());
            return;
        }
    }

    /**
     * status_update callback od Klarne (HPP session status promena).
     * Best-effort - finalni status se uvek verifikuje direktnim pozivom Klarna API-ja,
     * nikad se ne veruje slepo payload-u (isti princip kao RaiAccept webhook).
     *
     * @since 0.1.0
     */
    public function onPCPonPaymentWebhook(int $pid, array $eventData): void
    {
        http_response_code(200);

        if (!$this->isMyPlugin($eventData)) {
            return;
        }

        $payload = @file_get_contents('php://input');
        $payload = ($payload !== false) ? $payload : '';

        if (empty($payload)) {
            ShopHelper::addLog(2, 'Payment - Klarna - ERROR webhook', 0, 'Empty payload');
            return;
        }

        $data = json_decode($payload, true);

        // Potvrđena struktura status_update callback-a (iz playground testa 10.08.2026):
        // {"event_id":"...", "session":{"session_id":"...", "status":"...", "updated_at":"...", "expires_at":"..."}}
        $sessionStatus = $data['session']['status'] ?? null;
        $kpSessionId   = $data['session']['session_id'] ?? null;

        ShopHelper::addLog(1, 'Payment - Klarna - webhook received', 0,
            'status=' . $sessionStatus . ' session_id=' . $kpSessionId);

        // NAPOMENA: KP session_id iz webhook-a nije isto što i Klarna order_id
        // (order_id postoji tek nakon placeOrder poziva na success redirectu).
        // Za sada je ovo samo informativni log; status_update stiže i prije nego
        // je order uopšte kreiran (npr. "IN_PROGRESS" dok kupac popunjava formu),
        // pa se ovdje NE mijenja status ordera - to i dalje isključivo radi
        // onPCPafterRecievePayment nakon uspešnog placeOrder() poziva.
        // Ako zatreba dodatna otpornost (npr. kupac zatvori browser prije redirecta
        // nazad na sajt), ovdje bi trebalo po $kpSessionId pronaći orderId preko
        // saveInternalData('klarna_kp_session_id') zapisa i, ako je status "COMPLETED"
        // a mi ga nismo obradili, ručno pozvati placeOrder tok.
    }

    // -------------------------------------------------------------------------
    // Private methods
    // -------------------------------------------------------------------------

    /**
     * Gradi merchant_urls za HPP sesiju.
     *
     * @since 0.1.0
     */
    private function buildMerchantUrls(int $orderId, int $paymentId): array
    {
        $baseUrl = Uri::root();
        $base    = $baseUrl . 'index.php?option=com_phocacart&view=response&task=response.paymentrecieve'
            . '&type=klarna&tmpl=component&orderId=' . $orderId . '&pid=' . $paymentId;

        return [
            'success'       => $base . '&redirect_status=success&authorization_token={{authorization_token}}&kp_session_id={{session_id}}',
            'cancel'        => $base . '&redirect_status=cancel',
            'back'          => $base . '&redirect_status=cancel',
            'failure'       => $base . '&redirect_status=failure',
            'error'         => $base . '&redirect_status=failure',
            'status_update' => $baseUrl
                . 'index.php?option=com_phocacart&view=response&task=response.paymentwebhook&type=klarna&pid=' . $paymentId,
        ];
    }

    /**
     * Gradi order payload (KP session format) iz checkout $order strukture.
     *
     * @since 0.1.0
     */
    private function buildOrderPayload(array $order, int $orderId, array $amount, string $orderNr, float $rate = 1.0): array
    {
        $b = $order['bas']['b'] ?? [];
        $s = $order['bas']['s'] ?? $b;

        $billingFirst   = trim($b['name_first'] ?? $b['firstname'] ?? '');
        $billingLast    = trim($b['name_last']  ?? $b['lastname']  ?? '');
        $billingStreet  = trim(($b['address_1'] ?? $b['address'] ?? '') . ' ' . ($b['address_2'] ?? $b['address2'] ?? ''));
        $billingCity    = trim($b['city']    ?? '');
        $billingZip     = trim($b['zip']     ?? $b['postal_code'] ?? '');
        $billingCountry = strtoupper(trim($b['countrycode'] ?? $b['country_code_2'] ?? $b['country_code'] ?? ''));
        $email          = trim($b['email']   ?? '');
        $phone          = trim($b['phone_1'] ?? $b['phone_mobile'] ?? $b['phone'] ?? '');

        $shippingFirst   = trim($s['name_first'] ?? $s['firstname'] ?? '') ?: $billingFirst;
        $shippingLast    = trim($s['name_last']  ?? $s['lastname']  ?? '') ?: $billingLast;
        $shippingStreet  = trim(($s['address_1'] ?? $s['address'] ?? '') . ' ' . ($s['address_2'] ?? $s['address2'] ?? '')) ?: $billingStreet;
        $shippingCity    = trim($s['city']    ?? '') ?: $billingCity;
        $shippingZip     = trim($s['zip']     ?? $s['postal_code'] ?? '') ?: $billingZip;
        $shippingCountry = strtoupper(trim($s['countrycode'] ?? $s['country_code_2'] ?? $s['country_code'] ?? '')) ?: $billingCountry;

        $paymentId = (int) ($order['common']->payment_id ?? 0);

        return $this->assembleOrderPayload(
            $amount,
            $orderNr,
            $rate,
            $order['products'] ?? [],
            compact('billingFirst', 'billingLast', 'billingStreet', 'billingCity', 'billingZip', 'billingCountry', 'email', 'phone'),
            compact('shippingFirst', 'shippingLast', 'shippingStreet', 'shippingCity', 'shippingZip', 'shippingCountry'),
            $this->resolvePurchaseCountry($paymentId, $billingCountry)
        );
    }

    /**
     * Gradi order payload za placeOrder() poziv (koristi se na povratnom requestu
     * gde $order checkout forma više nije dostupna).
     *
     * NAPOMENA: $common objekat (getItemCommon) NE sadrži adresna polja kupca
     * (potvrđeno testom 10.08.2026) - samo order-level metapodatke (status, valuta,
     * brojevi računa...). purchase_country/currency/locale zato čitamo iz podataka
     * sačuvanih u onPCPbeforeSetPaymentForm koraku (ista vrednost koja je već
     * uspešno poslata Klarni pri kreiranju KP sesije), umesto da ih ponovo
     * pogrešno izvodimo iz baze. Billing/shipping adresu ne šaljemo u ovom pozivu -
     * Klarna je već prikupila te podatke tokom same HPP sesije i ne zahteva da se
     * ponovo prosleđuju uz authorization_token (potvrđeno: placeOrder uspeva i bez
     * njih, fraud_status=ACCEPTED).
     *
     * @since 0.1.0
     */
    private function buildOrderPayloadFromDb(int $orderId, int $paymentId, array $amount, string $orderNr, float $rate = 1.0): array
    {
        $orderView = new \PhocacartOrderView;
        $products  = $orderView->getItemProducts($orderId);

        $paymentData = ShopHelper::getPaymentData($orderId)[$this->name] ?? [];

        $purchaseCountry = strtoupper((string) ($paymentData['klarna_purchase_country'] ?? ''));

        if (!preg_match('/^[A-Z]{2}$/', $purchaseCountry)) {
            // Nije trebalo da se desi (uvek se čuva u prethodnom koraku), ali za
            // svaki slučaj koristimo config fallback umesto da pukne.
            $purchaseCountry = $this->resolvePurchaseCountry($paymentId, '');
        }

        $billing = [
            'billingFirst' => '', 'billingLast' => '', 'billingStreet' => '',
            'billingCity' => '', 'billingZip' => '', 'billingCountry' => $purchaseCountry,
            'email' => '', 'phone' => '',
        ];
        $shipping = [
            'shippingFirst' => '', 'shippingLast' => '', 'shippingStreet' => '',
            'shippingCity' => '', 'shippingZip' => '', 'shippingCountry' => $purchaseCountry,
        ];

        return $this->assembleOrderPayload($amount, $orderNr, $rate, $products, $billing, $shipping, $purchaseCountry);
    }

    /**
     * Zajednička logika za sastavljanje Klarna order payload-a (order_lines, amounts, adrese).
     *
     * @since 0.1.0
     */
    private function assembleOrderPayload(
        array $amount,
        string $orderNr,
        float $rate,
        iterable $products,
        array $billing,
        array $shipping,
        string $purchaseCountry
    ): array {
        $currency = strtoupper($amount['currency']);

        // Klarna traži iznose u NAJMANJOJ jedinici valute (npr. öre za SEK: 100.00 SEK -> 10000)
        $orderLines = [];
        foreach ($products as $product) {
            $product = (object) $product;
            $dbrutto = (float) ($product->dbrutto ?? 0);
            $brutto  = (float) ($product->brutto  ?? 0);
            $unitPrice = round(($dbrutto > 0 ? $dbrutto : $brutto) * $rate, 2);
            $qty   = (int) ($product->quantity ?? $product->qty ?? 1);
            $title = (string) ($product->title ?? $product->name ?? 'Product');

            $orderLines[] = [
                'name'               => $title,
                'quantity'           => $qty,
                'unit_price'         => (int) round($unitPrice * 100),
                'total_amount'       => (int) round($unitPrice * $qty * 100),
                // Ako Phoca Cart nema odvojen PDV po stavci, ostavljamo 0 - uskladiti sa
                // klijentovim poreskim podešavanjem pre produkcije.
                'tax_rate'           => 0,
                'total_tax_amount'   => 0,
            ];
        }

        if (empty($orderLines)) {
            $orderLines[] = [
                'name'         => 'Order ' . $orderNr,
                'quantity'     => 1,
                'unit_price'   => (int) round((float) $amount['total'] * 100),
                'total_amount' => (int) round((float) $amount['total'] * 100),
                'tax_rate'     => 0,
                'total_tax_amount' => 0,
            ];
        } else {
            // Klarna zahtijeva da zbir order_lines + order_tax_amount TAČNO
            // odgovara order_amount. Product linije pokrivaju samo cijenu
            // proizvoda - razlika (shipping, popusti, naknade...) se dodaje
            // kao jedna generička korekciona linija umjesto da se pokušava
            // pogoditi tačna Phoca Cart order_total šema za svaku pojedinačnu
            // stavku (shipping/coupon/fee redovi).
            $orderAmountMinor = (int) round((float) $amount['total'] * 100);
            $lineSumMinor      = array_sum(array_column($orderLines, 'total_amount'));
            $diff              = $orderAmountMinor - $lineSumMinor;

            if ($diff !== 0) {
                $orderLines[] = [
                    'name'             => 'Shipping & Handling',
                    'quantity'         => 1,
                    'unit_price'       => $diff,
                    'total_amount'     => $diff,
                    'tax_rate'         => 0,
                    'total_tax_amount' => 0,
                ];
            }
        }

        $payload = [
            'purchase_country'  => $purchaseCountry,
            'purchase_currency' => $currency,
            'locale'            => $this->localeForCountry($purchaseCountry),
            'order_amount'      => (int) round((float) $amount['total'] * 100),
            'order_tax_amount'  => 0,
            'order_lines'       => $orderLines,
            'merchant_reference1' => (string) $orderNr,
        ];

        $billingAddress = array_filter([
            'given_name'   => $billing['billingFirst'],
            'family_name'  => $billing['billingLast'],
            'email'        => $billing['email'],
            'phone'        => $billing['phone'],
            'street_address' => $billing['billingStreet'],
            'postal_code'  => $billing['billingZip'],
            'city'         => $billing['billingCity'],
            'country'      => $billing['billingCountry'],
        ]);

        $shippingAddress = array_filter([
            'given_name'   => $shipping['shippingFirst'],
            'family_name'  => $shipping['shippingLast'],
            'street_address' => $shipping['shippingStreet'],
            'postal_code'  => $shipping['shippingZip'],
            'city'         => $shipping['shippingCity'],
            'country'      => $shipping['shippingCountry'],
        ]);

        if (!empty($billingAddress))  { $payload['billing_address']  = $billingAddress; }
        if (!empty($shippingAddress)) { $payload['shipping_address'] = $shippingAddress; }

        return $payload;
    }

    /**
     * Zvanična Klarna mapa: purchase_country => [currency, default_locale].
     * Izvor: https://docs.klarna.com/acquirer/klarna/get-started/data-requirements/puchase-countries-currencies-locales/
     * (potvrđeno 11.08.2026). Klarna zahtijeva TAČNO poklapanje currency za dato
     * purchase_country - nema izuzetaka (npr. HU mora biti HUF, ne EUR).
     *
     * NAPOMENA: svaka zemlja mora biti pojedinačno ugovorno aktivirana na
     * Klarna merchant nalogu (Merchant Portal → Settings → Markets) prije nego
     * što je moguće nuditi je kupcima - i sama tehnička ispravnost
     * country+currency kombinacije nije dovoljna ako nalog nije provizionisan
     * za to tržište.
     *
     * @since 0.1.0
     */
    private const KLARNA_MARKETS = [
        'AU' => ['AUD', 'en-AU'],
        'AT' => ['EUR', 'de-AT'],
        'BE' => ['EUR', 'nl-BE'],
        'CA' => ['CAD', 'en-CA'],
        'CZ' => ['CZK', 'cs-CZ'],
        'DK' => ['DKK', 'da-DK'],
        'FI' => ['EUR', 'fi-FI'],
        'FR' => ['EUR', 'fr-FR'],
        'DE' => ['EUR', 'de-DE'],
        'GR' => ['EUR', 'el-GR'],
        'HU' => ['HUF', 'hu-HU'],
        'IE' => ['EUR', 'en-IE'],
        'IT' => ['EUR', 'it-IT'],
        'MX' => ['MXN', 'es-MX'],
        'NL' => ['EUR', 'nl-NL'],
        'NZ' => ['NZD', 'en-NZ'],
        'NO' => ['NOK', 'nb-NO'],
        'PL' => ['PLN', 'pl-PL'],
        'PT' => ['EUR', 'pt-PT'],
        'RO' => ['RON', 'ro-RO'],
        'SK' => ['EUR', 'sk-SK'],
        'ES' => ['EUR', 'es-ES'],
        'SE' => ['SEK', 'sv-SE'],
        'CH' => ['CHF', 'de-CH'],
        'GB' => ['GBP', 'en-GB'],
        'US' => ['USD', 'en-US'],
    ];

    /**
     * Provjerava da li je purchase_country validno Klarna tržište, i da li se
     * currency poklapa sa jedinom valutom koju to tržište podržava.
     *
     * @return string|null  Poruka o grešci, ili null ako je kombinacija validna.
     * @since  0.1.0
     */
    private function validateMarket(string $purchaseCountry, string $currency): ?string
    {
        $purchaseCountry = strtoupper($purchaseCountry);
        $currency        = strtoupper($currency);

        if (!isset(self::KLARNA_MARKETS[$purchaseCountry])) {
            return sprintf(
                'Klarna does not support the country "%s". Supported: %s.',
                $purchaseCountry,
                implode(', ', array_keys(self::KLARNA_MARKETS))
            );
        }

        [$requiredCurrency] = self::KLARNA_MARKETS[$purchaseCountry];

        if ($currency !== $requiredCurrency) {
            return sprintf(
                'Klarna requires %s for %s, but this order is in %s. '
                . 'Klarna only accepts each country\'s local currency - no exceptions.',
                $requiredCurrency,
                $purchaseCountry,
                $currency
            );
        }

        return null;
    }

    /**
     * Locale za dato purchase_country (Klarna zahteva format npr. "sv-SE", "en-GB").
     * Koristi zvaničnu Klarna mapu (KLARNA_MARKETS) umesto ručno održavane liste.
     *
     * @since 0.1.0
     */
    private function localeForCountry(string $countryIso2): string
    {
        $countryIso2 = strtoupper($countryIso2);

        return self::KLARNA_MARKETS[$countryIso2][1] ?? 'en-GB';
    }

    /**
     * Proverava da li je order već naplaćen.
     *
     * @since 0.1.0
     */
    private function isOrderCharged(int $orderId): bool
    {
        $paymentData  = ShopHelper::getPaymentData($orderId);
        $internalData = $paymentData[$this->name] ?? null;

        return !empty($internalData['klarna_order_id']);
    }

    /**
     * Dohvata credentials iz plugin parametara.
     *
     * @since 0.1.0
     */
    private function getCredentials(int $pid): array
    {
        $params = ShopHelper::getPaymentMethod($pid)->params;

        return [
            'sandbox'  => (bool) $params->get('sandbox', 1),
            'region'   => (string) $params->get('region', 'eu'),
            'username' => trim($params->get('api_username', '')),
            'password' => trim($params->get('api_password', '')),
        ];
    }

    /**
     * Određuje purchase_country: prioritet ima stvarna zemlja narudžbe
     * (billing country iz checkout-a), a config polje služi samo kao fallback
     * kad billing country nije poznat/validan.
     *
     * VAŽNO: purchase_country + purchase_currency MORAJU biti validna Klarna
     * tržišna kombinacija (npr. SE+SEK, DE+EUR - ali NE SE+EUR). Ako narudžba
     * ima valutu koja ne odgovara toj zemlji, Klarna vraća generičku poruku
     * "not available for this region or currency" bez preciznijeg error koda.
     *
     * Takođe: merchant test/produkcijski nalog mora biti aktivan (contracted)
     * za tu konkretnu zemlju kod Klarne - i ako je kombinacija tehnički
     * validna, dobićeš istu poruku ako account nije provisioned za tu regiju.
     *
     * @since 0.1.0
     */
    private function resolvePurchaseCountry(int $pid, string $billingCountry): string
    {
        $billingCountry = strtoupper(trim($billingCountry));

        if (preg_match('/^[A-Z]{2}$/', $billingCountry)) {
            return $billingCountry;
        }

        try {
            $params = ShopHelper::getPaymentMethod($pid)->params;
        } catch (\Throwable $e) {
            $params = $this->params;
        }

        return strtoupper((string) $params->get('purchase_country', 'SE'));
    }

    /**
     * Dohvata statuse ordera iz plugin parametara.
     *
     * @since 0.1.0
     */
    private function getOrderStatuses(int $pid): array
    {
        try {
            $params = ShopHelper::getPaymentMethod($pid)->params;
        } catch (\Throwable $e) {
            $params = $this->params;
        }

        return [
            'completed'     => (int) $params->get('status_completed', 6),
            'failed'        => (int) $params->get('status_failed', 7),
            'canceled'      => (int) $params->get('status_canceled', 3),
            'refunded'      => (int) $params->get('status_refunded', 5),
            'part_refunded' => (int) $params->get('status_part_refunded', 5),
        ];
    }

    /**
     * Proverava minimalne uslove za rad plugina.
     *
     * @since 0.1.0
     */
    private function initCheck(): array
    {
        return [];
    }

    /**
     * Prikazuje Klarna order info + refund panel u admin listi orderova.
     *
     * @since 0.1.0
     */
    public function onPCPgetPaymentBranchInfoAdminList(\Joomla\Event\Event $event): void
    {
        $item      = $event->getArgument('order');
        $eventData = $event->getArgument('eventData', []);

        if (!$this->isMyPlugin($eventData)) {
            return;
        }

        $paymentData   = !empty($item->params_payment) ? json_decode($item->params_payment, true) : [];
        $klarnaData    = $paymentData['klarna'] ?? [];
        $klarnaOrderId = $klarnaData['klarna_order_id'] ?? '';

        if (empty($klarnaOrderId)) {
            return;
        }

        $currency        = $item->currency_code ?? 'EUR';
        $rate            = (float) ($item->currency_exchange_rate ?? 1);
        $totalAmountRaw  = (float) ($item->total_amount_currency ?? 0);
        $totalAmount     = $totalAmountRaw > 0 ? $totalAmountRaw : round((float) ($item->total_amount ?? 0) * $rate, 2);
        $alreadyRefunded = (float) ($klarnaData['klarna_refunded_amount'] ?? 0);
        $availableAmount = round($totalAmount - $alreadyRefunded, 2);

        $orderId   = (int) $item->id;
        $paymentId = (int) ($item->payment_id ?? 0);

        $header = '<div class="small mt-1">Klarna order: <code>' . htmlspecialchars($klarnaOrderId) . '</code></div>';

        if ($availableAmount <= 0) {
            $event->setArgument('result', [[
                'content' => $header . '<div class="badge text-bg-success mt-1">Fully Refunded</div>',
            ]]);
            return;
        }

        $ajaxUrl       = Uri::root() . 'administrator/index.php?option=com_ajax&plugin=klarna&group=pcp&format=json&task=refund';
        $ajaxCancelUrl = Uri::root() . 'administrator/index.php?option=com_ajax&plugin=klarna&group=pcp&format=json&task=cancel';
        $csrfToken = \Joomla\CMS\Session\Session::getFormToken();

        $content = $header
            . '<div class="mt-1" id="klarna-panel-' . $orderId . '">'
            . '<div class="input-group input-group-sm">'
            . '<input type="number" class="form-control form-control-sm" id="klarna-amount-' . $orderId . '"'
            . ' value="' . $availableAmount . '" min="0.01" max="' . $availableAmount . '" step="0.01" style="max-width:90px">'
            . '<span class="input-group-text">' . htmlspecialchars($currency) . '</span>'
            . '<button type="button" class="btn btn-warning btn-sm" onclick="klarnaRefund_' . $orderId . '(event)">'
            . '&#8617; Refund</button>'
            . '</div>'
            . '<button type="button" class="btn btn-outline-danger btn-sm mt-1" onclick="klarnaCancel_' . $orderId . '(event)">'
            . '&#10005; Cancel order (only if Uncaptured)</button>'
            . '<div id="klarna-msg-' . $orderId . '" class="small mt-1"></div>'
            . '</div>'
            . '<script>'
            . 'function klarnaRefund_' . $orderId . '(e){'
            . 'e.preventDefault();e.stopPropagation();'
            . 'var a=parseFloat(document.getElementById("klarna-amount-' . $orderId . '").value);'
            . 'if(isNaN(a)||a<=0||a>' . $availableAmount . '+0.001){'
            . 'document.getElementById("klarna-msg-' . $orderId . '").innerHTML="<span class=\'text-danger\'>Invalid amount</span>";return false;}'
            . 'if(!window.confirm("Refund "+a.toFixed(2)+" ' . $currency . ' for order #' . $orderId . '?"))return false;'
            . 'var btn=e.target;btn.disabled=true;btn.innerHTML="...";'
            . 'window.fetch("' . $ajaxUrl . '",{'
            . 'method:"POST",'
            . 'headers:{"Content-Type":"application/json","X-Requested-With":"XMLHttpRequest"},'
            . 'body:JSON.stringify({'
            . 'orderId:' . $orderId . ','
            . 'paymentId:' . $paymentId . ','
            . 'klarnaOrderId:"' . $klarnaOrderId . '",'
            . 'amount:a,'
            . '"' . $csrfToken . '":1'
            . '})})'
            . '.then(function(r){return r.json();})'
            . '.then(function(d){'
            . 'btn.disabled=false;btn.innerHTML="&#8617; Refund";'
            . 'var msg=document.getElementById("klarna-msg-' . $orderId . '");'
            . 'if(d.success){msg.innerHTML="<span class=\'text-success\'>"+d.message+"</span>";'
            . 'if(!d.partial){document.getElementById("klarna-panel-' . $orderId . '").innerHTML="<span class=\'badge text-bg-warning\'>Refunded</span>";}}'
            . 'else{msg.innerHTML="<span class=\'text-danger\'>"+(d.message||"Error")+"</span>";}'
            . '})'
            . '.catch(function(){'
            . 'btn.disabled=false;btn.innerHTML="&#8617; Refund";'
            . 'document.getElementById("klarna-msg-' . $orderId . '").innerHTML="<span class=\'text-danger\'>Network error</span>";'
            . '});return false;}'
            . 'function klarnaCancel_' . $orderId . '(e){'
            . 'e.preventDefault();e.stopPropagation();'
            . 'if(!window.confirm("Cancel Klarna order #' . $orderId . '? Only works if still Uncaptured."))return false;'
            . 'var btn=e.target;btn.disabled=true;btn.innerHTML="...";'
            . 'window.fetch("' . $ajaxCancelUrl . '",{'
            . 'method:"POST",'
            . 'headers:{"Content-Type":"application/json","X-Requested-With":"XMLHttpRequest"},'
            . 'body:JSON.stringify({'
            . 'orderId:' . $orderId . ','
            . 'paymentId:' . $paymentId . ','
            . 'klarnaOrderId:"' . $klarnaOrderId . '",'
            . '"' . $csrfToken . '":1'
            . '})})'
            . '.then(function(r){return r.json();})'
            . '.then(function(d){'
            . 'btn.disabled=false;btn.innerHTML="&#10005; Cancel order (only if Uncaptured)";'
            . 'var msg=document.getElementById("klarna-msg-' . $orderId . '");'
            . 'if(d.success){msg.innerHTML="<span class=\'text-success\'>"+d.message+"</span>";'
            . 'document.getElementById("klarna-panel-' . $orderId . '").innerHTML="<span class=\'badge text-bg-secondary\'>Cancelled</span>";}'
            . 'else{msg.innerHTML="<span class=\'text-danger\'>"+(d.message||"Error")+"</span>";}'
            . '})'
            . '.catch(function(){'
            . 'btn.disabled=false;btn.innerHTML="&#10005; Cancel order (only if Uncaptured)";'
            . 'document.getElementById("klarna-msg-' . $orderId . '").innerHTML="<span class=\'text-danger\'>Network error</span>";'
            . '});return false;}'
            . '</script>';

        $event->setArgument('result', [['content' => $content]]);
    }

    /**
     * Ajax handler za refund iz admin liste orderova.
     * URL: administrator/index.php?option=com_ajax&plugin=klarna&group=pcp&format=json&task=refund
     *
     * @since 0.1.0
     */
    public function onAjaxKlarna(): array
    {
        // Učitavamo Phoca Cart bootstrap jer nije dostupan u Ajax kontekstu
        $bootstrapPath = JPATH_ADMINISTRATOR . '/components/com_phocacart/libraries/bootstrap.php';
        if (file_exists($bootstrapPath)) {
            require_once $bootstrapPath;
        }

        $app  = $this->getApplication();
        $user = $app->getIdentity();

        if (!$user->authorise('core.edit', 'com_phocacart')) {
            return ['success' => false, 'message' => 'Unauthorized'];
        }

        $rawBody   = @file_get_contents('php://input');
        $body      = json_decode($rawBody ?: '', true) ?? [];
        $csrfToken = \Joomla\CMS\Session\Session::getFormToken();

        if (empty($body[$csrfToken])) {
            return ['success' => false, 'message' => 'Invalid token'];
        }

        $task          = $app->getInput()->getCmd('task', 'refund');
        $orderId       = (int)   ($body['orderId']       ?? 0);
        $paymentId     = (int)   ($body['paymentId']     ?? 0);
        $klarnaOrderId = trim(   ($body['klarnaOrderId'] ?? ''));

        if ($task === 'cancel') {
            return $this->handleAjaxCancel($orderId, $paymentId, $klarnaOrderId);
        }

        $amount = (float) ($body['amount'] ?? 0);

        if ($orderId < 1 || empty($klarnaOrderId) || $amount <= 0) {
            return ['success' => false, 'message' => 'Invalid parameters'];
        }

        try {
            $credentials = $this->getCredentialsFromDb($paymentId);

            $db = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);

            // Provjera da order pripada ovom payment ID-u (sprječava IDOR)
            $orderPaymentId = (int) $db->setQuery(
                $db->getQuery(true)
                   ->select('payment_id')
                   ->from('#__phocacart_orders')
                   ->where('id = ' . $orderId)
            )->loadResult();

            if ($orderPaymentId !== $paymentId) {
                return ['success' => false, 'message' => 'Order/payment mismatch'];
            }

            $currency = (string) $db->setQuery(
                $db->getQuery(true)
                   ->select('currency_code')
                   ->from('#__phocacart_orders')
                   ->where('id = ' . $orderId)
            )->loadResult();

            $totalAmount = (float) $db->setQuery(
                $db->getQuery(true)
                   ->select('t.amount_currency')
                   ->from('#__phocacart_order_total AS t')
                   ->where('t.order_id = ' . $orderId)
                   ->where('t.type = ' . $db->quote('brutto'))
            )->loadResult();

            if ($totalAmount <= 0) {
                $totalAmount = (float) $db->setQuery(
                    $db->getQuery(true)
                       ->select('t.amount')
                       ->from('#__phocacart_order_total AS t')
                       ->where('t.order_id = ' . $orderId)
                       ->where('t.type = ' . $db->quote('brutto'))
                )->loadResult();
            }

            $paymentDataCheck  = ShopHelper::getPaymentData($orderId);
            $klarnaDataCheck   = $paymentDataCheck[$this->name] ?? [];
            $alreadyRefunded   = (float) ($klarnaDataCheck['klarna_refunded_amount'] ?? 0);
            $maxRefundable     = round($totalAmount - $alreadyRefunded, 2);

            if ($amount > $maxRefundable + 0.01) {
                return ['success' => false, 'message' => 'Refund amount exceeds available amount (' . $maxRefundable . ' ' . $currency . ')'];
            }

            $apiHelper = new ApiHelper($credentials);
            $apiHelper->refundOrder($klarnaOrderId, $amount, 'Refund for order #' . $orderId);

            $newRefunded = round($alreadyRefunded + $amount, 2);

            ShopHelper::saveInternalData($orderId, [
                'klarna_refunded_amount' => $newRefunded,
            ], $this->name);

            $statuses    = $this->getOrderStatuses($paymentId);
            $isPartial   = $newRefunded < $totalAmount;
            $statusId    = $isPartial ? $statuses['part_refunded'] : $statuses['refunded'];
            $statusLabel = $isPartial ? 'PARTIALLY REFUNDED' : 'REFUNDED';

            ShopHelper::setOrderStatus($orderId, $statusId, $statusLabel);

            ShopHelper::addLog(1, 'Payment - Klarna - REFUND', $orderId,
                'Admin refund: ' . $amount . ' ' . $currency . ' | Klarna order: ' . $klarnaOrderId);

            return [
                'success' => true,
                'partial' => $isPartial,
                'message' => 'Refund of ' . number_format($amount, 2) . ' ' . $currency . ' successful.',
            ];
        } catch (\Throwable $e) {
            ShopHelper::addLog(2, 'Payment - Klarna - ERROR refund', $orderId, $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Obrada 'cancel' taska - otkazuje order kod Klarne (samo dok je Uncaptured).
     *
     * @since 0.1.0
     */
    private function handleAjaxCancel(int $orderId, int $paymentId, string $klarnaOrderId): array
    {
        if ($orderId < 1 || empty($klarnaOrderId)) {
            return ['success' => false, 'message' => 'Invalid parameters'];
        }

        try {
            $db = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);

            // Provjera da order pripada ovom payment ID-u (sprječava IDOR)
            $orderPaymentId = (int) $db->setQuery(
                $db->getQuery(true)
                   ->select('payment_id')
                   ->from('#__phocacart_orders')
                   ->where('id = ' . $orderId)
            )->loadResult();

            if ($orderPaymentId !== $paymentId) {
                return ['success' => false, 'message' => 'Order/payment mismatch'];
            }

            $credentials = $this->getCredentialsFromDb($paymentId);
            $apiHelper   = new ApiHelper($credentials);
            $apiHelper->cancelOrder($klarnaOrderId);

            $statuses = $this->getOrderStatuses($paymentId);
            ShopHelper::setOrderStatus($orderId, $statuses['canceled'], 'CANCELED');

            ShopHelper::addLog(1, 'Payment - Klarna - ORDER CANCELED', $orderId,
                'Admin cancel | Klarna order: ' . $klarnaOrderId);

            return ['success' => true, 'message' => 'Order cancelled at Klarna.'];
        } catch (\Throwable $e) {
            // Najčešći uzrok neuspjeha: order je već (delimično) kapturisan -
            // Klarna tada odbija cancel i traži refund umjesto toga.
            ShopHelper::addLog(2, 'Payment - Klarna - ERROR cancel', $orderId, $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage() . ' (Is the order already captured? Use Refund instead.)'];
        }
    }

    /**
     * Dohvata Klarna kredencijale direktno iz baze (Ajax kontekst nema pristup
     * standardnom Phoca Cart payment method objektu na isti način kao front-end).
     *
     * @since 0.1.0
     */
    private function getCredentialsFromDb(int $pid): array
    {
        $db     = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
        $params = $db->setQuery(
            $db->getQuery(true)
               ->select('params')
               ->from('#__phocacart_payment_methods')
               ->where('id = ' . (int) $pid)
        )->loadResult();

        if (empty($params)) {
            throw new \RuntimeException('Klarna payment method params not found for pid=' . $pid);
        }

        $registry = new Registry($params);

        return [
            'sandbox'  => (bool) $registry->get('sandbox', 1),
            'region'   => (string) $registry->get('region', 'eu'),
            'username' => trim($registry->get('api_username', '')),
            'password' => trim($registry->get('api_password', '')),
        ];
    }

    /**
     * Provera da li je event za ovaj plugin.
     *
     * @since 0.1.0
     */
    private function isMyPlugin(array $eventData): bool
    {
        if (!isset($eventData['pluginname']) || $eventData['pluginname'] != $this->name) {
            return false;
        }
        return true;
    }
}
