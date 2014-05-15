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
	 * URL of CDN to use for assets.
	 *
	 * @var string
	 */
	public $cdn;
	
	/**
	 * All of the registered assets.
	 *
	 * @var array
	 */
	public $assets = array();
	
	/**
	 * Array of global dependencies which all assets are checked against.
	 *
	 * @var array
	 */
	public $dependencies = array();
	
	/**
	 * Local cache of all assets and whether or not they needed processing.
	 *
	 * @var array
	 */
	protected static $needs_processing = array();
	
	/**
	 * Local cache of all assets that have already been processed.
	 *
	 * @var array
	 */
	protected static $processed = array();
	
	/**
	 * Local cache of paths.
	 *
	 * @var array
	 */
	protected static $cached_paths = array();
	
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
		$this->combine      = \Config::get('laravel-casset::combine', true);
		$this->minify       = \Config::get('laravel-casset::minify', true);
		$this->cdn          = rtrim(\Config::get('laravel-casset::cdn', ''), '/');
		$this->public_path  = public_path();
		$this->assets_path  = $this->public_path
			. '/'
			. trim(\Config::get('laravel-casset::assets_dir', 'assets'), '/');
		$this->cache_path   = $this->public_path
			. '/'
			. trim(\Config::get('laravel-casset::cache_dir', 'assets/cache'), '/');
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
	 * @param string $source       Relative path to file.
	 * @param array  $attributes   Attribuets array.
	 * @param array  $dependencies Dependencies array.
	 * 
	 * @return Container
	 */
	public function add($source, array $attributes = array(), array $dependencies = array())
	{
		$ext = pathinfo($source, PATHINFO_EXTENSION);
		
		$this->assets[] = compact('ext', 'source', 'attributes', 'dependencies');
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
			$url = $this->cdn ? $this->cdn . $asset['url'] : $asset['url'];
			$links[] = \HTML::style($url, $asset['attributes']);
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

        foreach ($this->dependencies['js'] as $dependency) {
            $asset = $this->findDependency($dependency);
            if ($asset !== false && is_array($asset)) {
                $assets[$asset['source']] = $this->process($asset);
            }
        }

		foreach ($this->assets as $asset) {
			if ('js' !== $asset['ext']) {
				continue;
			}

            if (isset($assets[$asset['source']])) {
                continue;
            }

            if ($asset['dependencies']) {
                //-- TODO if there is a non-global dependency resolve here.
            }
			
            $assets[$asset['source']] = $this->process($asset);
		}

        $assets = array_values($assets);
		
		if (empty($assets)) {
			return '';
		}
		
		if ($this->combine) {
			$assets = $this->combine($assets, 'script');
		}
		
		$links = array();
		foreach ($assets as $asset) {
			$url = $this->cdn ? $this->cdn . $asset['url'] : $asset['url'];
			$links[] = \HTML::script($url, $asset['attributes']);
		}
		
		return implode('', $links);
	}
	
	/**
	 * Add an image asset to the container.
	 * 
	 * @param string $source
	 * @param string $alt
	 * @param array  $attributes
	 * 
	 * @return string
	 */
	public function image($source, $alt = null, $attributes = array())
	{
		$url = $source;
		
		if (false === strstr($source, '://') && '//' !== substr($source, 0, 2)) {
			$url = $this->cdn($source);
		}
		
		return \HTML::image($url, $alt, $attributes);
	}
	
	/**
	 * Get the URL to the CDN for an asset.
	 *
	 * @param string $source
	 *
	 * @return string
	 */
	public function cdn($source)
	{
		$url = str_ireplace($this->public_path, '', $this->assets_path . '/' . ltrim($source, '/'));
		$url = $this->cdn ? $this->cdn . $url : $url;
		return $url;
	}
	
	/**
	 * Add a global dependency.
	 *
	 * @param string $source Relative path to file.
	 * 
	 * @return Container
	 */
	public function dependency($source)
	{
		$this->dependencies[pathinfo($source, PATHINFO_EXTENSION)][] = $source;
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
		
		// Is this relative to the public dir?
		$path = '/public/' . ltrim(end($source_parts), '/');
		if ('/' === substr(end($source_parts), 0, 1)) {
			$path = end($source_parts);
		}
		
		// Check local cache first.
		if (array_key_exists($package_name, static::$cached_paths)) {
			return static::$cached_paths[$package_name] . $path;
		}
		
		$finder = \Symfony\Component\Finder\Finder::create();
		
		// Try to find package path.
		$vendor = base_path() . '/vendor';
		foreach ($finder->directories()->in($vendor)->name($package_name)->depth('< 3') as $package) {
			static::$cached_paths[$package_name] = $package->getPathname();
			return static::$cached_paths[$package_name] . $path;
		}
		
		// Try to find workbench path.
		$workbench = base_path() . '/workbench';
		foreach ($finder->directories()->in($workbench)->name($package_name)->depth('< 3') as $package) {
			static::$cached_paths[$package_name] = $package->getPathname();
			return static::$cached_paths[$package_name] . $path;
		}
		
		return $source;
	}
	
	/**
	 * Return the public path to the given asset.
	 * The if the file is not in the public directory,
	 * or if it needs to be compiled (less, etc...), then the
	 * public cache path is returned.
	 *
	 * @param array $asset Asset array.
	 * 
	 * @return string
	 */
	public function publicPath(array $asset)
	{
		$path          = $this->path($asset['source']);
		$is_public     = (bool) stristr($path, $this->public_path);
		$compiled_exts = array('less');
		
		if ($is_public && !in_array($asset['ext'], $compiled_exts)) {
			return $path;
		}
		
		$cache_path = $this->cache_path
			. '/'
			. str_replace(array('/', '::'), '-', $asset['source']);
		
		$cache_path .= ('less' === $asset['ext']) ? '.css' : '';
		
		return $cache_path;
	}
	
	/**
	 * Returns whether or not a file needs to be processed.
	 *
	 * @param array $asset Asset array.
	 * 
	 * @return boolean
	 */
	public function needsProcessing(array $asset)
	{
		if (isset(static::$needs_processing[$asset['source']])) {
			return static::$needs_processing[$asset['source']];
		}
		
		$path          = $this->path($asset['source']);
		$is_public     = (bool) stristr($path, $this->public_path);
		$compiled_exts = array('less');
		
		// Any dependencies that need processing?
		$dependencies = isset($asset['dependencies']) ? $asset['dependencies'] : array();
		$dependencies = array_unique(array_merge(array_get($this->dependencies, $asset['ext'], array()), $dependencies));
		if (!empty($dependencies)) {
			foreach ($dependencies as $dep_source) {
				if (!empty(static::$needs_processing[$dep_source])) {
					return static::$needs_processing[$asset['source']] = true;
				}
			}
		}
		
		// This file does not require processing.
		if ($is_public && !in_array($asset['ext'], $compiled_exts)) {
			return static::$needs_processing[$asset['source']] = false;
		}
		
		$cache_path = $this->publicPath($asset);
		
		// Does file exist?
		if (!\File::exists($cache_path)) {
			return static::$needs_processing[$asset['source']] = true;
		}
		
		// Is cached file newer than the original?
		if (\File::lastModified($cache_path) >= \File::lastModified($path)) {
			return static::$needs_processing[$asset['source']] = false;
		}
		
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
						touch($cache_path);
						return false;
					}
				}
			}
		}
		
		return static::$needs_processing[$asset['source']] = true;
	}
	
	/**
	 * Process the given asset.
	 * Make public, if needed.
	 * Compile, if needed (less, etc...).
	 * 
	 * Returns a valid asset.
	 *
	 * @param array $asset Asset array.
	 * 
	 * @return array [url, attributes]
	 */
	public function process(array $asset)
	{
		// Any dependencies that need processing?
		$dependencies = isset($asset['dependencies']) ? $asset['dependencies'] : array();
		$dependencies = array_unique(array_merge(array_get($this->dependencies, $asset['ext'], array()), $dependencies));
		if (!empty($dependencies)) {
			foreach ($dependencies as $dep_source) {
				if ($asset['source'] == $dep_source) {
					continue;
				}
				
				$this->process(array(
					'source' => $dep_source,
					'ext'    => pathinfo($dep_source, PATHINFO_EXTENSION),
				));
			}
		}
		
		$path        = $this->path($asset['source']);
		$public_path = $this->publicPath($asset);
		
		if (empty(static::$processed[$asset['source']]) && $this->needsProcessing($asset)) {
			if (\File::exists($path)) {
				\File::put($public_path, $this->compile($path));
			}
		}
		
		$asset['path'] = $public_path;
		$asset['url']  = str_ireplace($this->public_path, '', $public_path);
		
		static::$processed[$asset['source']] = true;
		
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
		switch (pathinfo($path, PATHINFO_EXTENSION)) {
			case 'less':
				$less = new \Less_Parser;
				$content = '/*' . md5(\File::get($path)) . "*/\n" . $less->parseFile($path)->getCss();
				
				break;
				
			default:
				$content = \File::get($path);
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
		$paths = array();
		$lastmod = 0;
		foreach ($assets as $asset) {
			$paths[] = $asset['path'];
			$mod = \File::lastModified($asset['path']);
			if ($mod > $lastmod) {
				$lastmod = $mod;
			}
		}
		
		$file = $this->cache_path . '/casset-' . md5(implode(',', $paths) . $lastmod) . '-' . $this->name;
		$file .= ('script' === $type) ? '.js' : '.css';
		
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
			'url'        => str_ireplace($this->public_path, '', $file),
		));
	}

    /**
     * Match a dependency with an asset
     * @param $source The filename + relative path to match inside the asset's source
     * @return bool|array
     */
    protected function findDependency($source) {
        foreach ($this->assets as $i => $asset) {
            if ($source === $asset['source']) {
                return $asset;
                break;
            }
        }

        return false;
    }
}
