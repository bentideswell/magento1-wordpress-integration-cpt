<?php
/**
 * @category    Fishpig
 * @package     Fishpig_Wordpress
 * @license     http://fishpig.co.uk/license.txt
 * @author      Ben Tideswell <help@fishpig.co.uk>
 * @SkipObfuscation
 */

class Fishpig_Wordpress_Addon_CPT_IndexController extends Fishpig_Wordpress_Controller_Abstract
{
	/**
	 * Feed block for the archive RSS feed for the post type
	 *
	 * @var string
	 */
	protected $_feedBlock = 'wp_addon_cpt/view';
	
	/**
	 * Used to do things en-masse
	 * eg. include canonical URL
	 *
	 * @return false|Fishpig_Wordpress_Model_Post_Category
	 */
	public function getEntityObject()
	{
		if (($type = Mage::registry('wordpress_post_type')) !== null) {
			return $type;
		}

		return false;
	}

	/**
	  * Display the category page and list blog posts
	  *
	  */
	public function viewAction()
	{
		$type = $this->getEntityObject();
		
		$this->_addCustomLayoutHandles(array(
			'wordpress_post_customtype', // legacy
			'wordpress_' . $type->getPostType() . '_list',
			'wordpress_post_list_' . strtoupper($type->getPostType()), // legacy
			'wordpress_post_type_' . $type->getPostType(),  // legacy
			'wordpress_post_list',
		));

		$this->_initLayout();
		
		$this->_title($type->getName());
		
		if (($headBlock = $this->getLayout()->getBlock('head')) !== false) {
			$headBlock->addItem('link_rel', 
				$type->getArchiveUrl() . 'feed/',
				'rel="alternate" type="application/rss+xml" title="' . $type->getName() . ' &raquo; Feed"'
			);
		}
		
		$this->renderLayout();
	}
	
	
	/*
	 * Get the breadcrumbs for the entity
	 *
	 * @param  array $objects
	 * @return void
	 */
	protected function _getEntityCrumbs(array &$objects)
	{
		$type = $this->getEntityObject();
		
		$objects['post_type_' . $type->getPostType()] = array(
			'link' => '', 'label' => $type->getName(),
		);
	}
}
