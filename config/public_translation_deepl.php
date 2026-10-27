<?php
declare(strict_types=1);

require_once __DIR__ . '/public_translation.php';

/**
 * DeepL adapter contract. The callable is injected: tests pass a fake, and the
 * real HTTP transport lives in public_translation_deepl_transport.php, which is
 * OFF unless BRVTAL_DEEPL_ENABLED=1 and DEEPL_AUTH_KEY exist in the server
 * environment. This class never reads, stores or forwards the key.
 */
final class BrvtalDeepLTranslationAdapter implements BrvtalPublicTranslationAdapter
{
    public const int MAX_SOURCE_BYTES = 5000;
    public const int MAX_RESULT_BYTES = 10000;

    public const string FAKE_VERSION = 'deepl-fake-es-en-v1';

    private readonly Closure $fakeTransport;
    private readonly string $version;
    private bool $configured;

    /**
     * The optional configuration reader abstracts the environment. It may
     * return a fixture key, but the value is never stored or sent to transport.
     * Without a reader the provider is always disabled (fail closed).
     */
    public function __construct(callable $fakeTransport, ?callable $readConfiguration = null, string $version = self::FAKE_VERSION)
    {
        $this->fakeTransport = Closure::fromCallable($fakeTransport);
        $this->version = preg_match('/^deepl-[a-z0-9-]{3,48}$/', $version) === 1 ? $version : self::FAKE_VERSION;
        $this->configured = false;
        if ($readConfiguration !== null) {
            try {
                $value = $readConfiguration('DEEPL_AUTH_KEY');
                $this->configured = is_string($value) && trim($value) !== '';
            } catch (Throwable) {
                $this->configured = false;
            }
        }
    }

    public function version(): string
    {
        return $this->version;
    }

    public function translate(string $source, string $sourceLocale, string $targetLocale): string
    {
        if (!$this->configured) {
            throw new RuntimeException('provider_not_configured');
        }
        if ($sourceLocale !== 'es' || $targetLocale !== 'en') {
            throw new InvalidArgumentException('unsupported_locale');
        }
        // Reject malformed UTF-8 and oversize bytes before text normalization
        // or invoking the injected fake transport.
        if (
            strlen($source) > self::MAX_SOURCE_BYTES
            || preg_match('//u', $source) !== 1
            || preg_match('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F\\x7F]/', $source) === 1
        ) {
            throw new InvalidArgumentException('invalid_public_text');
        }
        $plain = brvtalPublicTranslationPlainText($source);
        if ($plain === null) {
            throw new InvalidArgumentException('invalid_public_text');
        }
        // Normalization is safe for public plain text and preserves cache identity
        // at the provider-neutral resolver boundary.
        $source = $plain;

        try {
            // Only this injected test double is invoked; no key is forwarded.
            $response = ($this->fakeTransport)([
                'text' => [$source],
                'source_lang' => 'ES',
                'target_lang' => 'EN',
            ]);
        } catch (Throwable) {
            throw new RuntimeException('provider_unavailable');
        }

        if (
            !is_array($response)
            || !isset($response['translations'])
            || !is_array($response['translations'])
            || !array_is_list($response['translations'])
            || count($response['translations']) !== 1
            || !is_array($response['translations'][0])
        ) {
            throw new RuntimeException('provider_invalid_response');
        }

        $text = $response['translations'][0]['text'] ?? null;
        if (
            !is_string($text)
            || strlen($text) > self::MAX_RESULT_BYTES
            || preg_match('//u', $text) !== 1
        ) {
            throw new RuntimeException('provider_invalid_response');
        }
        $plainResult = brvtalPublicTranslationPlainText($text);
        if (
            $plainResult === null
            || $plainResult !== $text
            || strlen($plainResult) > self::MAX_RESULT_BYTES
        ) {
            throw new RuntimeException('provider_invalid_response');
        }
        return $plainResult;
    }
}
