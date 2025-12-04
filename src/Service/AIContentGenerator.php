<?php

namespace Drupal\rw_generate\Service;

use Drupal\node\Entity\Node;
use Drupal\media\Entity\Media;
use Drupal\file\Entity\File;
use Drupal\Core\File\FileSystemInterface;
use GuzzleHttp\Client;

class AIContentGenerator
{
  private Client $client;
  private string $apiKey;
  private FileSystemInterface $fileSystem;

  public function __construct(Client $client, FileSystemInterface $fileSystem)
  {
    $this->client = $client;
    $this->apiKey = getenv('GEMINI_API_KEY') ?: '';
    $this->fileSystem = $fileSystem;
  }

  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('http_client'),
      $container->get('file_system')
    );
  }

  public function generateNodes(int $count, string $contentType, bool $generateImages): int
  {
    $created = 0;

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

        $textData = $this->generateAIText($contentType);
        $node->setTitle($textData['title']);
        $node->set('body', [
          'value' => $textData['body'],
          'format' => 'full_html',
        ]);

        if ($generateImages) {
          if ($node->hasField('field_image')) {
            $mediaId = $this->generateAIImage($textData['title']);
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

  private function generateAIText(string $contentType): array
  {
    if (empty($this->apiKey)) {
      throw new \Exception('GEMINI_API_KEY not set.');
    }

    $prompt = sprintf(
      'Write a short %s about Cape Town. The first line should be the title, followed by the body text.',
      $contentType === 'article' ? 'blog post' : 'page content'
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

  private function generateAIImage(string $title): ?int
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
                'parts' => [['text' => 'Cape Town cityscape']],
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
}


