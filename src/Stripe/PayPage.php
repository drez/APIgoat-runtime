<?php

namespace ApiGoat\Stripe;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Public tokenized pay page (GET /stripe/pay/<token>) and Checkout return
 * landing (GET /stripe/return/<token>). Tokens are looked up by sha256 hash;
 * raw record ids never appear in public URLs. The webhook remains the source
 * of truth — renderReturn re-verifies the session server-side for display only.
 */
final class PayPage
{
    public static function render(Request $request, Response $response, string $token): Response
    {
        $pay = self::paymentByToken($token);
        if ($pay === null) {
            return self::html($response, 404, 'Payment link', '<p>This payment link is invalid or has expired.</p>');
        }
        if (\in_array((string) $pay->getStatus(), ['succeeded', 'refunded', 'partially_refunded'], true)) {
            return self::html($response, 200, 'Payment received', '<p>This payment has already been completed. Thank you!</p>');
        }

        // Re-create a fresh Checkout Session if the stored one is expired/consumed.
        $gw  = StripeGateway::fromEnv();
        $url = '';
        if ($gw !== null && (string) $pay->getStripeCheckoutSessionId() !== '') {
            try {
                $session = $gw->client()->checkout->sessions->retrieve((string) $pay->getStripeCheckoutSessionId());
                if (($session->status ?? '') === 'open') {
                    $url = (string) $session->url;
                }
            } catch (\Throwable $e) {
                // fall through — regenerate below
            }
        }
        if ($url === '') {
            $entry = StripeManifest::payable((string) $pay->getPayableTable());
            if ($entry === null || $gw === null) {
                return self::html($response, 503, 'Payment unavailable', '<p>Payments are temporarily unavailable. Please contact us.</p>');
            }
            try {
                // Reuse the existing ledger row (same pay token / URL) instead
                // of spawning a new stripe_payment row per stale visit.
                $url = CheckoutService::refreshSessionFor($pay, $token);
            } catch (\Throwable $e) {
                return self::html($response, 404, 'Payment link', '<p>This payment link is invalid.</p>');
            }
        }

        // refreshSessionFor() mutates $pay in place (setAmount/setCurrency)
        // when it regenerates the session, so the displayed figure always
        // matches what the (possibly just-refreshed) session will charge.
        $amount   = \htmlspecialchars(\number_format($pay->getAmount() / 100, 2), ENT_QUOTES);
        $currency = \htmlspecialchars(\strtoupper((string) $pay->getCurrency()), ENT_QUOTES);
        $body = "<p class=\"gc-pay-amount\">{$amount} {$currency}</p>"
              . "<a class=\"gc-pay-button\" href=\"" . \htmlspecialchars($url, ENT_QUOTES) . "\">Pay now</a>"
              . "<p class=\"gc-pay-note\">You will be redirected to Stripe's secure payment page.</p>";
        return self::html($response, 200, 'Complete your payment', $body);
    }

    public static function renderReturn(Request $request, Response $response, string $token): Response
    {
        $pay = self::paymentByToken($token);
        if ($pay === null) {
            return self::html($response, 404, 'Payment', '<p>This link is invalid or has expired.</p>');
        }
        $params = $request->getQueryParams();
        if (($params['s'] ?? '') === 'cancel') {
            return self::html($response, 200, 'Payment canceled', '<p>The payment was canceled. You can retry from the same link at any time.</p>');
        }
        // Display-only server-side verification (webhook remains authoritative).
        $gw = StripeGateway::fromEnv();
        $paid = \in_array((string) $pay->getStatus(), ['succeeded'], true);
        if (!$paid && $gw !== null && !empty($params['sid']) && \is_string($params['sid']) && \preg_match('/^cs_[A-Za-z0-9_]+$/', $params['sid'])) {
            try {
                $session = $gw->client()->checkout->sessions->retrieve($params['sid']);
                $paid = ($session->payment_status ?? '') === 'paid'
                    && (string) $session->id === (string) $pay->getStripeCheckoutSessionId();
            } catch (\Throwable $e) {
                $paid = false;
            }
        }
        return $paid
            ? self::html($response, 200, 'Payment received', '<p>Thank you! Your payment was received. A receipt has been emailed to you by Stripe.</p>')
            : self::html($response, 200, 'Payment processing', '<p>Your payment is being processed. This page does not update automatically — you will receive a Stripe receipt by email once it completes.</p>');
    }

    private static function paymentByToken(string $token): ?object
    {
        if (!StripeManifest::available() || !\preg_match('/^[0-9a-f]{64}$/', $token)) {
            return null;
        }
        $payQ = StripeDb::query('StripePayment');
        $pay  = $payQ::create()->filterByPayTokenHash(PayTokens::hash($token))->findOne();
        if ($pay === null) {
            return null;
        }
        $exp = (int) $pay->getPayTokenExpires();
        if ($exp > 0 && $exp < \time() && !\in_array((string) $pay->getStatus(), ['succeeded', 'refunded', 'partially_refunded'], true)) {
            return null;
        }
        return $pay;
    }

    private static function html(Response $response, int $status, string $title, string $body): Response
    {
        $t = \htmlspecialchars($title, ENT_QUOTES);
        $page = "<!doctype html><html><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">"
            . "<title>{$t}</title><style>body{font-family:system-ui,sans-serif;display:flex;justify-content:center;padding:3rem 1rem;background:#f6f8fa}"
            . ".gc-pay-card{max-width:26rem;width:100%;background:#fff;border:1px solid #d0d7de;border-radius:8px;padding:2rem;text-align:center}"
            . ".gc-pay-amount{font-size:2rem;font-weight:600;margin:0 0 1.5rem}"
            . ".gc-pay-button{display:inline-block;background:#635bff;color:#fff;text-decoration:none;padding:.75rem 2.5rem;border-radius:6px;font-weight:600}"
            . ".gc-pay-note{color:#57606a;font-size:.85rem;margin-top:1.25rem}</style></head>"
            . "<body><div class=\"gc-pay-card\"><h1 style=\"font-size:1.2rem\">{$t}</h1>{$body}</div></body></html>";
        $response->getBody()->write($page);
        return $response->withStatus($status)->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
