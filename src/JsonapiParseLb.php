<?php

namespace Drupal\jsonapi_include_lb;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Layout\LayoutPluginManagerInterface;
use Drupal\jsonapi_include\JsonapiParseInterface;
use Drupal\layout_builder\Context\LayoutBuilderContextTrait;
use Drupal\layout_builder\Entity\LayoutEntityDisplayInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\jsonapi\Routing\Routes as JsonApiRoutes;
use Drupal\jsonapi\ResourceType\ResourceTypeRepositoryInterface;
use Drupal\jsonapi\Controller\EntityResource;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class JsonapiParseLb.
 *
 * Jsonapi Layout Builder include Parser.
 *
 * @package Drupal\jsonapi_include
 */
class JsonapiParseLb implements JsonapiParseInterface {

  /**
   * The root parser.
   *
   * @var \Drupal\jsonapi_include\JsonapiParseInterface
   */
  protected JsonapiParseInterface $rootParser;

  /**
   * The 'entity_type.manager' service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The 'jsonapi.resource_type.repository' service.
   *
   * @var \Drupal\jsonapi\ResourceType\ResourceTypeRepositoryInterface
   */
  protected ResourceTypeRepositoryInterface $resourceTypeRepository;
  /**
   * The 'jsonapi.entity_resource' service.
   *
   * @var \Drupal\jsonapi\Controller\EntityResource
   */
  protected EntityResource $jsonapiEntityResource;

  /**
   * The jsonapi base path.
   *
   * @var string
   */
  protected string $jsonapiBasePath;

  /**
   * The layout plugin manager service.
   *
   * @var \Drupal\Core\Layout\LayoutPluginManagerInterface
   */
  protected $layoutPluginManager;
  /**
   * JsonapiParseLb constructor.
   *
   * @param \Drupal\jsonapi_include\JsonapiParseInterface $rootParser
   *   The decorated parser.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The 'entity_type.manager' service.
   * @param \Drupal\jsonapi\ResourceType\ResourceTypeRepositoryInterface $resourceTypeRepository
   *   The 'jsonapi.resource_type.repository' service.
   * @param \Drupal\jsonapi\Controller\EntityResource $jsonapiEntityResource
   *   The 'jsonapi.entity_resource' service.
   * @param string $jsonapiBasePath
   *   The jsonapi base path.
   * @param \Drupal\Core\Layout\LayoutPluginManagerInterface $layoutPluginManager
   *   The layout plugin manager service.
   */
  public function __construct(
    JsonapiParseInterface $rootParser,
    EntityTypeManagerInterface $entityTypeManager,
    ResourceTypeRepositoryInterface $resourceTypeRepository,
    EntityResource $jsonapiEntityResource,
    string $jsonapiBasePath,
    LayoutPluginManagerInterface $layoutPluginManager,
  ) {
    $this->rootParser = $rootParser;
    $this->entityTypeManager = $entityTypeManager;
    $this->resourceTypeRepository = $resourceTypeRepository;
    $this->jsonapiEntityResource = $jsonapiEntityResource;
    $this->jsonapiBasePath = $jsonapiBasePath;
    $this->layoutPluginManager = $layoutPluginManager;
  }

  /**
   * Parse Resource.
   *
   * @param array $item
   *   The data for resolve.
   * @param \Symfony\Component\HttpFoundation\Response $response
   *   A Response with JSON:API data.
   *
   * @return array
   *   The modified item. All cache dependencies will be added to the Response
   *   object.
   */
  protected function parseResource($item, Response $response) {
    // @todo: possible to get the "view mode" here? Also can this work for any entity type?
    // The view mode should be checked because some view modes may allow LB, and may allow overrides
    [$entity_type, $bundle] = explode('--', $item['type']);
    $storage = $this->entityTypeManager->getStorage('entity_view_display');
    $entity_view_display = $storage->load($entity_type . '.' . $bundle . '.default');
  
    if (!$entity_view_display instanceof LayoutEntityDisplayInterface || !$entity_view_display->isLayoutBuilderEnabled()) {
      return $item;
    }
  
    // Check if the entity display has a managed layout since the viewed entity layout is empty.
    if (empty($item['layout_builder__layout'])) {
      $third_party_settings = $entity_view_display->getThirdPartySettings('layout_builder');
      // @todo: Any better way of handling Section entity objects?
      if ($third_party_settings['enabled']) {
        foreach ($third_party_settings['sections'] as $section) {
          $item['layout_builder__layout'][] = $section->toArray();
        }
  
        // @todo: We should cache the dependency only if this layout view mode matches the display above.
        $response->addCacheableDependency($entity_view_display);
      }
    }
  
    if (isset($item['layout_builder__layout'])) {
      $storage = $this->entityTypeManager->getStorage('block_content');
      $sections = &$item['layout_builder__layout'];
      foreach ($sections as &$section) {
        if (isset($section['components'])) {
          foreach ($section['components'] as &$component) {
            switch ($component['configuration']['provider'] ?? NULL) {
              case 'block_content':
                [, $blockUuid] = explode(':', $component['configuration']['id']);
                break;

              case 'layout_builder':
                $blockUuid = $component['configuration']['uuid'] ?? NULL;
                break;

              default:
                $blockUuid = NULL;
            }
            // @todo Optimize to gather all uuids to an array and load all
            // entities via a single query to improve the performance.
            if (
              isset($blockUuid)
              && $entity = current($storage->loadByProperties([
                'uuid' => $blockUuid,
              ]))) {
              $blockResponse = $this->getEntityJsonapiResponse($entity);
              $blockContent = $blockResponse->getContent();
              $blockData = Json::decode($blockContent)['data'];
              $response->addCacheableDependency($blockResponse);
              $component['block'] = $blockData;
            }
          }
        }
      }
    }

    $this->sortSectionComponents($item['layout_builder__layout']);
    return $item;
  }

  /**
   * {@inheritdoc}
   */
  public function parse($response) {
    // Requires a patch for jsonapi_include from the issue
    // https://www.drupal.org/project/jsonapi_include/issues/3374410.
    $this->rootParser->parse($response);
    $this->parseBlocks($response);
    return $response;
  }

  /**
   * Check array is assoc.
   *
   * @param array $arr
   *   The array.
   *
   * @return bool
   *   Check result.
   */
  protected function isAssoc(array $arr) {
    if ([] === $arr) {
      return FALSE;
    }
    return array_keys($arr) !== range(0, count($arr) - 1);
  }

  /**
   * Parse json api.
   *
   * @param \Symfony\Component\HttpFoundation\Response $response
   *   A Response with JSON:API data.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The Response object, modifications will be done in place.
   */
  protected function parseBlocks(Response $response) {
    $json = Json::decode($response->getContent());
    if (isset($json['errors']) || empty($json['data'])) {
      return $json;
    }
    if (!$this->isAssoc($json['data'])) {
      foreach ($json['data'] as $item) {
        $data[] = $this->parseResource($item, $response);
      }
    }
    else {
      $data = $this->parseResource($json['data'], $response);
    }
    $json['data'] = $data;
    $response->setContent(Json::encode($json));
    return $response;
  }

  /**
   * Sort blocks within sections and regions by their weights so the order is correct in JSON:API.
   *
   * @param array $section_data
   *   A set of layouts and components within a section.
   *
   * @return array
   *   The sorted components.
   */
  protected function sortSectionComponents(array &$section_data = []): array {
    foreach ($section_data as &$section_components) {
      /** @var \Drupal\Core\Layout\LayoutDefinition $layout */
      $layout = $this->layoutPluginManager->getDefinition($section_components['layout_id']);

      if (count($layout->getRegions()) > 1) {
        // We can only assume the defined region list is the 'order'.
        $regions = array_flip(array_keys($layout->getRegions()));

        // Sort the regions
        usort($section_components['components'], function($a, $b) use ($regions) {
          return $regions[$a['region']] <=> $regions[$b['region']];
        });

        // Now sort within the regions
        usort($section_components['components'], function($a, $b) use ($regions) {
          if ($regions[$a['region']] === $regions[$b['region']]) {
            return $a['weight'] <=> $b['weight'];
          }

          // What can we do with blocks that have the same weight value in one region?
          return 0;
        });
      } else {
        usort($section_components['components'], fn($a, $b) => $a['weight'] <=> $b['weight']);
      }
    }

    return $section_data;
  }

  /**
   * Returns the JSON:API response for an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   An entity to get the response.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The JSON:API representation of the entity data.
   */
  private function getEntityJsonapiResponse(EntityInterface $entity): Response {
    $resourceType = $this->resourceTypeRepository->get($entity->getEntityTypeId(), $entity->bundle());
    // Getting list of default includes for block by jsonapi_defaults module,
    // should return empty array if module is missing.
    $defaultIncludes = $resourceType->getJsonapiResourceConfig()->getThirdPartySetting(
      'jsonapi_defaults',
      'default_include',
      []
    );
    $requestUri = strtr('/{jsonapi_url}/{entity_type}/{bundle}/{uuid}', [
      '{jsonapi_url}' => 'jsonapi',
      '{entity_type}' => $entity->getEntityTypeId(),
      '{bundle}' => $entity->bundle(),
      '{uuid}' => $entity->uuid(),
    ]);
    // @todo Invent a better way to create a request without manually filling
    // server valuus.
    $request = new Request(
      [
        'jsonapi_include' => 1,
      ],
      [],
      [JsonApiRoutes::RESOURCE_TYPE_KEY => $resourceType],
      [],
      [],
      [
        'REQUEST_URI' => $requestUri,
        'HTTP_HOST' => $_SERVER['HTTP_HOST'],
      ],
    );
    $response = $this->jsonapiEntityResource->getIndividual($entity, $request);
    $data = \Drupal::service('jsonapi.serializer')->normalize($response->getResponseData(), 'api_json')->getNormalization();
    $response->setContent(Json::encode($data));
    \Drupal::request()->query->set('include', implode(',', $defaultIncludes));
    $this->rootParser->parse($response);
    $response->addCacheableDependency($entity);
    return $response;
  }

}
