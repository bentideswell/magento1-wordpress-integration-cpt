<?php
/**
 * @category    Fishpig
 * @package     Fishpig_Wordpress
 * @license     http://fishpig.co.uk/license.txt
 * @author      Ben Tideswell <help@fishpig.co.uk>
 */
 
class Fishpig_Wordpress_Addon_CPT_Model_Observer extends Varien_Object
{
	/**
	 * Determine whether the CPT plugin is enabled in WordPress
	 *
	 * @return bool
	 */
	public function isPluginEnabled()
	{
		return Mage::helper('wordpress')->isPluginEnabled('fishpig/custom-post-types.php');
	}
	
	/**
	 * Attempt to match a WP route to a custom post type
	 *
	 * @param Varien_Event_Observer $observer
	 * @return $this
	 */
	public function matchRoutesObserver(Varien_Event_Observer $observer)
	{
		$observer->getEvent()
			->getRouter()
				->addRouteCallback(array($this, 'getRoutes'));
		
		return $this;
	}
	
	/**
	 * Generate routes based on $uri
	 *
	 * @param string $uri = ''
	 * @return $this
	 */
	public function getRoutes($uri = '')
	{
		if ($postTypes = Mage::helper('wordpress/app')->getPostTypes()) {
			foreach($postTypes as $postType) {
				if (!$postType->hasArchive()) {
					continue;
				}

				if ($uri !== $postType->getArchiveSlug()) {
					continue;
				}

				Mage::app()->getFrontController()
					->getRouter('wordpress')
						->addRoute($postType->getArchiveSlug(), 'wp_addon_cpt/index/view')
						->addRoute($postType->getArchiveSlug() . '/feed', 'wp_addon_cpt/index/feed');

				Mage::register('wordpress_post_type', $postType);

				break;
			}
		}

		return $this;
	}
	
	public function initPostTypesObserver(Varien_Event_Observer $observer)
	{
		try {
			$cacheKey = 'posttype_' . Mage::app()->getStore()->getId();

			if ($postTypes = $this->_getCache()->load($cacheKey)) {
				$postTypes = json_decode($postTypes, true);
			}
			
			if (!$postTypes) {
				if ($postTypes = $this->_getPostTypeDataFromWordPress()) {
					$this->_getCache()->save(json_encode($postTypes), $cacheKey);
				}
			}

			if (!$postTypes) {
				throw new Exception('Unable to get post types.');
			}

			foreach($postTypes as $type => $data) {
				$postTypes[$type] = Mage::getModel('wordpress/post_type')->setData($data)->setPostType($type);
			}

			$observer->getEvent()->getTransport()->setPostTypes($postTypes);
		}
		catch (Exception $e) {
			Mage::helper('wordpress')->log($e->getMessage());
		}

		return $this;
	}
	
	/*
	 * Get post type data from WordPress
	 *
	 * @return array|false
	 */
	protected function _getPostTypeDataFromWordPress()
	{
		try {
			return $this->simulatedCallback(function() {
				if (!defined('ABSPATH')) {
					return false;
				}
	
				$postTypesToIgnore = array('attachment', 'nav_menu_item', 'revision');
	
				// Include plugin.php so we can check whether WPPermastructure is active
				include_once ABSPATH . 'wp-admin/includes/plugin.php';
			
				$wpPermastructureActive = is_plugin_active('wp-permastructure/wp-permastructure.php');
		
				if (!($customPostTypes = get_post_types(array('_builtin' => false, 'public' => true), 'objects'))) {
					return false;
				}
				
				$postTypeData = array_merge($customPostTypes, get_post_types(array('_builtin' => true), 'objects'));
	
				foreach($postTypeData as $type => $data) {
					if (in_array($type, $postTypesToIgnore)) {
						unset($postTypeData[$type]);
						continue;
					}
		
					$data = json_decode(json_encode($data), true);
		
					if ($type === 'post') {
						$data['rewrite'] = array(
							'slug' => get_option('permalink_structure')
						);
						
						$data['taxonomies'] = array_merge($data['taxonomies'], array('category', 'post_tag'));
					}
					else if ($type === 'page') {
						$data['rewrite'] = array(
							'slug' => '%postname%/',
						);				
					}
					else if ($wpPermastructureActive && $wpPermastructure = get_option($type . '_permalink_structure')) {
						$data['rewrite']['slug'] = $wpPermastructure;
					}
	
					// Convert any stdClass instances to an array
					$postTypeData[$type] = $data;
				}
	
				return $postTypeData;
			});
		}
		catch (Exception $e) {
			return false;
		}
	}
	
	public function initTaxonomiesObserver(Varien_Event_Observer $observer)
	{
		try {
			$cacheKey = 'taxonomy_' . Mage::app()->getStore()->getId();

			if ($taxonomies = $this->_getCache()->load($cacheKey)) {
				$taxonomies = json_decode($taxonomies, true);
			}

			if (!$taxonomies) {
				if ($taxonomies = $this->_getTaxonomyDataFromWordPress()) {
					$this->_getCache()->save(json_encode($taxonomies), $cacheKey);
				}
			}

			if (!$taxonomies) {
				throw new Exception('Unable to get taxonomies.');
			}

			foreach($taxonomies as $taxonomy => $data) {
				$taxonomies[$taxonomy] = Mage::getModel('wordpress/term_taxonomy')->setData($data)->setTaxonomyType($taxonomy);
			}

			$observer->getEvent()->getTransport()->setTaxonomies($taxonomies);
		}
		catch (Exception $e) {
			Mage::helper('wordpress')->log($e->getMessage());
		}
		
		return $this;
	}
	
	/*
	 *
	 *
	 * @return array|false
	 */
	protected function _getTaxonomyDataFromWordPress()
	{
		try {
			return $this->simulatedCallback(function() {
				if (!($customTaxonomies = get_taxonomies(array('_builtin' => false), 'objects'))) {
					return false;
				}
				
				$taxonomiesToIgnore = array('nav_menu', 'link_category', 'post_format');
				$taxonomyData = array_merge($customTaxonomies, get_taxonomies(array('_builtin' => true), 'objects'));
		
				$blogPrefix = is_multisite() && !is_subdomain_install() && is_main_site();
		
				foreach($taxonomyData as $taxonomy => $data) {
					if (in_array($taxonomy, $taxonomiesToIgnore)) {
						unset($taxonomyData[$taxonomy]);
						continue;
					}
		
					$data = json_decode(json_encode($data), true);
		
					if ($blogPrefix && isset($data['rewrite']) && isset($data['rewrite']['slug'])) {
						if (strpos($data['rewrite']['slug'], 'blog/') === 0) {
							$data['rewrite']['slug'] = substr($data['rewrite']['slug'], strlen('blog/'));
						}
					}
					
					$taxonomyData[$taxonomy] = $data;
				}
		
				return $taxonomyData;
			});
		}
		catch (Exception $e) {
			return false;
		}
	}
	
	/**
	 * Add custom post types to association collections
	 *
	 * @param Varien_Event_Observer $observer
	 * @return $this
	 */
	public function wordpressAssociationPostCollectionLoadBeforeObserver(Varien_Event_Observer $observer)
	{
		$posts = $observer
			->getEvent()
				->getCollection();

		if ($posts && $postTypes = Mage::helper('wordpress/app')->getPostTypes()) {
			if (count($postTypes) > 0) {
				$postTypes = array_merge(array('post'), array_keys($postTypes));

				$posts->addPostTypeFilter($postTypes);

				$grid = $observer
					->getEvent()
						->getGrid();
				
				if ($grid) {
					$grid->addColumnAfter('post_type', array(
						'header'=> 'Type',
						'index' => 'post_type',
						'type' => 'options',
						'options' => array_combine($postTypes, $postTypes),
					), 'post_title');
					
					$grid->sortColumnsByOrder();
				}
			}
		}
		
		return $this;
	}

	/**
	 * Add custom post types to integrated search
	 *
	 * @param Varien_Event_Observer $observer
	 * @return $this
	 */
	public function addPostsToIntegratedSearchObserver(Varien_Event_Observer $observer)
	{
		if (Mage::app()->getRequest()->getParam('post_type') === '*') {
			return $this;
		}

		if ($postTypes = Mage::helper('wordpress/app')->getPostTypes()) {
			$tabs = $observer->getEvent()
				->getTransport()
					->getTabs();
			
			$searchTerm = $observer->getEvent()
				->getParsedSearchTerm();
				
			foreach($postTypes as $alias => $postType) {
				if ($alias === 'post') {
					continue;
				}

				if ((int)$postType->getExcludeFromSearch() === 1) {
					continue;
				}

				$listBlock = Mage::getSingleton('core/layout')->createBlock('wordpress/post_list')
					->setTemplate('wordpress/post/list.phtml');
				
				$wrapperBlock = Mage::getSingleton('core/layout')->createBlock('wp_addon_cpt/view')
					->setPostType($postType)
					->setParsedSearchTerm($searchTerm)
					->setChild('post_list', $listBlock);
				
				if (($searchHtml = trim($wrapperBlock->getPostListHtml())) !== '') {
					$tabs[] = array(
						'alias' => $alias,
						'html' => $searchHtml,
						'title' => Mage::helper('wordpress')->__($postType->getPluralName() ? $postType->getPluralName() : $postType->getName()),
					);
				}
			}
			
			$observer->getEvent()
				->getTransport()
					->setTabs($tabs);
		}

		return $this;
	}
	
	/**
	 * Perform a callback during WordPress simulation mode
	 *
	 * @param $callback
	 * @return mixed
	**/
	public function simulatedCallback($callback, array $params = array())
	{
		$coreHelper = Mage::helper('wp_addon_cpt/core');
		$result = null;
		
		if ($coreHelper->isActive()) {
			try {
				$coreHelper->startWordPressSimulation();
				
				$result = call_user_func_array($callback, $params);
				
				$coreHelper->endWordPressSimulation();
			}
			catch (\Exception $e) {
				$coreHelper->endWordPressSimulation();
				Mage::helper('wordpress')->log($e->getMessage());
			}
		}
		
		return $result;
	}
	
	/*
	 *
	 *
	 * @return
	 */
	protected function _getCache()
	{
		return Mage::getSingleton('core/cache');
	}
}
