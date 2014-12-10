<?php

if (empty($wp_filesystem)) {
	require_once(ABSPATH . 'wp-admin/includes/file.php');
	WP_Filesystem();
}

class Homepage {

	var $name = 'Homepage';
	var $type = 'homepage';
	var $id;
	var $description;
	var $template;
	var $zones;
	var $assets = array();
	var $sidebars = array();
	var $rightRail = false;
	var $prominenceTerms = array();

	function __construct($options=array()) {
		$this->init($options);
		$this->load($options);
	}

	public function load($options) {
		$vars = get_object_vars($this);
		foreach ($options as $k => $v) {
			if (in_array($k, array_keys($vars)))
				$this->{$k} = $v;
		}
		if (empty($this->id))
			$this->populateId();
		else if (sanitize_title($this->id) !== $this->id)
			throw new Exception('Homepage `id` can only contain letters, numbers and hyphens.');

		$this->readZones();
		add_filter('largo_prominence_terms', array($this, 'activateTerms'), 10, 1);
	}

	public function init($options=null) {}

	public function isActiveHomepageLayout() {
		$activeLayout = largo_get_active_homepage_layout();
		return get_class($this) == $activeLayout;
	}

	public function activateTerms($largoProminenceTerms=array()) {
		if ($this->isActiveHomepageLayout())
			return array_merge($largoProminenceTerms, $this->prominenceTerms);
		else
			return $largoProminenceTerms;
	}

	public function render() {
		$vars = array(
			'templateId' => $this->id,
			'templateType' => $this->type
		);
		foreach ($this->zones as $zone) {
			if (!empty($this->{$zone})) {
				if (function_exists($this->{$zone})) {
					$vars[$zone] = call_user_func($this->{$zone});
				} else if (is_string($this->{$zone})) {
					$vars[$zone] = $this->{$zone};
				}
			} else {
				if (method_exists($this, $zone))
					$vars[$zone] = call_user_func(array($this, $zone));
			}
		}
		extract($vars);
		include_once $this->template;
	}

	public function register() {
		$this->registerSidebars();
		$this->setRightRail();
		add_action('wp_enqueue_scripts', array($this, 'enqueueAssets'), 100);
	}

	public function enqueueAssets() {
		foreach ($this->assets as $asset) {
			if (preg_match('/\.js$/', $asset[1]))
				call_user_func_array('wp_enqueue_script', $asset);
			if (preg_match('/\.css$/', $asset[1]))
				call_user_func_array('wp_enqueue_style', $asset);
		}
	}

	public function registerSidebars() {
		foreach ($this->sidebars as $sidebar) {
			preg_match('|^(.*?)(\((.*)\))?$|', trim($sidebar), $sb);
			register_sidebar( array(
				'name' => trim($sb[1]),
				'id' => largo_make_slug( trim($sb[1]) ),
				'description' => (isset( $sb[3] ) ) ? trim($sb[3]) : __('Auto-generated by current homepage template'),
				'before_widget' => '<aside id="%1$s" class="%2$s clearfix">',
				'after_widget' 	=> "</aside>",
				'before_title' 	=> '<h3 class="widgettitle">',
				'after_title' 	=> '</h3>',
			));
		}
	}

	public function setRightRail() {
		global $largo;
		$rail = $largo['home_rail'] = $this->rightRail;
	}

	private function populateId() {
		$this->id = sanitize_title($this->name);
	}

	private function readZones() {
		global $wp_filesystem;

		$contents = $wp_filesystem->get_contents($this->template);
		$tokens = token_get_all($contents);
		$filtered = array_filter($tokens, function($t) { return $t[0] == T_VARIABLE; });
		$variables = array_map(function($item) { return str_replace("$", "", $item[1]); }, $filtered);
		$uniques = array_values(array_unique($variables));

		$this->zones = $uniques;
	}
}

class HomepageLayoutFactory {
	var $layouts = array();

	function __construct() {
		add_action('init', array($this, 'register_active_layout'), 100);
	}

	function register($layoutClass) {
		$this->layouts[$layoutClass] = new $layoutClass();
	}

	function unregister($layoutClass) {
		if (isset($this->layouts[$layoutClass]))
			unset($this->layouts[$layoutClass]);
	}

	function register_active_layout() {
		$active = largo_get_active_homepage_layout();
		if (!empty($active) && !empty($this->layouts[$active]))
			$this->layouts[$active]->register();
	}
}

function register_homepage_layout($layoutClass) {
	global $largo_homepage_factory;

	$largo_homepage_factory->register($layoutClass);
}

function unregister_homepage_layout($layoutClass) {
	global $largo_homepage_factory;

	$largo_homepage_factory->unregister($layoutClass);
}

$GLOBALS['largo_homepage_factory'] = new HomepageLayoutFactory();
