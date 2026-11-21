<?php

namespace Passorde\Api;

use Passorde\Api\Exceptions\ApiConfigurationException;
use SensitiveParameter;

final class Credentials
{
    public function __construct(
        public readonly string $baseUrl,
        public readonly string $companyId,
        public readonly string $publicKey,
        #[SensitiveParameter] private readonly string $secretKey,
    ) {}

    /**
     * @throws ApiConfigurationException
     */
    public static function fromConfig(): self
    {
        $values = [];
        $missing = [];

        foreach (['base_url', 'company_id', 'public_key', 'secret_key'] as $key) {
            $value = config('passorde-connect.evoliz.'.$key);
            $values[$key] = is_scalar($value) ? trim((string) $value) : '';

            if ($values[$key] === '') {
                $missing[] = 'PASSORDE_EVOLIZ_'.strtoupper($key);
            }
        }

        if ($missing !== []) {
            throw new ApiConfigurationException('Configuration Evoliz incomplète : '.implode(', ', $missing));
        }

        return new self(rtrim($values['base_url'], '/'), $values['company_id'], $values['public_key'], $values['secret_key']);
    }

    public function secretKey(): string
    {
        return $this->secretKey;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->baseUrl, 'companyId' => $this->companyId, 'publicKey' => $this->publicKey, 'secretKey' => '[hidden]'];
    }
}
