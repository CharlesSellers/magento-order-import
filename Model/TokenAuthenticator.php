<?php
/**
 * Copyright © Venuno. All rights reserved.
 */

declare(strict_types=1);

namespace Venuno\OrderImport\Model;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Webapi\Rest\Request;

/**
 * Validates the Venuno per-environment token presented on every Venuno endpoint.
 *
 * The token is NOT a Magento admin/integration token — it is a Venuno shared secret, configured per
 * environment in `app/etc/env.php` so dev / staging / production each carry their own value and the secret
 * never enters version control or the database:
 *
 *     'venuno' => [
 *         'order_import' => [
 *             'token'  => 'single-token',          // or
 *             'tokens' => ['active-token', 'previous-token'], // a list, to support rotation
 *         ],
 *     ],
 *
 * The client presents it as `Authorization: Bearer <token>` (canonical) or `X-Venuno-Token: <token>`.
 * Comparison is constant-time. A missing or unmatched token raises an {@see AuthorizationException},
 * which the Magento webapi error processor renders as HTTP 401.
 */
class TokenAuthenticator
{
    /** env.php path to a single token. */
    public const CONFIG_PATH_TOKEN = 'venuno/order_import/token';

    /** env.php path to a list of valid tokens (enables zero-downtime rotation). */
    public const CONFIG_PATH_TOKENS = 'venuno/order_import/tokens';

    private const HEADER_AUTHORIZATION = 'Authorization';
    private const HEADER_VENUNO_TOKEN = 'X-Venuno-Token';
    private const BEARER_PREFIX = 'Bearer ';

    public function __construct(
        private readonly DeploymentConfig $deploymentConfig,
        private readonly Request $request
    ) {
    }

    /**
     * Authorise the current request, or throw.
     *
     * @throws AuthorizationException
     */
    public function authenticate(): void
    {
        $provided = $this->extractToken();
        if ($provided === '') {
            throw new AuthorizationException(
                __('A Venuno authentication token is required (Authorization: Bearer <token>).')
            );
        }

        foreach ($this->validTokens() as $valid) {
            if ($valid !== '' && hash_equals($valid, $provided)) {
                return;
            }
        }

        throw new AuthorizationException(__('The Venuno authentication token is invalid.'));
    }

    private function extractToken(): string
    {
        $authorization = $this->header(self::HEADER_AUTHORIZATION);
        if ($authorization !== '' && stripos($authorization, self::BEARER_PREFIX) === 0) {
            return trim(substr($authorization, strlen(self::BEARER_PREFIX)));
        }

        return trim($this->header(self::HEADER_VENUNO_TOKEN));
    }

    private function header(string $name): string
    {
        $value = $this->request->getHeader($name);

        return ($value === false || $value === null) ? '' : (string) $value;
    }

    /**
     * The configured valid token(s), preferring the rotation list when present.
     *
     * @return string[]
     */
    private function validTokens(): array
    {
        $tokens = $this->deploymentConfig->get(self::CONFIG_PATH_TOKENS);
        if (is_array($tokens)) {
            return array_values(
                array_filter(array_map('strval', $tokens), static fn (string $token): bool => $token !== '')
            );
        }

        $single = $this->deploymentConfig->get(self::CONFIG_PATH_TOKEN);

        return (is_string($single) && $single !== '') ? [$single] : [];
    }
}
