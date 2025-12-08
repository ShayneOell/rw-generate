<?php

namespace Drupal\rw_generate\Service;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Symfony\Component\Yaml\Yaml;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use GuzzleHttp\Client;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\taxonomy\Entity\Term;
use Drupal\node\Entity\Node;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslationManager;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;

class AIContentGenerator
{
  private Client $client;
  private string $apiKey;
  private FileSystemInterface $fileSystem;
  private ModuleHandlerInterface $moduleHandler;
  private ConfigFactoryInterface $configFactory;
  private array $siteContext;
  private EntityTypeManagerInterface $entityTypeManager;
  private EntityFieldManagerInterface $entityFieldManager;
  private EntityTypeBundleInfoInterface $entityTypeBundleInfo;
  private TranslationManager $stringTranslation;


  public function __construct(Client $client, FileSystemInterface $fileSystem, ModuleHandlerInterface $moduleHandler, ConfigFactoryInterface $configFactory, EntityTypeManagerInterface $entityTypeManager, EntityFieldManagerInterface $entityFieldManager, EntityTypeBundleInfoInterface $entityTypeBundleInfo, TranslationManager $stringTranslation)
  {
    $this->client = $client;
    $this->apiKey = getenv('GEMINI_API_KEY') ?: '';
    $this->fileSystem = $fileSystem;
    $this->moduleHandler = $moduleHandler;
    $this->configFactory = $configFactory;
    $this->siteContext = $this->loadSiteContext();
    $this->entityTypeManager = $entityTypeManager;
    $this->entityFieldManager = $entityFieldManager;
    $this->entityTypeBundleInfo = $entityTypeBundleInfo;
    $this->stringTranslation = $stringTranslation;
  }

  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('http_client'),
      $container->get('file_system'),
      $container->get('module_handler'),
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('entity_type.bundle.info'),
      $container->get('string_translation')
    );
  }

  public function generateNodes(int $count, string $contentType, bool $generateImages, array $fields = []): int
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

    $term = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['vid' => 'rw_generated', 'name' => 'Generated']);
    $generatedTerm = reset($term);

    for ($i = 0; $i < $count; $i++) {
      $node = NULL;
      try {
        $nodeTypeManager = \Drupal::entityTypeManager()->getStorage('node_type');
        if (!$nodeTypeManager->load($contentType)) {
          \Drupal::logger('rw_generate')->warning('Content type "@type" does not exist. Skipping node generation.', ['@type' => $contentType]);
          continue;
        }

        $this->ensureGeneratedTagFieldExists('node', $contentType);

        $node = Node::create([
          'type' => $contentType,
          'title' => 'Generating...',
        ]);

        if ($generatedTerm) {
          $node->get('field_rw_generated_tag')->appendItem($generatedTerm);
        } elseif (!$generatedTerm) {
          \Drupal::logger('rw_generate')->warning('Taxonomy term "Generated" not found. Node will not be tagged.', ['@type' => $contentType]);
        }


        $currentPromptParameters = $promptCombinations[$i] ?? [];
        $field_definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', $contentType);

        foreach ($fields as $field_name) {
          if ($node->hasField($field_name)) {
            $field_definition = $field_definitions[$field_name];
            $field_type = $field_definition->getType();
            if (in_array($field_type, ['text', 'text_long', 'text_with_summary', 'string'])) {
              $textData = $this->generateAIText($currentPromptParameters, $field_definition->getLabel());
              if ($field_name == 'title') {
                $node->setTitle($textData['title']);
              } else {
                $node->set($field_name, [
                  'value' => $textData['body'],
                  'format' => 'full_html',
                ]);
              }
            } elseif ($field_type == 'entity_reference' && $field_definition->getSetting('target_type') == 'media' && $generateImages) {
              $imageKeyword = $imageKeywords[$i % count($imageKeywords)];
              $mediaId = $this->generateAIImage($imageKeyword);
              if ($mediaId) {
                $alt_text = 'AI generated image';
                try {
                  $alt_text = $this->generateAIText($currentPromptParameters, 'Image alt text')['title'];
                } catch (\Exception $e) {
                  \Drupal::logger('rw_generate')->warning('Failed to generate alt text: @msg', ['@msg' => $e->getMessage()]);
                }
                $node->set($field_name, [
                  [
                    'target_id' => $mediaId,
                    'alt' => $alt_text,
                  ]
                ]);
              }
            }
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

  private function generateAIText(array $promptParameters = [], string $field_label = 'text'): array
  {
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

    $term = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['vid' => 'rw_generated', 'name' => 'Generated']);
    $generatedTerm = reset($term);

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
        \Drupal::logger('rw_generate')->warning('No inline image data found in Gemini response. Image generation failed or returned empty data.');
        return $this->createPlaceholderMedia($time);
      }

      $imageBinary = base64_decode($inline['data'], true);
      if ($imageBinary === false) {
        \Drupal::logger('rw_generate')->error('Failed to base64 decode image data from Gemini. Raw data length: @length', ['@length' => strlen($inline['data'])]);
        return $this->createPlaceholderMedia($time);
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

      $file_saved = $this->fileSystem->saveData($imageBinary, $uri, FileSystemInterface::EXISTS_REPLACE);
      if ($file_saved === FALSE) {
        \Drupal::logger('rw_generate')->error('Failed to save image file to @uri.', ['@uri' => $uri]);
        return $this->createPlaceholderMedia($time);
      }
      \Drupal::logger('rw_generate')->debug('Image file saved to: @uri', ['@uri' => $uri]);

      $this->ensureGeneratedTagFieldExists('media', 'image');

      $file = File::create(['uri' => $uri, 'status' => 1]);
      $file->save();
      \Drupal::logger('rw_generate')->debug('File entity created with ID: @id', ['@id' => $file->id()]);

      $media = Media::create([
        'bundle' => 'image',
        'name' => $imageName,
        'status' => 1,
        'field_media_image' => ['target_id' => $file->id()],
      ]);

      if ($generatedTerm) {
        $media->get('field_rw_generated_tag')->appendItem($generatedTerm);
      } elseif (!$generatedTerm) {
        \Drupal::logger('rw_generate')->warning('Taxonomy term "Generated" not found. Media will not be tagged.');
      }

      $media->save();

      return $media->id();
    } catch (\Exception $e) {
      \Drupal::logger('rw_generate')->error('Gemini API failure: @msg', ['@msg' => $e->getMessage()]);
      return $this->createPlaceholderMedia($time);
    }
  }

  private function createPlaceholderMedia(int $time): int
  {
    $term = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['vid' => 'rw_generated', 'name' => 'Generated']);
    $generatedTerm = reset($term);

        $this->ensureGeneratedTagFieldExists('media', 'image');
    
        $placeholderName = 'ai_placeholder_image_' . $time . '.png';
        $placeholderBinary = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABAQMAAAAl21bKAAAAA1BMVEUAAACnej3aAAAAAXRSTlMAQObYZgAAAApJREFUCNdjYAAAAAIAAeIhvDMAAAAASUVORK5CYII=');
        $uri = 'public://' . $placeholderName;
    
        $file_saved = $this->fileSystem->saveData($placeholderBinary, $uri, FileSystemInterface::EXISTS_REPLACE);
        if ($file_saved === FALSE) {
          \Drupal::logger('rw_generate')->error('Failed to save placeholder image file to @uri.', ['@uri' => $uri]);
          return 0;
        }
        \Drupal::logger('rw_generate')->debug('Placeholder image file saved to: @uri', ['@uri' => $uri]);
    
        $file = File::create(['uri' => $uri, 'status' => 1]);
        $file->save();
        \Drupal::logger('rw_generate')->debug('File entity for placeholder created with ID: @id', ['@id' => $file->id()]);
    
        $media = Media::create([
          'bundle' => 'image',
          'name' => $placeholderName,
          'status' => 1,
          'field_media_image' => ['target_id' => $file->id()],
        ]);
    if ($generatedTerm) {
      $media->get('field_rw_generated_tag')->appendItem($generatedTerm);
    } elseif (!$generatedTerm) {
      \Drupal::logger('rw_generate')->warning('Taxonomy term "Generated" not found. Media will not be tagged.');
    }
    $media->save();
    return $media->id();
  }

  private function ensureGeneratedTagFieldExists(string $entity_type_id, string $bundle_id): void
  {

    $field_name = 'field_rw_generated_tag';
    $field_storage_id = $entity_type_id . '.' . $field_name;

    if (!FieldStorageConfig::load($field_storage_id)) {
      $field_storage = FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => $entity_type_id,
        'type' => 'entity_reference',
        'settings' => [
          'target_type' => 'taxonomy_term',
        ],
        'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
        'translatable' => TRUE,
        'locked' => FALSE,
        'indexes' => ['target_id' => ['target_id']],
      ]);
      $field_storage->save();
      $this->entityFieldManager->clearCachedFieldDefinitions();
      \Drupal::logger('rw_generate')->notice('Created field storage {id} for {entity_type_id}.', ['id' => $field_storage_id, 'entity_type_id' => $entity_type_id]);
    }

    $field_id = $entity_type_id . '.' . $bundle_id . '.' . $field_name;
    if (!FieldConfig::load($field_id)) {
      $field = FieldConfig::create([
        'field_storage' => FieldStorageConfig::load($field_storage_id),
        'bundle' => $bundle_id,
        'label' => $this->stringTranslation->translate('Generated Tag'),
        'description' => $this->stringTranslation->translate('Tag indicating content was generated by the RW Generate module.'),
        'required' => FALSE,
        'default_value' => [],
        'settings' => [
          'handler' => 'default:taxonomy_term',
          'handler_settings' => [
            'target_bundles' => [
              'rw_generated' => 'rw_generated',
            ],
            'auto_create' => FALSE,
          ],
        ],
        'third_party_settings' => [],
      ]);
      $field->save();
      $this->entityFieldManager->clearCachedFieldDefinitions();
      \Drupal::logger('rw_generate')->notice('Created field instance {id} for bundle {bundle_id}.', ['id' => $field_id, 'bundle_id' => $bundle_id]);

      $this->assignFieldToDisplays($entity_type_id, $bundle_id, $field_name);
      
      \Drupal::service('cache_tags.invalidator')->invalidateTags([
        'config:core.entity_view_display.' . $entity_type_id . '.' . $bundle_id . '.default',
        'config:core.entity_form_display.' . $entity_type_id . '.' . $bundle_id . '.default',
        'rendered',
      ]);
    }
  }

  private function assignFieldToDisplays(string $entity_type_id, string $bundle_id, string $field_name): void
  {
    $form_display = EntityFormDisplay::load($entity_type_id . '.' . $bundle_id . '.default');
    if (!$form_display) {
      $form_display = EntityFormDisplay::create([
        'targetEntityType' => $entity_type_id,
        'bundle' => $bundle_id,
        'mode' => 'default',
        'status' => TRUE,
      ]);
    }
    $form_display->setComponent($field_name, ['region' => 'hidden']); //this is supposed to hide the field from the form but that isnt the case, need to investigate futher
    $form_display->save();

    $view_display = EntityViewDisplay::load($entity_type_id . '.' . $bundle_id . '.default');
    if (!$view_display) {
      $view_display = EntityViewDisplay::create([
        'targetEntityType' => $entity_type_id,
        'bundle' => $bundle_id,
        'mode' => 'default',
        'status' => TRUE,
      ]);
    }
    $view_display->setComponent($field_name, ['region' => 'hidden']); 
    $view_display->save();
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
