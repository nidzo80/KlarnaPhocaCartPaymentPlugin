<?php
/**
 * @package    Plg_Pcp_Klarna
 * @license    GNU General Public License version 3 or later
 */

namespace YourVendor\Plugin\Pcp\Klarna\Helper;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\Database\DatabaseInterface;
use PhocacartOrderStatus;

\defined('_JEXEC') or die;

/**
 * Phoca Cart helper klasa.
 * Wrapper oko Phoca Cart API-ja za lakšu upotrebu u pluginu.
 * (Identičan RaiAccept/Kombank ShopHelper-u - generički, plugin-agnostičan.)
 *
 * @since 0.1.0
 */
class ShopHelper
{
    /**
     * Upisuje poruku u Phoca Cart log.
     *
     * @since 0.1.0
     */
    public static function addLog(int $type = 1, string $title = '', int $itemId = 0, string $message = ''): void
    {
        \PhocacartLog::add($type, $title, $itemId, $message);
    }

    /**
     * Prazni korpu i otkazuje guest korisnika.
     *
     * @since 0.1.0
     */
    public static function emptyCart(): void
    {
        $cart = new \PhocacartCart;
        $cart->emptyCart();
        \PhocacartUserGuestuser::cancelGuestUser();
    }

    /**
     * Dohvata interne podatke o plaćanju sačuvane uz order.
     *
     * @since 0.1.0
     */
    public static function getPaymentData(int $orderId): array
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $paymentData = $db->setQuery(
            $db->getQuery(true)
               ->select('params_payment')
               ->from('#__phocacart_orders')
               ->where('id = ' . $orderId)
        )->loadResult();

        return !empty($paymentData) ? json_decode($paymentData, true) : [];
    }

    /**
     * Dohvata ukupan iznos i valutu ordera.
     *
     * @since 0.1.0
     */
    public static function getOrderAmount(int $orderId): array
    {
        $order       = new \PhocacartOrderView;
        $orderTotal  = array_reverse($order->getItemTotal($orderId));
        $totalBrutto = 0;
        $common      = $order->getItemCommon($orderId);
        $rate        = $common->currency_exchange_rate ?? 1;

        foreach ($orderTotal as $v) {
            if ($v->type === 'brutto') {
                $totalBrutto = ($v->amount_currency != 0)
                    ? $v->amount_currency
                    : $v->amount * $rate;
                break;
            }
        }

        return [
            'total'    => $totalBrutto,
            'currency' => $common->currency_code,
            'rate'     => (float) ($common->currency_exchange_rate ?? 1),
        ];
    }

    /**
     * Dohvata payment metodu po ID-u.
     *
     * @since 0.1.0
     */
    public static function getPaymentMethod(int $pid): object
    {
        static $paymentMethod = null;

        if (!isset($paymentMethod)) {
            $payment       = new \PhocacartPayment;
            $paymentMethod = $payment->getPaymentMethod($pid);
        }

        return $paymentMethod;
    }

    /**
     * Redirect na checkout sa porukom.
     *
     * @since 0.1.0
     */
    public static function redirectToCart(string $messageText, string $messageType = 'error'): void
    {
        Factory::getApplication()->enqueueMessage($messageText, $messageType);
        Factory::getApplication()->redirect(Route::_(\PhocacartRoute::getCheckoutRoute()));
    }

    /**
     * Čuva interne podatke plaćanja u order zapis.
     * Merge-uje sa postojećim podacima kako se ne bi izgubili prethodni podaci.
     *
     * @since 0.1.0
     */
    public static function saveInternalData(int $orderId, array $data, string $pluginName): bool
    {
        $paymentData = self::getPaymentData($orderId);
        $paymentData[$pluginName] = !empty($paymentData[$pluginName])
            ? array_merge($paymentData[$pluginName], $data)
            : $data;

        $object                 = new \stdClass;
        $object->id             = $orderId;
        $object->params_payment = json_encode($paymentData);

        return Factory::getContainer()
                      ->get(DatabaseInterface::class)
                      ->updateObject('#__phocacart_orders', $object, 'id');
    }

    /**
     * Postavlja status ordera i upisuje historiju.
     *
     * @since 0.1.0
     */
    public static function setOrderStatus(
        int $orderId,
        int $statusId,
        string $statusName = '',
        ?string $paymentId = null,
        ?float $paymentAmount = null
    ): void {
        PhocacartOrderStatus::changeStatusInOrderTable($orderId, $statusId);

        $order  = new \PhocacartOrderView;
        $common = $order->getItemCommon($orderId);
        $notify = false;

        try {
            $notify = PhocacartOrderStatus::changeStatus($orderId, $statusId, $common->order_token);
        } catch (\Throwable $e) {
            \PhocacartLog::add(2, 'Payment - Klarna - ERROR changeStatus', $orderId,
                $e->getMessage() . ' token=' . ($common->order_token ?? 'NULL'));
            // Email greška nije fatalna - status je već promijenjen u tabeli
        }

        $comment = Text::_('COM_PHOCACART_ORDER_STATUS_CHANGED_BY_PAYMENT_SERVICE_PROVIDER') . ' (Klarna)';
        $comment .= "\n" . Text::_('COM_PHOCACART_INFORMATION');

        if (!empty($paymentId)) {
            $comment .= "\n" . Text::_('COM_PHOCACART_PAYMENT_ID') . ': ' . $paymentId;
        }

        if (!empty($paymentAmount)) {
            $comment .= "\n" . Text::_('COM_PHOCACART_PAYMENT_AMOUNT') . ': ' . $paymentAmount;
        }

        if (!empty($statusName)) {
            $comment .= "\n" . Text::_('COM_PHOCACART_PAYMENT_STATUS') . ': ' . $statusName;
        }

        try {
            PhocacartOrderStatus::setHistory($orderId, $statusId, $notify, $comment);
        } catch (\Throwable $e) {
            // Historija nije fatalna
        }
    }
}
