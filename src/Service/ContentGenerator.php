<?php

namespace Drupal\rw_generate\Service;

use Symfony\Component\Yaml\Yaml;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Password\PasswordGeneratorInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ContentGenerator
{
  private EntityTypeManagerInterface $entityTypeManager;
  private EntityFieldManagerInterface $entityFieldManager;
  private PasswordGeneratorInterface $passwordGenerator;
  private TextGenerator $textGenerator;
  private ImageGenerator $imageGenerator;
  private array $siteContext;

  public function __construct(EntityTypeManagerInterface $entityTypeManager, EntityFieldManagerInterface $entityFieldManager, PasswordGeneratorInterface $passwordGenerator, TextGenerator $textGenerator, ImageGenerator $imageGenerator, ConfigFactoryInterface $configFactory)
  {
    $this->entityTypeManager = $entityTypeManager;
    $this->entityFieldManager = $entityFieldManager;
    $this->passwordGenerator = $passwordGenerator;
    $this->textGenerator = $textGenerator;
    $this->imageGenerator = $imageGenerator;
    $context = $configFactory->get('rw_generate.site_context')->get('context');
    $this->siteContext = $context ? Yaml::parse($context) : [];
  }

  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('password_generator'),
      $container->get('rw_generate.text_generator'),
      $container->get('rw_generate.image_generator'),
      $container->get('config.factory')
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

    $aiUser = $this->getAIUser();

    for ($i = 0; $i < $count; $i++) {
      $node = NULL;
      try {
        $nodeTypeManager = $this->entityTypeManager->getStorage('node_type');
        if (!$nodeTypeManager->load($contentType)) {
          \Drupal::logger('rw_generate')->warning('Content type "@type" does not exist. Skipping node generation.', ['@type' => $contentType]);
          continue;
        }

        $node = Node::create([
          'type' => $contentType,
          'title' => 'Generating...',
          'uid' => $aiUser->id(),
        ]);

        $currentPromptParameters = $promptCombinations[$i] ?? [];
        $field_definitions = $this->entityFieldManager->getFieldDefinitions('node', $contentType);

        foreach ($fields as $field_name) {
          if ($node->hasField($field_name)) {
            $field_definition = $field_definitions[$field_name];
            $field_type = $field_definition->getType();
            if (in_array($field_type, ['text', 'text_long', 'text_with_summary', 'string'])) {
              $textData = $this->textGenerator->generateAIText($currentPromptParameters, $field_definition->getLabel());
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
              $mediaId = $this->imageGenerator->generateAIImage($imageKeyword, $aiUser->id());
              if ($mediaId) {
                $alt_text = 'AI generated image';
                try {
                  $alt_text = $this->textGenerator->generateAIText($currentPromptParameters, 'Image alt text')['title'];
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

  private function getAIUser(): User
  {
    $user_storage = $this->entityTypeManager->getStorage('user');
    $ai_users = $user_storage->loadByProperties(['name' => 'AI Content Generator']);
    if ($ai_users) {
      return reset($ai_users);
    } else {
      $user = User::create([
        'name' => 'AI Content Generator',
        'mail' => 'ai@example.com',
        'pass' => $this->passwordGenerator->generate(),
        'status' => 1,
      ]);
      $user->save();
      return $user;
    }
  }
}