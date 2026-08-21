<?php
/**
 * @package    Plg_Pcp_Klarna
 * @license    GNU General Public License version 3 or later
 */

namespace YourVendor\Plugin\Pcp\Klarna\Helper;

use RuntimeException;

\defined('_JEXEC') or die;

/**
 * Klarna API komunikacija (Hosted Payment Page flow).
 *
 * Implementira Klarna HPP tok:
 * 1. createKpSession()   → POST {base}/payments/v1/sessions
 *                           kreira Klarna Payments (KP) sesiju
 * 2. createHppSession()  → POST {base}/hpp/v1/sessions
 *                           kreira Hosted Payment Page sesiju vezanu za KP sesiju,
 *                           vraća redirect_url na koji se kupac šalje
 * 3. placeOrder()        → POST {base}/payments/v1/authorizations/{authToken}/order
 *                           poziva se TEK NAKON što kupac plati (authorization_token
 *                           stiže kroz success redirect placeholder {{authorization_token}})
 * 4. getOrder()          → GET  {base}/ordermanagement/v1/orders/{order_id}
 * 5. captureOrder()      → POST {base}/ordermanagement/v1/orders/{order_id}/captures
 * 6. refundOrder()       → POST {base}/ordermanagement/v1/orders/{order_id}/refunds
 * 7. cancelAuthorization()→ DELETE {base}/payments/v1/authorizations/{authToken}
 *
 * VAŽNO: Base URL i endpoint strukture treba potvrditi protiv aktuelne
 * Klarna dokumentacije (docs.klarna.com/api-reference) prije produkcije -
 * ovdje su postavljeni prema zvaničnoj dokumentaciji avgust 2026, ali
 * Klarna povremeno ažurira nazive polja u payload-u.
 *
 * @since 0.1.0
 */
class ApiHelper
{
    /** @var string API korisničko ime (Merchant ID / username) */
    private string $username;

    /** @var string API lozinka */
    private string $password;

    /** @var bool Sandbox (Playground) mod */
    private bool $sandbox;

    /** @var string Regija: eu | na | oc */
    private string $region;

    /**
     * @param array $credentials  ['username' => '', 'password' => '', 'sandbox' => true, 'region' => 'eu']
     */
    public function __construct(array $credentials)
    {
        $this->username = $credentials['username'] ?? '';
        $this->password = $credentials['password'] ?? '';
        $this->sandbox  = (bool) ($credentials['sandbox'] ?? true);
        $this->region   = strtolower($credentials['region'] ?? 'eu');
    }

    // -------------------------------------------------------------------------
    // Public API metodi
    // -------------------------------------------------------------------------

    /**
     * Korak 1: Kreira Klarna Payments (KP) sesiju.
     *
     * @param  array  $payload  purchase_country, purchase_currency, locale,
     *                          order_amount, order_lines, merchant_urls (opciono za KP samostalno)
     * @return array  Odgovor sa session_id i client_token
     * @throws RuntimeException
     * @since  0.1.0
     */
    public function createKpSession(array $payload): array
    {
        $response = $this->httpPost($this->apiBase() . '/payments/v1/sessions', $payload);

        if (empty($response['session_id'])) {
            throw new RuntimeException('Klarna: KP session creation failed. ' . json_encode($response));
        }

        return $response;
    }

    /**
     * Korak 2: Kreira Hosted Payment Page (HPP) sesiju vezanu za KP sesiju.
     *
     * @param  string  $kpSessionId   session_id iz createKpSession()
     * @param  array   $merchantUrls  ['success' => '', 'cancel' => '', 'back' => '', 'failure' => '', 'error' => '', 'status_update' => '']
     * @return array   Odgovor sa redirect_url, session_id (HPP), session_url
     * @throws RuntimeException
     * @since  0.1.0
     */
    public function createHppSession(string $kpSessionId, array $merchantUrls): array
    {
        $payload = [
            'payment_session_url' => $this->apiBase() . '/payments/v1/sessions/' . $kpSessionId,
            'merchant_urls'       => array_filter($merchantUrls),
        ];

        $response = $this->httpPost($this->apiBase() . '/hpp/v1/sessions', $payload);

        if (empty($response['redirect_url'])) {
            throw new RuntimeException('Klarna: HPP session creation failed. ' . json_encode($response));
        }

        return $response;
    }

    /**
     * Korak 3: Postavlja order kod Klarne koristeći authorization_token
     * dobijen kroz success redirect nakon što je kupac platio.
     *
     * @param  string  $authorizationToken
     * @param  array   $payload             isti order podaci (purchase_country, order_lines...)
     *                                       + auto_capture (bool) ako se odmah kapturiše
     * @return array   Odgovor sa order_id, redirect_url, fraud_status
     * @throws RuntimeException
     * @since  0.1.0
     */
    public function placeOrder(string $authorizationToken, array $payload): array
    {
        $url = $this->apiBase() . '/payments/v1/authorizations/' . urlencode($authorizationToken) . '/order';

        $response = $this->httpPost($url, $payload);

        if (empty($response['order_id'])) {
            throw new RuntimeException('Klarna: Place order failed. ' . json_encode($response));
        }

        return $response;
    }

    /**
     * Otkazuje/oslobađa authorization koji nije iskorišćen za order (npr. na cancel/error redirectu).
     *
     * @since 0.1.0
     */
    public function cancelAuthorization(string $authorizationToken): void
    {
        $url = $this->apiBase() . '/payments/v1/authorizations/' . urlencode($authorizationToken);
        $this->httpDelete($url);
    }

    /**
     * Dohvata detalje ordera (status, fraud_status, captures...).
     *
     * @since 0.1.0
     */
    public function getOrder(string $orderId): array
    {
        $url = $this->apiBase() . '/ordermanagement/v1/orders/' . urlencode($orderId);

        return $this->httpGet($url);
    }

    /**
     * Kapturiše (naplaćuje) order - koristi se kada auto_capture nije uključen
     * pri place_order (npr. kod fizičke robe, capture nakon slanja).
     *
     * @since 0.1.0
     */
    public function captureOrder(string $orderId, float $amount, string $currency, ?array $orderLines = null): array
    {
        $url = $this->apiBase() . '/ordermanagement/v1/orders/' . urlencode($orderId) . '/captures';

        $payload = [
            // Klarna očekuje iznos u najmanjoj jedinici valute (npr. öre za SEK -> *100)
            'captured_amount' => (int) round($amount * 100),
        ];

        if ($orderLines !== null) {
            $payload['order_lines'] = $orderLines;
        }

        return $this->httpPost($url, $payload, true);
    }

    /**
     * Izdaje refund za order (potpuni ili parcijalni).
     *
     * @since 0.1.0
     */
    public function refundOrder(string $orderId, float $amount, string $description = ''): array
    {
        $url = $this->apiBase() . '/ordermanagement/v1/orders/' . urlencode($orderId) . '/refunds';

        $payload = [
            'refunded_amount' => (int) round($amount * 100),
        ];

        if (!empty($description)) {
            $payload['description'] = $description;
        }

        return $this->httpPost($url, $payload, true);
    }

    /**
     * Otkazuje order kod Klarne (samo dok je "Uncaptured" - pre nego što je
     * bilo šta kapturisano). Ako je order već (delimično) kapturisan, Klarna
     * odbija ovaj poziv - u tom slučaju treba koristiti refundOrder() umesto.
     *
     * @since 0.1.0
     */
    public function cancelOrder(string $orderId): array
    {
        $url = $this->apiBase() . '/ordermanagement/v1/orders/' . urlencode($orderId) . '/cancel';

        return $this->httpPost($url, [], true);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Vraća base URL prema regiji i sandbox/produkcija modu.
     * Struktura: api[-region][.playground].klarna.com
     *
     * @since 0.1.0
     */
    private function apiBase(): string
    {
        $regionPart = match ($this->region) {
            'na' => '-na',
            'oc' => '-oc',
            default => '', // eu je default, bez sufiksa
        };

        $envPart = $this->sandbox ? '.playground' : '';

        return 'https://api' . $regionPart . $envPart . '.klarna.com';
    }

    /**
     * Gradi Basic Auth header: Base64(username:password).
     *
     * @since 0.1.0
     */
    private function authHeaders(): array
    {
        $token = base64_encode($this->username . ':' . $this->password);

        return [
            'Authorization: Basic ' . $token,
            'Content-Type: application/json',
        ];
    }

    /**
     * HTTP POST zahtev.
     *
     * @param  bool  $allowEmptyResponse  true za 204 No Content odgovore (capture/refund ponekad vraćaju prazno)
     * @throws RuntimeException
     */
    private function httpPost(string $url, array $payload, bool $allowEmptyResponse = false): array
    {
        $json = json_encode($payload);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => $this->authHeaders(),
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new RuntimeException('Klarna cURL error: ' . $error);
        }

        if ($status >= 400) {
            throw new RuntimeException('Klarna API error (HTTP ' . $status . ') on ' . $url . ': ' . $body);
        }

        if ($status === 204 || trim((string) $body) === '') {
            if ($allowEmptyResponse) {
                return ['status' => 'accepted', 'http_status' => $status];
            }
            throw new RuntimeException('Klarna: Empty response (HTTP ' . $status . ') for ' . $url);
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            throw new RuntimeException('Klarna: Invalid response (HTTP ' . $status . ') on ' . $url . ': ' . $body);
        }

        return $decoded;
    }

    /**
     * HTTP GET zahtev.
     *
     * @throws RuntimeException
     */
    private function httpGet(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $this->authHeaders(),
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new RuntimeException('Klarna cURL error: ' . $error);
        }

        if ($status >= 400) {
            throw new RuntimeException('Klarna API error (HTTP ' . $status . ') on ' . $url . ': ' . $body);
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            throw new RuntimeException('Klarna: Invalid response (HTTP ' . $status . ') on ' . $url . ': ' . $body);
        }

        return $decoded;
    }

    /**
     * HTTP DELETE zahtev (npr. cancel authorization).
     *
     * @throws RuntimeException
     */
    private function httpDelete(string $url): void
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_HTTPHEADER     => $this->authHeaders(),
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new RuntimeException('Klarna cURL error: ' . $error);
        }

        if ($status >= 400 && $status !== 404) {
            throw new RuntimeException('Klarna API error (HTTP ' . $status . '): ' . $body);
        }
    }
}
