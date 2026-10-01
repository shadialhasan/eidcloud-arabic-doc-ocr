<?php

declare(strict_types=1);

namespace EidCloud\ArabicDocOcr\Engines;

/**
 * Vision Language Model (Qwen2-VL / Llama-3.2-Vision / Gemini API compatible) Engine.
 *
 * Can call local Ollama / vLLM / OpenAI-compatible endpoint with image base64.
 */
class VisionModelEngine implements OcrEngineInterface
{
    private string $endpointUrl;
    private string $modelName;
    private ?string $apiKey;

    public function __construct(
        string $endpointUrl = 'http://localhost:11434/api/generate',
        string $modelName = 'qwen2-vl:latest',
        ?string $apiKey = null
    ) {
        $this->endpointUrl = $endpointUrl;
        $this->modelName = $modelName;
        $this->apiKey = $apiKey;
    }

    public function getName(): string
    {
        return 'Vision-Language Model (' . $this->modelName . ')';
    }

    public function isAvailable(): bool
    {
        $ch = @curl_init($this->endpointUrl);
        if (!$ch) {
            return false;
        }
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 1);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        @curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code > 0;
    }

    public function recognize(string $imagePath, array $options = []): string
    {
        if (!file_exists($imagePath)) {
            throw new \InvalidArgumentException("Image not found: {$imagePath}");
        }

        $imageData = file_get_contents($imagePath);
        if ($imageData === false) {
            throw new \RuntimeException("Could not read image file: {$imagePath}");
        }

        $base64Image = base64_encode($imageData);
        $prompt = $options['prompt'] ?? 'Extract all Arabic and English text from this document accurately, preserving lines and numbers.';

        // Prepare JSON payload for Ollama / OpenAI Vision compatible API
        $payload = [
            'model' => $this->modelName,
            'prompt' => $prompt,
            'stream' => false,
            'images' => [$base64Image]
        ];

        $ch = curl_init($this->endpointUrl);
        if (!$ch) {
            throw new \RuntimeException("Failed to initialize cURL.");
        }

        $headers = ['Content-Type: application/json'];
        if ($this->apiKey) {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        }

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \RuntimeException("Vision model connection failed: " . $error);
        }

        if ($httpCode >= 400 || !$response) {
            throw new \RuntimeException("Vision model returned HTTP {$httpCode}: {$response}");
        }

        $json = json_decode((string)$response, true);
        return $json['response'] ?? $json['choices'][0]['message']['content'] ?? (string)$response;
    }
}
