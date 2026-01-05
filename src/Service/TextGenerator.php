<?php

namespace Drupal\rw_generate\Service;

use GuzzleHttp\Client;
use Symfony\Component\Yaml\Yaml;
use Drupal\Core\Config\ConfigFactoryInterface;

class TextGenerator {

  private Client $client;
  private string $apiKey;
  private array $siteContext;

  public function __construct(Client $client, ConfigFactoryInterface $configFactory) {
    $this->client = $client;
    $this->apiKey = getenv('GEMINI_API_KEY') ?: '';
    $context = $configFactory->get('rw_generate.site_context')->get('context');
    $this->siteContext = $context ? Yaml::parse($context) : [];
  }

  public function generateAIText(array $promptParameters = [], string $field_label = 'text'): array {
    if (empty($this->apiKey)) {
      throw new \Exception('GEMINI_API_KEY not set.');
    }

    $context = $this->siteContext['default_site_context'] ?? [];
    $basePrompt = $context['base_prompt'] ?? 'Write a {field_label} about {topic}. {variation}.';
    $topics = $context['topics'] ?? ['a general topic'];
    $variations = $context['variations'] ?? ['with interesting details'];

    $selectedTopic = $promptParameters['topic'] ?? $topics[array_rand($topics)];
    $selectedVariation = $promptParameters['variation'] ?? $variations[array_rand($variations)];

    $prompt = str_replace(
      ['{field_label}', '{topic}', '{variation}'],
      [$field_label, $selectedTopic, $selectedVariation],
      $basePrompt
    );

    $response = $this->client->post(
      'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent',
      [
        'headers' => [
          'Content-Type' => 'application/json',
          'x-goog-api-key' => $this->apiKey,
        ],
        'json' => [
          'contents' => [
            [
              'role' => 'user',
              'parts' => [['text' => $prompt]],
            ],
          ],
        ],
        'timeout' => 30,
      ]
    );

    $body = json_decode($response->getBody()->getContents(), true);
    $fullText = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';
    if (!$fullText) {
      throw new \Exception('No text returned from Gemini.');
    }

    $lines = preg_split("/
?
/", $fullText, 2);
    $title = trim($lines[0]) ?: 'AI Generated Title';
    $bodyText = isset($lines[1]) ? trim($lines[1]) : trim($lines[0]);
    $bodyHtml = '<p>' . nl2br($bodyText) . '</p>';

    return [
      'title' => $title,
      'body' => $bodyHtml,
    ];
  }
}
