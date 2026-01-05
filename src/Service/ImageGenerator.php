<?php

namespace Drupal\rw_generate\Service;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\user\Entity\User;
use GuzzleHttp\Client;
use Drupal\Core\Entity\EntityTypeManagerInterface;

class ImageGenerator {

  private Client $client;
  private string $apiKey;
  private FileSystemInterface $fileSystem;
  private EntityTypeManagerInterface $entityTypeManager;

  public function __construct(Client $client, FileSystemInterface $fileSystem, EntityTypeManagerInterface $entityTypeManager) {
    $this->client = $client;
    $this->apiKey = getenv('GEMINI_API_KEY') ?: '';
    $this->fileSystem = $fileSystem;
    $this->entityTypeManager = $entityTypeManager;
  }

  public function generateAIImage(string $imageKeyword = 'abstract image', int $uid): ?int {
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
        \Drupal::logger('rw_generate')->warning('No inline image data found in Gemini response.');
        return null;
      }

      $imageBinary = base64_decode($inline['data'], true);
      if ($imageBinary === false) {
        \Drupal::logger('rw_generate')->error('Failed to base64 decode image data from Gemini.');
        return null;
      }

      $mimeType = $inline['mimeType'] ?? 'image/png';
      $ext = match ($mimeType) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
        default      => 'png',
      };

      $imageName = 'ai_image_' . time() . '_' . uniqid() . '.' . $ext;
      $uri = 'public://' . $imageName;

      $file_saved = $this->fileSystem->saveData($imageBinary, $uri, FileSystemInterface::EXISTS_REPLACE);
      if ($file_saved === FALSE) {
        \Drupal::logger('rw_generate')->error('Failed to save image file to @uri.', ['@uri' => $uri]);
        return null;
      }

      $file = File::create(['uri' => $uri, 'status' => 1, 'uid' => $uid]);
      $file->save();

      $media = Media::create([
        'bundle' => 'image',
        'name' => $imageName,
        'status' => 1,
        'uid' => $uid,
        'field_media_image' => ['target_id' => $file->id()]
      ]);
      $media->save();

      return $media->id();
    } catch (\Exception $e) {
      \Drupal::logger('rw_generate')->error('Gemini API failure: @msg', ['@msg' => $e->getMessage()]);
      return null;
    }
  }
}
