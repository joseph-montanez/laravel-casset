<?php namespace Mmanos\Casset;

class Container
{
	/**
	 * Container name.
	 *
	 * @var string
	 */
	public $name;
	
	/**
	 * Public path.
	 *
	 * @var string
	 */
	public $public_path;
	
	/**
	 * Assets path.
	 *
	 * @var string
	 */
	public $assets_path;
	
	/**
	 * Compile path.
	 *
	 * @var string
	 */
	public $cache_path;
	
	/**
	 * Whether or not to combine resources into a single file.
	 *
	 * @var boolean
	 */
	public $combine;
	
	/**
	 * Whether or not to minify resources (combined resources only).
	 *
	 * @var boolean
	 */
	public $minify;
	
	/**
	 * All of the registered assets.
	 *
	 * @var array
	 */
	public $assets = array();
	
	/**
	 * Initialize an instance of this class.
	 *
	 * @param string $name Name of container.
	 * 
	 * @return void
	 */
	public function __construct($name)
	{
		$this->name         = $name;
		$this->combine      = \Config::get('casset::combine', true);
		$this->minify       = \Config::get('casset::minify', true);
		$this->public_path  = public_path();
		$this->assets_path  = $this->public_path
			. '/'
			. trim(\Config::get('casset::assets_dir', 'assets'), '/');
		$this->cache_path   = $this->public_path
			. '/'
			. trim(\Config::get('casset::cache_dir', 'assets/cache'), '/');
	}
	
	/**
	 * Add an asset (of any type) to the container.
	 * 
	 * Accepts a source relative to the configured 'assets_dir'.
	 *   eg: 'js/jquery.js'
	 * 
	 * Also accepts a source relative to a package.
	 *   eg: 'package::js/file.js'
	 *
	 * @param string $source     Relative path to file.
	 * @param array  $attributes Attribuets array.
	 * 
	 * @return Container
	 */
	public function add($source, array $attributes = array())
	{
		$ext = pathinfo($source, PATHINFO_EXTENSION);
		
		$this->assets[] = compact('ext', 'source', 'attributes');
		
		return $this;
	}
	
	/**
	 * Get the HTML links to all of the registered CSS assets.
	 *
	 * @return string
	 */
	public function styles()
	{
		$assets = array();
		
		foreach ($this->assets as $asset) {
			if ('css' !== $asset['ext'] && 'less' !== $asset['ext']) {
				continue;
			}
			
			$assets[] = $this->process($asset);
		}
		
		if (empty($assets)) {
			return '';
		}
		
		if ($this->combine) {
			$assets = $this->combine($assets, 'style');
		}
		
		$links = array();
		foreach ($assets as $asset) {
			$links[] = \HTML::style($asset['url'], $asset['attributes']);
		}
		
		return implode('', $links);
	}
	
	/**
	 * Get the HTML links to all of the registered JavaScript assets.
	 *
	 * @return string
	 */
	public function scripts()
	{
		$assets = array();
		
		foreach ($this->assets as $asset) {
			if ('js' !== $asset['ext']) {
				continue;
			}
			
			$assets[] = $this->process($asset);
		}
		
		if (empty($assets)) {
			return '';
		}
		
		if ($this->combine) {
			$assets = $this->combine($assets, 'script');
		}
		
		$links = array();
		foreach ($assets as $asset) {
			$links[] = \HTML::script($asset['url'], $asset['attributes']);
		}
		
		return implode('', $links);
	}
	
	/**
	 * Get the full path to the given asset source. Will try to load from a
	 * package/workbench if prefixed with: "{package_name}::".
	 *
	 * @param string $source Asset source.
	 * 
	 * @return string
	 */
	public function path($source)
	{
		if (false === stristr($source, '::')) {
			return $this->assets_path . '/' . ltrim($source, '/');
		}
		
		$source_parts = explode('::', $source);
		$package_name = current($source_parts);
		$finder       = \Symfony\Component\Finder\Finder::create();
		
		// Try to find package path.
		$vendor = base_path() . '/vendor';
		foreach ($finder->directories()->in($vendor)->name($package_name)->depth('< 3') as $package) {
			return $package->getPathname() . '/public/' . ltrim(end($source_parts), '/');
		}
		
		// Try to find workbench path.
		$workbench = base_path() . '/workbench';
		foreach ($finder->directories()->in($workbench)->name($package_name)->depth('< 3') as $package) {
			return $package->getPathname() . '/public/' . ltrim(end($source_parts), '/');
		}
		
		return $source;
	}
	
	/**
	 * Process the given asset.
	 * Make public, if needed.
	 * Compile, if needed (lessphp, etc...).
	 * 
	 * Returns a valid asset.
	 *
	 * @param array $asset Asset array.
	 * 
	 * @return array [url, attributes]
	 */
	public function process(array $asset)
	{
		$path          = $this->path($asset['source']);
		$is_public     = (bool) stristr($path, $this->public_path);
		$compiled_exts = array('less');
		
		// This file requires processing.
		if (!$is_public || in_array($asset['ext'], $compiled_exts)) {
			$cache_path = $this->cache_path . '/'
				. str_replace(array('/', '::'), '-', $asset['source']);
			$cache_path .= ('less' === $asset['ext']) ? '.css' : '';
			
			$compile = false;
			if (!\File::exists($cache_path)) {
				$compile = true;
			}
			else if (\File::lastModified($cache_path) < \File::lastModified($path)) {
				$compile = true;
				
				// Check md5 to see if content is the same.
				if ($f = fopen($cache_path, 'r')) {
					$line = (string) fgets($f);
					fclose($f);
					
					if (false !== strstr($line, '*/')) {
						$md5 = trim(str_replace(array('/*', '*/'), '', $line));
						
						if (32 == strlen($md5)) {
							$file_md5 = md5_file($path);
							
							// Skip compiling and touch existing file.
							if ($file_md5 === $md5) {
								$compile = false;
								touch($cache_path);
							}
						}
					}
				}
			}
			
			if ($compile) {
				if (\File::exists($path)) {
					$content = $this->compile($path);
					\File::put($cache_path, $content);
				}
			}
			
			$path = $cache_path;
		}
		
		$asset['path'] = $path;
		$asset['url']  = str_ireplace($this->public_path, '', $path);
		
		return $asset;
	}
	
	/**
	 * Compile and return the content for the given asset according to it's
	 * extension.
	 *
	 * @param string $path Asset path.
	 * 
	 * @return string
	 */
	public function compile($path)
	{
		$content = \File::get($path);
		
		switch (pathinfo($path, PATHINFO_EXTENSION)) {
			case 'less':
				$less = new \lessc;
				$less->addImportDir(dirname($path));
				$content = '/*' . md5($content) . "*/\n" . $less->compile($content);
				
				break;
		}
		
		return $content;
	}
	
	/**
	 * Combine the given array of assets. Minify, if enabled.
	 * Returns new array containing one asset.
	 *
	 * @param array  $assets Array of assets.
	 * @param string $type   File type (script, style).
	 * 
	 * @return array
	 */
	public function combine(array $assets, $type)
	{
		$file = $this->cache_path . '/casset-' . $this->name;
		$file .= ('script' === $type) ? '.js' : '.css';
		
		$lastmod = 0;
		foreach ($assets as $asset) {
			$mod = \File::lastModified($asset['path']);
			if ($mod > $lastmod) {
				$lastmod = $mod;
			}
		}
		
		$combine = false;
		if (!\File::exists($file)) {
			$combine = true;
		}
		else if (\File::lastModified($file) < $lastmod) {
			$combine = true;
		}
		
		if ($combine) {
			$content = '';
			
			foreach ($assets as $asset) {
				if (!\File::exists($asset['path'])) {
					continue;
				}
				
				$c = \File::get($asset['path']);
				
				if ($this->minify
					&& !(stripos($asset['source'], '.min')
						|| stripos($asset['source'], '-min')
					)
				) {
					switch ($type) {
						case 'style':
							$c = Compressors\Css::process($c);
							break;
							
						case 'script':
							$c = Compressors\Js::minify($c);
							break;
					}
				}
				
				$content .= "/* {$asset['source']} */\n$c\n\n";
			}
			
			\File::put($file, $content);
		}
		
		return array(array(
			'path'       => $file,
			'attributes' => array(),
			'url'        => str_ireplace($this->public_path, '', $file) . "?ts=$lastmod",
		));
	}
}
