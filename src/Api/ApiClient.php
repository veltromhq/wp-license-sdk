<?php

namespace Veltrom\License\Api;

use Veltrom\License\Config;
use Veltrom\License\Http\Transport;
use Veltrom\License\Http\TransportException;

/**
 * The public licensing endpoints the SDK calls (api-v1.md). Transport
 * failures propagate as TransportException; the Client decides what an
 * unreachable server means, which is never a licensing failure.
 */
final class ApiClient
{
    public function __construct(private readonly Config $config, private readonly Transport $transport)
    {
    }

    /**
     * @param  array<string, mixed>  $installation
     *
     * @throws TransportException
     */
    public function activate(string $licenseKey, array $installation): ApiResponse
    {
        return $this->post('/licenses/activate', [
            'license_key' => $licenseKey,
            'product' => $this->config->product,
            'installation' => $installation,
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     *
     * @throws TransportException
     */
    public function validate(string $licenseKey, string $activationId, array $metadata): ApiResponse
    {
        return $this->post('/licenses/validate', [
            'license_key' => $licenseKey,
            'product' => $this->config->product,
            'activation_id' => $activationId,
            'metadata' => $metadata,
        ]);
    }

    /**
     * @throws TransportException
     */
    public function deactivate(string $licenseKey, string $activationId): ApiResponse
    {
        return $this->post('/licenses/deactivate', [
            'license_key' => $licenseKey,
            'product' => $this->config->product,
            'activation_id' => $activationId,
        ]);
    }

    /**
     * @throws TransportException
     */
    public function keys(): ApiResponse
    {
        $response = $this->transport->send('GET', $this->config->apiBase . '/keys');

        return ApiResponse::from($response->status, $response->json, $response->requestId);
    }

    /**
     * @throws TransportException
     */
    public function checkUpdate(string $licenseKey, ?string $activationId, string $currentVersion): ApiResponse
    {
        return $this->post('/updates/check', array_filter([
            'product' => $this->config->product,
            'license_key' => $licenseKey,
            'activation_id' => $activationId,
            'current_version' => $currentVersion,
            'channel' => 'stable',
            'platform' => 'wordpress',
            'architecture' => 'universal',
        ], fn ($value) => $value !== null));
    }

    /**
     * @param  array<string, mixed>  $body
     *
     * @throws TransportException
     */
    private function post(string $path, array $body): ApiResponse
    {
        $response = $this->transport->send('POST', $this->config->apiBase . $path, $body);

        return ApiResponse::from($response->status, $response->json, $response->requestId);
    }
}
