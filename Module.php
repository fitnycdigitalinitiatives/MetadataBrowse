<?php

namespace MetadataBrowse;

use MetadataBrowse\Form\ConfigForm;
use Omeka\Module\AbstractModule;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\View\Renderer\PhpRenderer;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Mvc\MvcEvent;
use Omeka\Permissions\Acl;

class Module extends AbstractModule
{
    public function onBootstrap(MvcEvent $event)
    {
        parent::onBootstrap($event);

        $acl = $this->getServiceLocator()->get('Omeka\Acl');
        $acl->allow(
            [
                Acl::ROLE_EDITOR,
                Acl::ROLE_GLOBAL_ADMIN,
                Acl::ROLE_SITE_ADMIN,
            ],
            ['MetadataBrowse\Controller\Admin\Index']
            );
    }

    public function uninstall(ServiceLocatorInterface $serviceLocator)
    {
        $logger = $serviceLocator->get('Omeka\Logger');
        $settings = $serviceLocator->get('Omeka\Settings');
        $settings->delete('metadata_browse_properties');
        $settings->delete('metadata_browse_use_globals');

        $api = $serviceLocator->get('Omeka\ApiManager');
        $sites = $api->search('sites', [])->getContent();
        $siteSettings = $serviceLocator->get('Omeka\Settings\Site');

        foreach ($sites as $site) {
            $siteSettings->setTargetId($site->id());
            $siteSettings->delete('metadata_browse_properties');
        }
    }

    public function upgrade($oldVersion, $newVersion, ServiceLocatorInterface $serviceLocator)
    {
        //fix the double json encoding that was stored
        if (version_compare($oldVersion, '0.2.1-alpha', '<')) {
            $settings = $serviceLocator->get('Omeka\Settings');
            $globalProperties = json_decode($settings->get('metadata_browse_properties'));
            $settings->set('metadata_browse_properties', $globalProperties);

            $api = $serviceLocator->get('Omeka\ApiManager');
            $sites = $api->search('sites', [])->getContent();
            $siteSettings = $serviceLocator->get('Omeka\Settings\Site');

            foreach ($sites as $site) {
                $siteSettings->setTargetId($site->id());
                $currentSiteSettings = json_decode($siteSettings->get('metadata_browse_properties'));
                $siteSettings->set('metadata_browse_properties', $currentSiteSettings);
            }
        }
    }

    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        $sharedEventManager->attach(
                'Omeka\Api\Representation\ValueRepresentation',
                'rep.value.html',
                [$this, 'repValueHtml'],
                100
                );

        $triggerIdentifiers = [
                'Omeka\Controller\Admin\Item',
                'Omeka\Controller\Admin\ItemSet',
                'Omeka\Controller\Admin\Media',
                'Omeka\Controller\Site\Item',
                'Omeka\Controller\Site\ItemSet',
                'Omeka\Controller\Site\Media',
                ];
        foreach ($triggerIdentifiers as $identifier) {
            $sharedEventManager->attach(
                $identifier,
                'view.show.after',
                [$this, 'addCSS']
            );

            $sharedEventManager->attach(
                $identifier,
                'view.browse.after',
                [$this, 'addCSS']
            );
        }
    }

    public function handleConfigForm(AbstractController $controller)
    {
        $params = $controller->params()->fromPost();
        if (isset($params['propertyIds'])) {
            $propertyIds = $params['propertyIds'];
        } else {
            $propertyIds = [];
        }
        $globalSettings = $this->getServiceLocator()->get('Omeka\Settings');
        $globalSettings->set('metadata_browse_properties', $propertyIds);
        $globalSettings->set('metadata_browse_use_globals', $params['metadata_browse_use_globals']);
        $directLinks = $this->getServiceLocator()->get('Omeka\Settings');
        $directLinks->set('metadata_browse_direct_links', $params['metadata_browse_direct_links']);
    }

    public function getConfigForm(PhpRenderer $renderer)
    {
        $globalSettings = $this->getServiceLocator()->get('Omeka\Settings');
        $filteredPropertyIds = json_encode($globalSettings->get('metadata_browse_properties'));
        $escape = $renderer->plugin('escapeHtml');
        $translator = $this->getServiceLocator()->get('MvcTranslator');
        $html = '';
        $html .= "<script type='text/javascript'>
        var filteredPropertyIds = $filteredPropertyIds;
        </script>
        ";
        $formElementManager = $this->getServiceLocator()->get('FormElementManager');
        $form = $formElementManager->get(ConfigForm::class, []);
        $html .= "<p>" . $translator->translate("If checked, the properties selected below will be linked on the admin side, overriding all site-specific settings. Each site's own settings will be reflected on the public side. Otherwise, the admin side will reflect the aggregated settings for all sites; anything selected to be a link in any site will be a link on the admin side.") . "</p>";
        $html .= $renderer->formCollection($form, false);
        $html .= "<div id='metadata-browse-properties'><p>" . $escape($translator->translate('Choose properties from the sidebar to be searchable on the admin side.')) . '</p></div>';
        $html .= $renderer->partial('metadata-browse/property-template', ['escape' => $escape, 'translator' => $translator]);
        $renderer->headScript()->appendFile($renderer->assetUrl('js/metadata-browse.js', 'MetadataBrowse'));
        $renderer->headLink()->appendStylesheet($renderer->assetUrl('css/metadata-browse.css', 'MetadataBrowse'));
        $renderer->htmlElement('body')->appendAttribute('class', 'sidebar-open');
        $selectorHtml = $renderer->propertySelector($translator->translate('Select properties to be searchable'));
        $html .= "<div class='sidebar active'>$selectorHtml</div>";

        return $html;
    }

    public function addCSS($event)
    {
        $view = $event->getTarget();
        $view->headLink()->appendStylesheet($view->assetUrl('css/metadata-browse.css', 'MetadataBrowse'));
    }

    public function repValueHtml($event)
    {
        $target = $event->getTarget();
        $controllerName = $target->resource()->getControllerName();
        if (!$controllerName) {
            return;
        }
        $propertyId = $target->property()->id();
        $propertyTerm = $target->property()->term();

        $routeMatch = $this->getServiceLocator()->get('Application')
                        ->getMvcEvent()->getRouteMatch();
        $routeMatchParams = $routeMatch->getParams();
        //setup the route params to pass to the Url helper. Both the route name and its parameters go here
        $routeParams = [
                'action' => 'browse',
        ];
        if ($routeMatch->getParam('__ADMIN__')) {
            $isSite = false;
            $globalSettings = $this->getServiceLocator()->get('Omeka\Settings');
            if ($globalSettings->get('metadata_browse_use_globals')) {
                $filteredPropertyIds = $globalSettings->get('metadata_browse_properties', []);
            } else {
                $api = $this->getServiceLocator()->get('Omeka\ApiManager');
                $sites = $api->search('sites', [])->getContent();
                $siteSettings = $this->getServiceLocator()->get('Omeka\Settings\Site');
                $filteredPropertyIds = [];
                foreach ($sites as $site) {
                    $siteSettings->setTargetId($site->id());
                    $currentSettings = $siteSettings->get('metadata_browse_properties', []);
                    $filteredPropertyIds = array_merge($currentSettings, $filteredPropertyIds);
                }
            }

            $routeParams['route'] = 'admin/default';
        } elseif ($routeMatch->getParam('__SITE__')) {
            $isSite = true;
            $siteSettings = $this->getServiceLocator()->get('Omeka\Settings\Site');
            $filteredPropertyIds = $siteSettings->get('metadata_browse_properties', []);
            $siteSlug = $routeMatch->getParam('site-slug');
            $routeParams['route'] = 'site/resource';
            $routeParams['site-slug'] = $siteSlug;
        } else {
            return;
        }

        $url = $this->getServiceLocator()->get('ViewHelperManager')->get('Url');
        $escape = $this->getServiceLocator()->get('ViewHelperManager')->get('escapeHtml');
        if (in_array($propertyId, $filteredPropertyIds)) {
          // Only apply links to Rights fields that are uri's, so descriptive rights fields are not searchable
          if (($propertyTerm == "dcterms:rights") && ($target->type() == "literal")) {
            return;
          }
            $routeParams['controller'] = $controllerName;

            $translator = $this->getServiceLocator()->get('MvcTranslator');
            $params = $event->getParams();
            $html = $params['html'];
            $isLiteral = false;
            $isURI = false;
            $isResource = false;
            switch ($target->type()) {
                case 'resource':
                    $searchTarget = $target->valueResource()->id();
                    $searchUrl = $this->resourceSearchUrl($url, $routeParams, $propertyId, $searchTarget);
                    $isResource = true;
                    break;
                case 'uri':
                    $searchTarget = $target->uri();
                    $label = $target->value();
                    $searchUrl = $this->uriSearchUrl($url, $routeParams, $propertyId, $searchTarget, $label, $isSite);
                    $isURI = true;
                    break;
                case 'literal':
                    $searchTarget = $target->value();
                    $searchUrl = $this->literalSearchUrl($url, $routeParams, $propertyId, $searchTarget, $isSite);
                    $isLiteral = true;
                    break;
                default:
                    $resource = $target->valueResource();
                    $uri = $target->uri();
                    if ($resource) {
                        $searchTarget = $target->valueResource()->id();
                        $searchUrl = $this->resourceSearchUrl($url, $routeParams, $propertyId, $searchTarget);
                    } elseif ($uri) {
                        $searchUrl = $this->uriSearchUrl($url, $routeParams, $propertyId, $uri);
                    } else {
                        $searchTarget = $target->value();
                        $searchUrl = $this->literalSearchUrl($url, $routeParams, $propertyId, $searchTarget);
                        $isLiteral = true;
                    }
            }

            switch ($controllerName) {
                case 'item':
                    $controllerLabel = 'items';
                break;
                case 'item-set':
                    $controllerLabel = 'item sets';
                break;
                default:
                    $controllerLabel = $controllerName;
                break;
            }
            $searchUrl = $escape($searchUrl);
            $globalSettings = $this->getServiceLocator()->get('Omeka\Settings');
            if($globalSettings->get('metadata_browse_direct_links') && $isLiteral == true){
                $cleanedValue = nl2br($escape($target->value()));
                $link = $html . "<a class='metadata-browse-direct-link' href='$searchUrl' aria-label='Search by this term'><i class='fas fa-search' title='Search by this term' aria-hidden='true'></i></a>";
                $event->setParam('html', $link);
            } elseif($globalSettings->get('metadata_browse_direct_links') && $isURI == true){
                $uri = $target->uri();
                $uriLabel = $target->value();
                if (filter_var($uri, FILTER_VALIDATE_URL)) {
                    if (!$uriLabel) {
                        $link = $html . "<a class='metadata-browse-direct-link' href='$searchUrl' aria-label='Search by this term'><i class='fas fa-search' title='Search by this term' aria-hidden='true'></i></a>";
                    }
                    else {
                      $link = $escape($uriLabel) . "<a class='metadata-browse-direct-link' href='$searchUrl' aria-label='Search by this term'><i class='fas fa-search' title='Search by this term' aria-hidden='true'></i></a>
                      <a class='uri-value-link info' target='_blank' href='$uri' aria-label='Source URI'><i class='fas fa-info-circle' title='Source URI'  aria-hidden='true'></i></a>";
                    }
                } else {
                    $link = $html . "<a class='metadata-browse-direct-link' href='$searchUrl' aria-label='Search by this term'><i class='fas fa-search' title='Search by this term' aria-hidden='true'></i></a>";
                }
                $event->setParam('html', $link);
            } elseif ($globalSettings->get('metadata_browse_direct_links') && $isResource == true) {
                $thumbnail = $this->getServiceLocator()->get('ViewHelperManager')->get('thumbnail');
                $resourceLink = $target->valueResource()->url();
                $link = sprintf(
                  '<div class="resource-metadata-browse">
                    <span class="resource-name">
                      %s
                    </span>
                    <a class="resource-link cube" href="%s" aria-label="Linked Resource">
                      %s
                      <i class="fas fa-cube" title="Linked Resource"><span class="sr-only">Linked Resource</span></i>
                    </a>
                    <a class="metadata-browse-direct-link" href="%s" aria-label="Search by this term"><i class="fas fa-search" title="Search by this term"  aria-hidden="true"></i></a>
                  </div>',
                  $escape($target->valueResource()->displayTitle()),
                  $resourceLink,
                  $thumbnail($target->valueResource(), 'square'),
                  $resourceLink,
                  $searchUrl
                );
                $event->setParam('html', $link);
            }
            else {
                $text = sprintf($translator->translate('See all %s with this value'), $translator->translate($controllerLabel));
                $link = "<a class='metadata-browse-link' href='$searchUrl'>$text</a>";
                $event->setParam('html', "$html $link");
            }
        }
    }

    protected function literalSearchUrl($url, $routeParams, $propertyId, $searchTarget, $isSite = "")
    {
      //Check if Solr Search is installed and if this is a request from a site
      if (($this->getServiceLocator()->get('ViewHelperManager')->has('getSearchFormForSite')) && $isSite) {
        $searchUrl = $url('site/search', ['__NAMESPACE__' => 'Search\Controller', 'controller' => 'index', 'action' => 'search'], ['query' => ['q' => '"' . addslashes($searchTarget) . '"', 'suggester' => 'true']], true);
        return $searchUrl;
      } else {
        $searchUrl = $url($routeParams['route'],
              $routeParams,
              ['query' => ['Search' => '',
                                     'property[0][property]' => $propertyId,
                                     'property[0][type]' => 'eq',
                                     'property[0][text]' => $searchTarget,
                           ],
                      ]
          );

        return $searchUrl;
      }


    }

    protected function uriSearchUrl($url, $routeParams, $propertyId, $searchTarget, $label = "", $isSite = "")
    {
        //Check if Solr Search is installed and if this is a request from a site
        if (($this->getServiceLocator()->get('ViewHelperManager')->has('getSearchFormForSite')) && $isSite) {
          $searchUrl = $url('site/search', ['__NAMESPACE__' => 'Search\Controller', 'controller' => 'index', 'action' => 'search'], ['query' => ['q' => '"' . addslashes($searchTarget) . '"', 'label' => $label, 'suggester' => 'true']], true);
          return $searchUrl;
        } else {
          $searchUrl = $url($routeParams['route'],
                $routeParams,
                  ['query' => ['Search' => '',
                      'property[0][property]' => $propertyId,
                      'property[0][type]' => 'eq',
                      'property[0][text]' => $searchTarget,
                      'property[0][label]' => $label,
                  ],
              ]
            );

          return $searchUrl;
        }


    }

    protected function resourceSearchUrl($url, $routeParams, $propertyId, $searchTarget)
    {
        $searchUrl = $url($routeParams['route'],
              $routeParams,
            ['query' => ['Search' => '',
                'property[0][property]' => $propertyId,
                'property[0][type]' => 'res',
                'property[0][text]' => $searchTarget,
            ],
            ]
          );

        return $searchUrl;
    }
}
