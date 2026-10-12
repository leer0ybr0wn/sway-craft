<?php
/**
 * Yii Application Config (web requests only)
 *
 * Merged with app.php and vendor/craftcms/cms/src/config/app.web.php when
 * Craft's bootstrap script is defining the configuration for web requests.
 *
 * @link https://craftcms.com/docs/5.x/reference/config/app.html
 */

use Craft;
use craft\contactform\events\SendEvent;
use craft\contactform\Mailer;
use craft\helpers\App;
use GuzzleHttp\Client;
use yii\base\Event;

// Verify the reCAPTCHA v3 token on contact form submissions before sending
Event::on(Mailer::class, Mailer::EVENT_BEFORE_SEND, function(SendEvent $event) {
    $request = Craft::$app->getRequest();
    $token = $request->getBodyParam('g-recaptcha-response');
    $secret = App::env('RECAPTCHA_SECRET_KEY');

    // Missing token likely means a bot skipped the JS challenge, treat as spam
    if (!$token) {
        $event->isSpam = true;
        return;
    }

    // Misconfiguration isn't the submitter's fault, fail open so the message still sends
    if (!$secret) {
        Craft::error('RECAPTCHA_SECRET_KEY is not set, skipping reCAPTCHA verification.', __METHOD__);
        return;
    }

    try {
        $client = new Client();
        $response = $client->post('https://www.google.com/recaptcha/api/siteverify', [
            'form_params' => [
                'secret' => $secret,
                'response' => $token,
                'remoteip' => $request->getUserIP(),
            ],
        ]);
        $result = json_decode((string)$response->getBody(), true);
    } catch (\Throwable $e) {
        // Can't complete verification, fail open rather than silently losing a real submission
        Craft::error('reCAPTCHA verification request failed: ' . $e->getMessage(), __METHOD__);
        return;
    }

    $verified = ($result['success'] ?? false)
        && ($result['action'] ?? null) === 'submit'
        && ($result['score'] ?? 0) >= 0.5;

    if (!$verified) {
        Craft::warning('reCAPTCHA verification failed for contact form submission: ' . json_encode($result), __METHOD__);
        $event->isSpam = true;
    }
});

return [];
