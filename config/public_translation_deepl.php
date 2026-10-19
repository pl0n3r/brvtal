<?php
declare(strict_types=1);

require_once __DIR__ . '/public_translation.php';

/**
 * DeepL build-ahead contract: only an explicitly injected fake callable can run.
 * No HTTP, SDK, secrets, service construction or production wiring is provided.
 */
final class BrvtalDeepLTranslationAdapter implements BrvtalPublicTranslationAdapter
{
    public const int MAX_SOURCE_BYTES = 5000;
    public const int MAX_RESULT_BYTES = 10000;

    private readonly Closure $fakeTransport;
    private bool $configured;

    /**
     * The optional configuration reader abstracts the environment. It may
     * return a fixture key, but the value is never stored or sent to transport.
     * Without a reader the provider is always disabled (fail closed).
     */
    public function __construct(callable $fakeTransport, ?callable $readConfiguration = null)
    {
        $this->fakeTransport = Closure::fromCallable($fakeTransport);
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
        return 'deepl-fake-es-en-v1';
    }

    public function translate(string $source, string $sourceLocale, string $targetLocale): string
    {
        if (!$this->configured) {
            throw new RuntimeException('provider_not_configured');
        }
        if ($sourceLocale !== 'es' || $targetLocale !== 'en') {
            throw new InvalidArgumentException('unsupported_locale');
        }
        $plain = brvtalPublicTranslationPlainText($source);
        if (
            $plain === null
            || $plain !== $source
            || strlen($source) > self::MAX_SOURCE_BYTES
        ) {
            throw new InvalidArgumentException('invalid_public_text');
        }

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
