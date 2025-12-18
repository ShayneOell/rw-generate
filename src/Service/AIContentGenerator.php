<?php

namespace Drupal\rw_generate\Service;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Symfony\Component\Yaml\Yaml;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use GuzzleHttp\Client;
use Drupal\file\FileInterface;
use Drupal\file\Entity\File;;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\Core\File\FileSystemInterface;

class AIContentGenerator
{
  private Client $client;
  private string $apiKey;
  private FileSystemInterface $fileSystem;
  private ModuleHandlerInterface $moduleHandler;
  private ConfigFactoryInterface $configFactory;
  private array $siteContext;

  public function __construct(Client $client, FileSystemInterface $fileSystem, ModuleHandlerInterface $moduleHandler, ConfigFactoryInterface $configFactory)
  {
    $this->client = $client;
    $this->apiKey = getenv('GEMINI_API_KEY') ?: '';
    $this->fileSystem = $fileSystem;
    $this->moduleHandler = $moduleHandler;
    $this->configFactory = $configFactory;
    $this->siteContext = $this->loadSiteContext();
  }

  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('http_client'),
      $container->get('file_system'),
      $container->get('module_handler'),
      $container->get('config.factory')
    );
  }

  public function generateNodes(int $count, string $contentType, bool $generateImages): int
  {
    $created = 0;
    $context = $this->siteContext['default_site_context'] ?? [];
    $topics = $context['topics'] ?? ['general topic'];
    $variations = $context['variations'] ?? ['interesting details'];
    $imageKeywords = $context['image_keywords'] ?? ['abstract image'];

    $promptCombinations = [];
    
    for ($i = 0; $i < $count; $i++) {
      $topic = $topics[$i % count($topics)];
      $variation = $variations[$i % count($variations)];
      $promptCombinations[] = ['topic' => $topic, 'variation' => $variation];
    }
    
    shuffle($promptCombinations);

    for ($i = 0; $i < $count; $i++) {
      $node = NULL;
      try {
        $nodeTypeManager = \Drupal::entityTypeManager()->getStorage('node_type');
        if (!$nodeTypeManager->load($contentType)) {
          \Drupal::logger('rw_generate')->warning('Content type "@type" does not exist. Skipping node generation.', ['@type' => $contentType]);
          continue;
        }

        $node = Node::create([
          'type' => $contentType,
          'title' => 'Generating...',
          'body' => [
            'value' => '<p>Generating content...</p>',
            'format' => 'full_html',
          ],
          'status' => 1,
        ]);
        $node->save();

        $currentPromptParameters = $promptCombinations[$i] ?? [];
        $textData = $this->generateAIText($currentPromptParameters);
        $node->setTitle($textData['title']);
        $node->set('body', [
          'value' => $textData['body'],
          'format' => 'full_html',
        ]);

        if ($generateImages) {
          if ($node->hasField('field_image')) {
            $imageKeyword = $imageKeywords[$i % count($imageKeywords)];
            if (empty($imageKeyword) && !empty($textData['title'])) {
                $imageKeyword = $textData['title'];
            }
            $mediaId = $this->generateAIImage($imageKeyword);
            if ($mediaId) {
              $node->set('field_image', [
                [
                  'target_id' => $mediaId,
                  'alt' => $textData['title'],
                ]
              ]);
            }
          } else {
            \Drupal::logger('rw_generate')->warning('Content type "@type" does not have an "field_image" field. Skipping image generation for this node.', ['@type' => $contentType]);
          }
        }

        $node->save();
        $created++;
      } catch (\Exception $e) {
        \Drupal::logger('rw_generate')->error('Failed to generate node for content type "@type": @msg', ['@type' => $contentType, '@msg' => $e->getMessage()]);
        if ($node instanceof Node && !$node->isNew()) {
          $node->delete();
          \Drupal::logger('rw_generate')->info('Deleted partially created node due to error.');
        }
        continue;
      }
    }

    return $created;
  }

  private function generateAIText(array $promptParameters = []): array
  {
    if (empty($this->apiKey)) {
      throw new \Exception('GEMINI_API_KEY not set.');
    }

    $context = $this->siteContext['default_site_context'] ?? [];
    $basePrompt = $context['base_prompt'] ?? 'Write about {topic}. {variation}.';
    $topics = $context['topics'] ?? ['a general topic'];
    $variations = $context['variations'] ?? ['with interesting details'];

    $selectedTopic = $promptParameters['topic'] ?? $topics[array_rand($topics)];
    $selectedVariation = $promptParameters['variation'] ?? $variations[array_rand($variations)];

    $prompt = str_replace(
      ['{topic}', '{variation}'],
      [$selectedTopic, $selectedVariation],
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

    $lines = preg_split("/\r?\n/", $fullText, 2);
    $title = trim($lines[0]) ?: 'AI Generated Title';
    $bodyText = isset($lines[1]) ? trim($lines[1]) : trim($lines[0]);
    $bodyHtml = '<p>' . nl2br($bodyText) . '</p>';

    return [
      'title' => $title,
      'body' => $bodyHtml,
    ];
  }

  private function generateAIImage(string $imageKeyword = 'abstract image'): ?int
  {
    $time = time();

    if (empty($this->apiKey)) {
      \Drupal::logger('rw_generate')->error('GEMINI_API_KEY not set.');
      return null;
    }

    try {
      $response = $this->client->post(
        'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image:generateContent',
        [
          'headers' => [
            'Content-Type' => 'application/json',
            'x-goog-api-key' => $this->apiKey,
          ],
          'json' => [
            'contents' => [
              [
                'role' => 'user',
                'parts' => [['text' => $imageKeyword]],
              ],
            ],
          ],
          'timeout' => 60,
        ]
      );

      $body = json_decode($response->getBody()->getContents(), true);
      \Drupal::logger('rw_generate')->debug('Gemini image response: @body', ['@body' => json_encode($body)]);

      $inline = null;
      if (!empty($body['candidates'][0]['content']['parts'])) {
        foreach ($body['candidates'][0]['content']['parts'] as $part) {
          if (!empty($part['inlineData']['data'])) {
            $inline = $part['inlineData'];
            break;
          }
        }
      }

      if (!$inline || empty($inline['data'])) {
        return $this->createPlaceholderMedia($title, $time);
      }

      $imageBinary = base64_decode($inline['data'], true);
      if ($imageBinary === false) {
        return $this->createPlaceholderMedia($title, $time);
      }

      $mimeType = $inline['mimeType'] ?? 'image/png';
      $ext = match ($mimeType) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
        default      => 'png',
      };

      $imageName = 'ai_image_' . $time . '_' . uniqid() . '.' . $ext;
      $uri = 'public://' . $imageName;

      $this->fileSystem->saveData($imageBinary, $uri, FileSystemInterface::EXISTS_REPLACE);

      $file = File::create(['uri' => $uri, 'status' => 1]);
      $file->save();

      $media = Media::create([
        'bundle' => 'image',
        'name' => $imageName,
        'status' => 1,
        'field_media_image' => ['target_id' => $file->id()],
      ]);
      $media->save();

      return $media->id();
    } catch (\Exception $e) {
      \Drupal::logger('rw_generate')->error('Gemini API failure: @msg', ['@msg' => $e->getMessage()]);
      return $this->createPlaceholderMedia($title, $time);
    }
  }

  private function createPlaceholderMedia(string $title, int $time): int
  {
    $placeholderName = 'ai_placeholder_image_' . $time . '.png';
    $placeholderBinary = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABAQMAAAAl21bKAAAAA1BMVEUAAACnej3aAAAAAXRSTlMAQObYZgAAAApJREFUCNdjYAAAAAIAAeIhvDMAAAAASUVORK5CYII=');
    $uri = 'public://' . $placeholderName;

    $this->fileSystem->saveData($placeholderBinary, $uri, FileSystemInterface::EXISTS_REPLACE);

    $file = File::create(['uri' => $uri, 'status' => 1]);
    $file->save();

    $media = Media::create([
      'bundle' => 'image',
      'name' => $placeholderName,
      'status' => 1,
      'field_media_image' => ['target_id' => $file->id()],
    ]);
    $media->save();
  

    return $media->id();
  }
  
  private function loadSiteContext(): array
  {
    $modulePath = $this->moduleHandler->getModule('rw_generate')->getPath();
    $configFilePath = $modulePath . '/config/site.context.yml';

    if (!file_exists($configFilePath)) {
      \Drupal::logger('rw_generate')->error('site.context.yml not found at @path', ['@path' => $configFilePath]);
      return [];
    }

    try {
      return Yaml::parseFile($configFilePath);
    } catch (\Exception $e) {
      \Drupal::logger('rw_generate')->error('Error parsing site.context.yml: @msg', ['@msg' => $e->getMessage()]);
      return [];
    }
  }
}


