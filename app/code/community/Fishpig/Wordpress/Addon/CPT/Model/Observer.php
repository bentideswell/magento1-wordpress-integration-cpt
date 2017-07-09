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
		$helper = $observer->getEvent()->getHelper();
		
		if ($postTypesEncoded = $helper->getWpOption('fishpig_posttypes')) {
			$postTypes = json_decode($postTypesEncoded, true);
			
			if (is_array($postTypes)) {
				foreach($postTypes as $postType => $postTypeData) {
					$postTypes[$postType] = Mage::getModel('wordpress/post_type')
						->setData($postTypeData)
						->setPostType($postType);
				}
				
				$observer->getEvent()->getTransport()->setPostTypes($postTypes);
			}
		}
		
		return $this;
	}
	
	public function initTaxonomiesObserver(Varien_Event_Observer $observer)
	{
		if ($taxonomiesEncoded = Mage::helper('wordpress')->getWpOption('fishpig_taxonomies')) {
			$taxonomies = json_decode($taxonomiesEncoded, true);

			if (is_array($taxonomies)) {
				foreach($taxonomies as $taxonomy => $taxonomyData) {
					$taxonomies[$taxonomy] = Mage::getModel('wordpress/term_taxonomy')
						->setData($taxonomyData)
						->setTaxonomyType($taxonomy);
				}
	
				$observer->getEvent()->getTransport()->setTaxonomies($taxonomies);
			}
		}
		
		return $this;
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
	 * Apply the integration tests
	 *
	 * @param Varien_Event_Observer $observer
	 * @return $this
	 */
	public function applyIntegrationTestsObserver(Varien_Event_Observer $observer)
	{
		$observer->getEvent()
			->getHelper()
				->applyTest(array($this, 'checkForPluginInstallation'));
		
		return $this;
	}
	
	/**
	 * Check whether the plugin is installed in WP
	 * If not, try and install it
	 *
	 * @return $this
	 */
	public function checkForPluginInstallation()
	{
		if (!$this->_installPlugin()) {
			throw Fishpig_Wordpress_Exception::warning(
				'Custom Post Types',
				sprintf('Unable to find the required Custom Post Types plugin installed in WordPress. Please <a href="%s" target="_blank">click here</a> for more information.', 'http://fishpig.co.uk/magento/wordpress-integration/post-types-taxonomies/installation/#plugin')
			);	
		}

		if (!Mage::helper('wordpress')->getWpOption('fishpig_posttypes')) {
			throw  Fishpig_Wordpress_Exception::warning(
				'Custom Post Types',
				'No custom post type data found. To generate the custom post data, login to your WordPress Admin and it will be generated automatically.'
			);
		}

		return $this;
	}
	
	/**
	 * Install the plugin WordPress
	 *
	 * @return bool
	 */
	protected function _installPlugin()
	{
		return Mage::helper('wordpress/plugin')->install($this->_getPluginTarget(), $this->_getPluginSource(), true);
	}
	
	/**
	 * Get the target file for the plugin
	 *
	 * @return string|false
	 */
	protected function _getPluginTarget()
	{
		if ($path = Mage::helper('wordpress')->getWordpressPath()) {
			return rtrim($path, DS) . DS . 'wp-content' . DS . 'plugins' . DS . 'fishpig' . DS . 'custom-post-types.php';;
		}
		
		return false;
	}
	
	/**
	 * Get the source file for the plugin
	 *
	 * @return string
	 */
	protected function _getPluginSource()
	{
		return Mage::getModuleDir('', 'Fishpig_Wordpress_Addon_CPT') . DS . 'custom-post-types.php';
	}
}
